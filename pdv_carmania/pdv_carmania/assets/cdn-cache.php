<?php
declare(strict_types=1);

$assets = [
    'bootstrap-css' => [
        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'content_type' => 'text/css; charset=utf-8',
        'filename' => 'bootstrap.min.css',
    ],
    'bootstrap-js' => [
        'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        'content_type' => 'application/javascript; charset=utf-8',
        'filename' => 'bootstrap.bundle.min.js',
    ],
    'html2canvas-js' => [
        'url' => 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js',
        'content_type' => 'application/javascript; charset=utf-8',
        'filename' => 'html2canvas.min.js',
    ],
];

$assetKey = isset($_GET['asset']) ? trim((string) $_GET['asset']) : '';

if ($assetKey === '' || !isset($assets[$assetKey])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Asset não encontrado.';
    exit;
}

$asset = $assets[$assetKey];
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$cacheFile = $cacheDir . '/' . $assetKey . '.bin';
$metaFile = $cacheDir . '/' . $assetKey . '.json';

$freshTtl = 86400 * 7;      // 7 dias considerado fresco
$maxStale = 86400 * 30;     // serve conteúdo com até 30 dias
$now = time();
$hasCache = file_exists($cacheFile);
$cacheMTime = $hasCache ? (filemtime($cacheFile) ?: 0) : 0;
$meta = loadMeta($metaFile);

if ($hasCache && ($now - $cacheMTime) <= $freshTtl) {
    serveFile($cacheFile, $asset['content_type'], 'fresh');
}

if (!empty($meta['next_retry_at']) && (int)$meta['next_retry_at'] > $now) {
    if ($hasCache) {
        serveFile($cacheFile, $asset['content_type'], 'stale');
    }

    $retryAfter = max(5, min(300, (int)$meta['next_retry_at'] - $now));
    http_response_code(503);
    header('Retry-After: ' . $retryAfter);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Requisição temporariamente limitada. Tente novamente mais tarde.';
    exit;
}

$result = fetchRemote($asset['url']);

if ($result['ok']) {
    if (file_put_contents($cacheFile, $result['body']) === false) {
        logFailure($assetKey, 'Falha ao gravar arquivo em cache.');
    } else {
        touch($cacheFile, $now);
    }

    $meta['fetched_at'] = $now;
    $meta['url'] = $asset['url'];
    $meta['next_retry_at'] = null;
    $meta['last_http_code'] = $result['http_code'];
    $meta['last_error'] = null;
    saveMeta($metaFile, $meta);

    serveFile($cacheFile, $asset['content_type'], 'refreshed');
}

if ($result['retry_after'] !== null) {
    $espera = max(5, min(600, $result['retry_after']));
    $meta['next_retry_at'] = $now + $espera;
    $meta['last_error'] = 'HTTP ' . $result['http_code'];
    saveMeta($metaFile, $meta);
}

if ($hasCache && ($now - $cacheMTime) <= $maxStale) {
    serveFile($cacheFile, $asset['content_type'], 'stale');
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo 'Não foi possível obter o recurso solicitado.';
exit;

/**
 * @return array{ok:bool, body:string|null, http_code:int|null, retry_after:int|null, error:?string}
 */
function fetchRemote(string $url, int $maxAttempts = 3): array
{
    $attempt = 0;
    $ultimaFalha = null;

    while ($attempt < $maxAttempts) {
        $attempt++;

        $ch = curl_init($url);
        if ($ch === false) {
            $ultimaFalha = 'curl_init falhou';
            break;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'PDV-Carmania-CDN-Cache/1.0 (+https://carmania.example)',
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
            ],
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $ultimaFalha = curl_error($ch) ?: 'Falha desconhecida ao contactar CDN';
            curl_close($ch);
            sleep(min(5 * $attempt, 15));
            continue;
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headersRaw = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($ch);

        $headers = normalizarHeaders($headersRaw);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'ok' => true,
                'body' => $body,
                'http_code' => $httpCode,
                'retry_after' => null,
                'error' => null,
            ];
        }

        if (in_array($httpCode, [429, 503], true)) {
            $retry = calcularRetryAfter($headers, $attempt);
            return [
                'ok' => false,
                'body' => null,
                'http_code' => $httpCode,
                'retry_after' => $retry,
                'error' => 'HTTP ' . $httpCode,
            ];
        }

        if ($httpCode >= 500 && $httpCode < 600) {
            sleep(min(5 * $attempt, 20));
            continue;
        }

        return [
            'ok' => false,
            'body' => null,
            'http_code' => $httpCode,
            'retry_after' => null,
            'error' => 'HTTP ' . $httpCode,
        ];
    }

    return [
        'ok' => false,
        'body' => null,
        'http_code' => null,
        'retry_after' => null,
        'error' => $ultimaFalha,
    ];
}

function serveFile(string $file, string $contentType, string $cacheStatus): void
{
    if (!is_file($file)) {
        return;
    }

    $length = filesize($file);
    $mtime = filemtime($file) ?: time();

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $length);
    header('Cache-Control: public, max-age=86400');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: ' . $cacheStatus);
    if ($cacheStatus === 'stale') {
        header('Warning: 110 - "Response is stale"');
    }

    readfile($file);
    exit;
}

/**
 * @return array<string, mixed>
 */
function loadMeta(string $metaFile): array
{
    if (!is_file($metaFile)) {
        return [];
    }

    $conteudo = file_get_contents($metaFile);
    if ($conteudo === false) {
        return [];
    }

    $json = json_decode($conteudo, true);
    return is_array($json) ? $json : [];
}

function saveMeta(string $metaFile, array $meta): void
{
    file_put_contents(
        $metaFile,
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * @return array<string, string>
 */
function normalizarHeaders(string $headersRaw): array
{
    $headers = [];
    $blocos = preg_split("/\r\n\r\n/", trim($headersRaw));
    if (!$blocos) {
        return $headers;
    }

    $ultimo = array_pop($blocos);
    if (!$ultimo) {
        return $headers;
    }

    $linhas = explode("\r\n", $ultimo);
    foreach ($linhas as $linha) {
        if (strpos($linha, ':') === false) {
            continue;
        }
        [$nome, $valor] = explode(':', $linha, 2);
        $headers[strtolower(trim($nome))] = trim($valor);
    }

    return $headers;
}

function calcularRetryAfter(array $headers, int $tentativa): int
{
    if (isset($headers['retry-after'])) {
        $valor = trim($headers['retry-after']);
        if (ctype_digit($valor)) {
            return max(1, min(600, (int) $valor));
        }

        $timestamp = strtotime($valor);
        if ($timestamp !== false) {
            $espera = $timestamp - time();
            if ($espera > 0) {
                return max(1, min(600, $espera));
            }
        }
    }

    return max(5, min(60, $tentativa * 5));
}

function logFailure(string $assetKey, string $mensagem): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $linha = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $assetKey, $mensagem);
    file_put_contents($logDir . '/cdn-cache.log', $linha . PHP_EOL, FILE_APPEND);
}
