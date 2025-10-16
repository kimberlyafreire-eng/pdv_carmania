<?php
require_once __DIR__ . '/lib/token-helper.php';

set_time_limit(0);

// CONFIGURAÇÕES
$tokenFile   = __DIR__ . '/token.json';
$dbFile      = __DIR__ . '/../db/produtos.db';
$imagensPath = __DIR__ . '/../imagens';
$logDir      = __DIR__ . '/../logs';
$logFile     = $logDir . '/atualizar.log';
$requestDelaySeconds = 3;
$progressFile = __DIR__ . '/../cache/atualizar-produtos-progress.json';

$isCli = PHP_SAPI === 'cli';
$logEntries = [];

function flushOutput(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><title>Atualização de Produtos</title>';
    echo '<style>body{font-family:Arial,Helvetica,sans-serif;background:#111;color:#f2f2f2;margin:0;padding:20px;}';
    echo '.container{max-width:900px;margin:0 auto;background:#1b1b1b;border-radius:12px;padding:24px;box-shadow:0 0 25px rgba(0,0,0,0.45);}';
    echo 'h1{margin-top:0;font-size:24px;} .log{margin-top:20px;max-height:70vh;overflow-y:auto;background:#0c0c0c;border-radius:8px;padding:16px;}';
    echo '.log-entry{padding:6px 8px;border-left:4px solid #444;margin-bottom:6px;line-height:1.4;background:rgba(255,255,255,0.03);}';
    echo '.success{border-color:#3fb950;} .error{border-color:#f85149;} .warn{border-color:#d29922;} .info{border-color:#58a6ff;}';
    echo '</style></head><body><div class="container"><h1>Atualização de produtos</h1>';
    echo '<p>Iniciando processo, aguarde. Cada etapa possui um atraso mínimo de ' . $requestDelaySeconds . 's para evitar limites da API.</p>';
    echo '<div class="log" id="log">';
    flushOutput();
}

// GARANTIR PASTAS
if (!is_dir(dirname($dbFile))) mkdir(dirname($dbFile), 0777, true);
if (!is_dir($imagensPath)) mkdir($imagensPath, 0777, true);
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
if (!is_dir(dirname($progressFile))) mkdir(dirname($progressFile), 0777, true);

function carregarProgresso(string $arquivo): ?string
{
    if (!file_exists($arquivo)) {
        return null;
    }

    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return null;
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return null;
    }

    $ultimoId = $dados['last_processed_id'] ?? null;
    return is_string($ultimoId) && $ultimoId !== '' ? $ultimoId : null;
}

function salvarProgresso(string $arquivo, ?string $produtoId): void
{
    if ($produtoId === null) {
        if (file_exists($arquivo)) {
            @unlink($arquivo);
        }
        return;
    }

    $dados = [
        'last_processed_id' => $produtoId,
        'updated_at' => date('c')
    ];

    file_put_contents($arquivo, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function logAtualizacao($msg, string $nivel = 'info') {
    global $logFile, $isCli, $logEntries;

    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg;
    $logEntries[] = [$line, $nivel];
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);

    if ($isCli) {
        echo $msg . "\n";
    } else {
        $classMap = [
            'success' => 'success',
            'error'   => 'error',
            'warn'    => 'warn',
            'info'    => 'info'
        ];
        $classe = $classMap[$nivel] ?? 'info';
        $htmlMsg = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<div class="log-entry ' . $classe . '">' . $htmlMsg . '</div>';
        flushOutput();
    }
}

function downloadImagem(string $url, string $destino): bool {
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    $fp = fopen($destino, 'w');
    if ($fp === false) {
        curl_close($ch);
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_FAILONERROR     => true,
        CURLOPT_USERAGENT       => 'PDV Carmania/1.0'
    ]);

    $exec = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    fclose($fp);
    curl_close($ch);

    if ($exec === false || $httpCode >= 400) {
        if (file_exists($destino)) {
            unlink($destino);
        }
        return false;
    }

    return true;
}

logAtualizacao("🔁 Iniciando atualização de produtos...");

$resumeFromId = carregarProgresso($progressFile);
$aguardandoRetomar = is_string($resumeFromId) && $resumeFromId !== '';
if ($aguardandoRetomar) {
    logAtualizacao("⏩ Retomada configurada para continuar após o produto {$resumeFromId}.", 'info');
}

if (!file_exists($tokenFile)) {
    logAtualizacao("❌ Token não encontrado. Execute o auth.php manualmente.", 'error');
    exit;
}

$access_token = getAccessToken();

if (!$access_token) {
    logAtualizacao("⚠️ Token indisponível. Tentando renovar...", 'warn');
    if (!refreshAccessToken()) {
        logAtualizacao("❌ Não foi possível renovar o token.", 'error');
        exit;
    }
    $access_token = getAccessToken();
    if (!$access_token) {
        logAtualizacao("❌ Access token ainda indisponível após renovação.", 'error');
        exit;
    }
}

logAtualizacao("✅ Token carregado.", 'success');

$blingBase = 'https://bling.com.br/Api/v3';

function extrairHeaders(string $headersRaw): array
{
    $headers = [];
    foreach (preg_split('/\r?\n/', $headersRaw) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$key, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($key))] = trim($value);
    }

    return $headers;
}

function executarRequisicaoBling(string $url, string $contexto, int $tentativa = 0)
{
    global $access_token, $requestDelaySeconds;

    if ($requestDelaySeconds > 0) {
        sleep($requestDelaySeconds);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45
    ]);

    $rawResponse = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        $headersRaw = '';
        $body = '';
        logAtualizacao("⚠️ Erro de conexão ao {$contexto}: {$curlError}", 'warn');
    } else {
        $headersRaw = substr($rawResponse, 0, $headerSize);
        $body = substr($rawResponse, $headerSize);
    }
    $headers = extrairHeaders($headersRaw);

    if ($httpcode === 401 && $tentativa < 2) {
        logAtualizacao("⚠️ Token expirado ao {$contexto}. Renovando...", 'warn');
        if (refreshAccessToken()) {
            $novoToken = getAccessToken();
            if ($novoToken) {
                $access_token = $novoToken;
                return executarRequisicaoBling($url, $contexto, $tentativa + 1);
            }
        }
        logAtualizacao("❌ Falha ao renovar token durante {$contexto}.", 'error');
    }

    if ($httpcode === 429 && $tentativa < 5) {
        $retryAfter = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 0;
        if ($retryAfter <= 0) {
            $retryAfter = min(60, (int) pow(2, $tentativa + 1));
        }
        logAtualizacao("⏳ Limite de requisições atingido ao {$contexto}. Aguardando {$retryAfter}s para tentar novamente...", 'warn');
        sleep($retryAfter);
        return executarRequisicaoBling($url, $contexto, $tentativa + 1);
    }

    if ($httpcode >= 500 && $httpcode < 600 && $tentativa < 4) {
        $espera = max($requestDelaySeconds, min(60, ($tentativa + 1) * $requestDelaySeconds));
        logAtualizacao("⚠️ Erro {$httpcode} ao {$contexto}. Repetindo após {$espera}s...", 'warn');
        sleep($espera);
        return executarRequisicaoBling($url, $contexto, $tentativa + 1);
    }

    return [$httpcode, $body];
}

function buscarPaginaProdutos(int $pagina)
{
    global $blingBase;

    $url = rtrim($blingBase, '/') . "/produtos?pagina={$pagina}&limite=100";

    return executarRequisicaoBling($url, "buscar página {$pagina}");
}

// CONECTAR OU CRIAR BANCO DE DADOS
$db = new SQLite3($dbFile);
$db->exec("CREATE TABLE IF NOT EXISTS produtos (
    id TEXT PRIMARY KEY,
    codigo TEXT,
    nome TEXT,
    preco REAL,
    imagem_url TEXT,
    imagem_local TEXT,
    gtin TEXT
)");

$colunas = [];
$colunasQuery = $db->query('PRAGMA table_info(produtos)');
while ($colunasQuery && ($row = $colunasQuery->fetchArray(SQLITE3_ASSOC))) {
    $colunas[] = $row['name'];
}

if (!in_array('gtin', $colunas, true)) {
    $db->exec('ALTER TABLE produtos ADD COLUMN gtin TEXT');
}

function buscarDetalheProduto(string $produtoId)
{
    global $blingBase;

    $url = rtrim($blingBase, '/') . "/produtos/{$produtoId}";

    return executarRequisicaoBling($url, "buscar detalhes do produto {$produtoId}");
}

function extrairGtinProduto(array $dadosProduto): ?string
{
    $candidatos = [
        $dadosProduto['gtin'] ?? null,
        $dadosProduto['gtinEmbalagem'] ?? null,
        $dadosProduto['codigoBarras'] ?? null
    ];

    foreach ($candidatos as $valor) {
        $valor = is_string($valor) ? trim($valor) : $valor;
        if (!empty($valor)) {
            return $valor;
        }
    }

    if (!empty($dadosProduto['variacoes']) && is_array($dadosProduto['variacoes'])) {
        foreach ($dadosProduto['variacoes'] as $variacao) {
            if (!is_array($variacao)) {
                continue;
            }

            $candidatosVariacao = [
                $variacao['gtin'] ?? null,
                $variacao['gtinEmbalagem'] ?? null,
                $variacao['codigoBarras'] ?? null,
                $variacao['codigo'] ?? null
            ];

            foreach ($candidatosVariacao as $valor) {
                $valor = is_string($valor) ? trim($valor) : $valor;
                if (!empty($valor)) {
                    return $valor;
                }
            }
        }
    }

    return null;
}

$pagina = 1;
$totalInseridos = 0;
$totalGtinsAtualizados = 0;
$totalDetalhesFalhos = 0;
$idsEncontrados = [];

do {
    [$httpcode, $response] = buscarPaginaProdutos($pagina);

    if ($httpcode !== 200) {
        logAtualizacao("❌ Erro ao buscar página $pagina: HTTP $httpcode", 'error');
        $retryWait = max(5, $requestDelaySeconds);
        logAtualizacao("⏳ Tentando novamente após {$retryWait}s...", 'warn');
        sleep($retryWait);
        continue;
    }

    $dados = json_decode($response, true);
    if ($dados === null && json_last_error() !== JSON_ERROR_NONE) {
        $erroJson = json_last_error_msg();
        logAtualizacao("❌ Erro ao interpretar resposta da página {$pagina}: {$erroJson}", 'error');
        $retryWait = max(5, $requestDelaySeconds);
        logAtualizacao("⏳ Tentando novamente após {$retryWait}s...", 'warn');
        sleep($retryWait);
        continue;
    }
    $produtos = $dados['data'] ?? [];

    logAtualizacao("📦 Página $pagina - " . count($produtos) . " produtos", 'info');

    if (empty($produtos)) {
        if ($pagina === 1) {
            logAtualizacao('ℹ️ Nenhum produto foi retornado pela API.', 'info');
        } else {
            logAtualizacao("ℹ️ Nenhum produto adicional a partir da página {$pagina}.", 'info');
        }
        break;
    }

    foreach ($produtos as $p) {
        $id = (string) ($p['id'] ?? '');
        $codigo = isset($p['codigo']) ? (string) $p['codigo'] : '';
        $nome = isset($p['nome']) ? (string) $p['nome'] : '';
        if ($id === '') {
            logAtualizacao('⚠️ Produto retornado sem ID válido, ignorando registro.', 'warn');
            continue;
        }

        $idsEncontrados[$id] = true;

        if ($aguardandoRetomar) {
            if ($id === $resumeFromId) {
                $aguardandoRetomar = false;
                logAtualizacao("▶️ Produto {$id} foi o último processado. Continuando a partir do próximo.", 'info');
                continue;
            }

            continue;
        }

        $preco = isset($p['preco']) ? (float) $p['preco'] : 0.00;
        $urlImg = $p['imagemURL'] ?? ($p['imagem'] ?? null);
        $localImg = null;

        if ($urlImg && filter_var($urlImg, FILTER_VALIDATE_URL)) {
            $path = parse_url($urlImg, PHP_URL_PATH) ?? '';
            $nomeArquivo = basename($path) ?: ($id . '.jpg');
            $ext = pathinfo($nomeArquivo, PATHINFO_EXTENSION);

            if (!$ext) {
                $nomeArquivo .= '.jpg';
            }

            $pastaProduto = $imagensPath . '/' . $id;
            if (!is_dir($pastaProduto) && !mkdir($pastaProduto, 0777, true) && !is_dir($pastaProduto)) {
                logAtualizacao("⚠️ Não foi possível criar a pasta de imagens para o produto $id", 'warn');
            } else {
                $destino = $pastaProduto . '/' . $nomeArquivo;

                if (!file_exists($destino)) {
                    if (!downloadImagem($urlImg, $destino)) {
                        $localImg = null;
                        logAtualizacao("⚠️ Falha ao baixar imagem do produto $id", 'warn');
                    } else {
                        $localImg = $id . '/' . $nomeArquivo;
                    }
                } else {
                    $localImg = $id . '/' . $nomeArquivo;
                }
            }
        }

        $insertStmt = $db->prepare(
            'INSERT OR IGNORE INTO produtos (id, codigo, nome, preco, imagem_url, imagem_local, gtin)
             VALUES (:id, :codigo, :nome, :preco, :url, :local, :gtin)'
        );
        $insertStmt->bindValue(':id', $id);
        $insertStmt->bindValue(':codigo', $codigo);
        $insertStmt->bindValue(':nome', $nome);
        $insertStmt->bindValue(':preco', $preco);
        if ($urlImg === null) {
            $insertStmt->bindValue(':url', null, SQLITE3_NULL);
        } else {
            $insertStmt->bindValue(':url', $urlImg);
        }

        if ($localImg === null) {
            $insertStmt->bindValue(':local', null, SQLITE3_NULL);
        } else {
            $insertStmt->bindValue(':local', $localImg);
        }
        $insertStmt->bindValue(':gtin', null, SQLITE3_NULL);
        $insertStmt->execute();

        $updateStmt = $db->prepare(
            'UPDATE produtos
             SET codigo = :codigo,
                 nome = :nome,
                 preco = :preco,
                 imagem_url = :url,
                 imagem_local = COALESCE(:local, imagem_local),
                 gtin = COALESCE(:gtin, gtin)
             WHERE id = :id'
        );
        $updateStmt->bindValue(':id', $id);
        $updateStmt->bindValue(':codigo', $codigo);
        $updateStmt->bindValue(':nome', $nome);
        $updateStmt->bindValue(':preco', $preco);
        if ($urlImg === null) {
            $updateStmt->bindValue(':url', null, SQLITE3_NULL);
        } else {
            $updateStmt->bindValue(':url', $urlImg);
        }

        if ($localImg === null) {
            $updateStmt->bindValue(':local', null, SQLITE3_NULL);
        } else {
            $updateStmt->bindValue(':local', $localImg);
        }
        $updateStmt->bindValue(':gtin', null, SQLITE3_NULL);
        $updateStmt->execute();

        $totalInseridos++;

        salvarProgresso($progressFile, $id);

        [$detalheStatus, $detalheResposta] = buscarDetalheProduto($id);
        if ($detalheStatus === 200) {
            $detalhes = json_decode($detalheResposta, true);
            if ($detalhes === null && json_last_error() !== JSON_ERROR_NONE) {
                logAtualizacao("❌ Falha ao interpretar detalhes do produto {$id}: " . json_last_error_msg(), 'error');
                $totalDetalhesFalhos++;
                continue;
            }
            $dadosProduto = $detalhes['data'] ?? null;
            if (is_array($dadosProduto)) {
                $gtin = extrairGtinProduto($dadosProduto);

                if ($gtin) {
                    $update = $db->prepare('UPDATE produtos SET gtin = :gtin WHERE id = :id');
                    $update->bindValue(':gtin', $gtin);
                    $update->bindValue(':id', $id);
                    $update->execute();
                    $totalGtinsAtualizados++;
                    logAtualizacao("✅ GTIN atualizado para o produto {$id}.", 'success');
                } else {
                    logAtualizacao("ℹ️ Produto {$id} sem GTIN informado no Bling.", 'info');
                }
            } else {
                logAtualizacao("⚠️ Resposta inesperada ao obter detalhes do produto {$id}.", 'warn');
                $totalDetalhesFalhos++;
            }
        } else {
            logAtualizacao("⚠️ Não foi possível obter detalhes do produto {$id}. HTTP {$detalheStatus}", 'warn');
            $totalDetalhesFalhos++;
        }
    }

    $pagina++;

} while (true);

if ($aguardandoRetomar) {
    logAtualizacao("⚠️ O produto salvo para retomada ({$resumeFromId}) não foi encontrado na lista atual. Um novo ciclo completo será executado da próxima vez.", 'warn');
    salvarProgresso($progressFile, null);
}

$idsAtivos = array_keys($idsEncontrados);
$idsExistentes = [];
$resultadoIds = $db->query('SELECT id FROM produtos');
while ($resultadoIds && ($row = $resultadoIds->fetchArray(SQLITE3_ASSOC))) {
    $idsExistentes[] = (string) $row['id'];
}

$paraRemover = array_diff($idsExistentes, $idsAtivos);
$totalRemovidos = 0;
if (!empty($paraRemover)) {
    $deleteStmt = $db->prepare('DELETE FROM produtos WHERE id = :id');
    foreach ($paraRemover as $idRemover) {
        $deleteStmt->reset();
        $deleteStmt->bindValue(':id', $idRemover);
        if ($deleteStmt->execute()) {
            $totalRemovidos++;
        }
    }
}

if ($totalRemovidos > 0) {
    logAtualizacao("🗑️ Produtos removidos por não estarem mais na API: {$totalRemovidos}", 'warn');
} else {
    logAtualizacao('ℹ️ Nenhum produto precisou ser removido nesta sincronização.', 'info');
}

logAtualizacao("📊 GTIN atualizados: {$totalGtinsAtualizados} | detalhes com falha: {$totalDetalhesFalhos}", 'info');
logAtualizacao("✅ Atualização concluída. Total de produtos inseridos/atualizados: $totalInseridos", 'success');

salvarProgresso($progressFile, null);

if (!$isCli) {
    echo '</div><p style="margin-top:16px;font-size:12px;color:#8b949e;">Processo finalizado em ' . date('d/m/Y H:i:s') . '.</p></div></body></html>';
    flushOutput();
}
