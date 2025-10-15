<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/token-helper.php';

$cacheFile = __DIR__ . '/../cache/vendedores-cache.json';
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    echo file_get_contents($cacheFile);
    exit();
}

$accessToken = getAccessToken();
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao obter token do Bling"]);
    exit();
}

$blingBase = 'https://www.bling.com.br/Api/v3';

function consultarPaginaVendedores(int $pagina, int $limite, string $token, string $baseUrl): array
{
    $query = http_build_query([
        'pagina' => $pagina,
        'limite' => $limite,
    ], '', '&', PHP_QUERY_RFC3986);

    $url = "$baseUrl/vendedores?$query";

    $tentativas = 0;
    $tentativasMaximas = 5;
    $atraso = 1;

    while ($tentativas < $tentativasMaximas) {
        $tentativas++;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
        ]);

        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erroCurl = curl_error($ch);
            curl_close($ch);

            return [
                'erro' => true,
                'status' => 500,
                'mensagem' => 'Falha na comunicação com o Bling',
                'detalhes' => $erroCurl,
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamanhoCabecalho = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $cabecalhoBruto = substr($resposta, 0, $tamanhoCabecalho);
        $corpo = substr($resposta, $tamanhoCabecalho);
        curl_close($ch);

        if ($httpCode === 429 || ($httpCode >= 500 && $httpCode < 600)) {
            $retryAfter = null;
            $linhasCabecalho = explode("\r\n", $cabecalhoBruto);
            foreach ($linhasCabecalho as $linha) {
                if (stripos($linha, 'Retry-After:') === 0) {
                    $valor = trim(substr($linha, strlen('Retry-After:')));
                    if (is_numeric($valor)) {
                        $retryAfter = (int) $valor;
                    }
                    break;
                }
            }

            $espera = $retryAfter !== null ? max(1, $retryAfter) : $atraso;
            sleep($espera);
            $atraso = min($atraso * 2, 30);
            continue;
        }

        if ($httpCode !== 200) {
            return [
                'erro' => true,
                'status' => $httpCode,
                'mensagem' => 'Erro ao consultar Bling',
                'detalhes' => $corpo,
            ];
        }

        $dados = json_decode($corpo, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'erro' => true,
                'status' => 500,
                'mensagem' => 'Resposta inválida do Bling',
            ];
        }

        return [
            'erro' => false,
            'dados' => $dados,
        ];
    }

    return [
        'erro' => true,
        'status' => 429,
        'mensagem' => 'Limite de tentativas ao consultar o Bling excedido',
    ];
}

$pagina = 1;
$limite = 100;
$todosVendedores = [];
$ultimaPaginaDados = null;

while (true) {
    $resultado = consultarPaginaVendedores($pagina, $limite, $accessToken, $blingBase);

    if ($resultado['erro']) {
        http_response_code($resultado['status']);
        echo json_encode([
            'erro' => $resultado['mensagem'],
            'detalhes' => $resultado['detalhes'] ?? null,
        ]);
        exit();
    }

    $dados = $resultado['dados'];
    $ultimaPaginaDados = $dados;

    $registros = $dados['data'] ?? [];
    if ($registros && array_keys($registros) !== range(0, count($registros) - 1)) {
        $registros = [$registros];
    }

    if (!is_array($registros)) {
        $registros = [];
    }

    $todosVendedores = array_merge($todosVendedores, $registros);

    $pageInfo = $dados['page'] ?? null;
    if (!$pageInfo || !is_array($pageInfo)) {
        break;
    }

    $paginaAtual = isset($pageInfo['number']) ? (int) $pageInfo['number'] : $pagina;
    $totalPaginas = isset($pageInfo['totalPages']) ? (int) $pageInfo['totalPages'] : null;
    $temProxima = isset($pageInfo['hasNext']) ? (bool) $pageInfo['hasNext'] : null;

    if ($totalPaginas !== null && $paginaAtual >= $totalPaginas) {
        break;
    }

    if ($temProxima === false) {
        break;
    }

    if (count($registros) < $limite) {
        break;
    }

    $pagina++;
}

$payload = [
    'data' => $todosVendedores,
];

if ($ultimaPaginaDados && isset($ultimaPaginaDados['page'])) {
    $payload['page'] = $ultimaPaginaDados['page'];
    $payload['page']['totalRecords'] = count($todosVendedores);
}

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao preparar cache de vendedores"]);
    exit();
}

file_put_contents($cacheFile, $json, LOCK_EX);

echo $json;
