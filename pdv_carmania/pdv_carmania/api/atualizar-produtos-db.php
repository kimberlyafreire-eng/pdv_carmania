<?php
require_once __DIR__ . '/lib/token-helper.php';

// CONFIGURAÇÕES
$tokenFile   = __DIR__ . '/token.json';
$dbFile      = __DIR__ . '/../db/produtos.db';
$imagensPath = __DIR__ . '/../imagens';
$logDir      = __DIR__ . '/../logs';
$logFile     = $logDir . '/atualizar.log';

// GARANTIR PASTAS
if (!is_dir(dirname($dbFile))) mkdir(dirname($dbFile), 0777, true);
if (!is_dir($imagensPath)) mkdir($imagensPath, 0777, true);
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

function logAtualizacao($msg) {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    echo $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
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

if (!file_exists($tokenFile)) {
    logAtualizacao("❌ Token não encontrado. Execute o auth.php manualmente.");
    exit;
}

$access_token = getAccessToken();

if (!$access_token) {
    logAtualizacao("⚠️ Token indisponível. Tentando renovar...");
    if (!refreshAccessToken()) {
        logAtualizacao("❌ Não foi possível renovar o token.");
        exit;
    }
    $access_token = getAccessToken();
    if (!$access_token) {
        logAtualizacao("❌ Access token ainda indisponível após renovação.");
        exit;
    }
}

logAtualizacao("✅ Token carregado.");

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
    global $access_token;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $rawResponse = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($rawResponse === false) {
        $headersRaw = '';
        $body = '';
    } else {
        $headersRaw = substr($rawResponse, 0, $headerSize);
        $body = substr($rawResponse, $headerSize);
    }
    $headers = extrairHeaders($headersRaw);

    if ($httpcode === 401 && $tentativa < 2) {
        logAtualizacao("⚠️ Token expirado ao {$contexto}. Renovando...");
        if (refreshAccessToken()) {
            $novoToken = getAccessToken();
            if ($novoToken) {
                $access_token = $novoToken;
                return executarRequisicaoBling($url, $contexto, $tentativa + 1);
            }
        }
        logAtualizacao("❌ Falha ao renovar token durante {$contexto}.");
    }

    if ($httpcode === 429 && $tentativa < 5) {
        $retryAfter = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 0;
        if ($retryAfter <= 0) {
            $retryAfter = min(60, (int) pow(2, $tentativa + 1));
        }
        logAtualizacao("⏳ Limite de requisições atingido ao {$contexto}. Aguardando {$retryAfter}s para tentar novamente...");
        sleep($retryAfter);
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

do {
    [$httpcode, $response] = buscarPaginaProdutos($pagina);

    if ($httpcode !== 200) {
        logAtualizacao("❌ Erro ao buscar página $pagina: HTTP $httpcode");
        logAtualizacao("⏳ Tentando novamente após 5s...");
        sleep(5);
        continue;
    }

    $dados = json_decode($response, true);
    $produtos = $dados['data'] ?? [];

    logAtualizacao("📦 Página $pagina - " . count($produtos) . " produtos");

    if (empty($produtos)) break;

    foreach ($produtos as $p) {
        $id = (string) ($p['id'] ?? '');
        $codigo = isset($p['codigo']) ? (string) $p['codigo'] : '';
        $nome = isset($p['nome']) ? (string) $p['nome'] : '';
        if ($id === '') {
            logAtualizacao('⚠️ Produto retornado sem ID válido, ignorando registro.');
            continue;
        }

        $preco = isset($p['preco']) ? (float) $p['preco'] : 0.00;
        $urlImg = $p['imagemURL'] ?? ($p['imagem'] ?? null);
        $localImg = null;

        $gtinAtual = null;
        $gtinConsulta = $db->prepare('SELECT gtin FROM produtos WHERE id = :id LIMIT 1');
        $gtinConsulta->bindValue(':id', $id);
        $resultadoGtin = $gtinConsulta->execute();
        if ($resultadoGtin && ($linhaGtin = $resultadoGtin->fetchArray(SQLITE3_ASSOC))) {
            $gtinAtual = $linhaGtin['gtin'];
        }

        if ($urlImg && filter_var($urlImg, FILTER_VALIDATE_URL)) {
            $path = parse_url($urlImg, PHP_URL_PATH) ?? '';
            $nomeArquivo = basename($path) ?: ($id . '.jpg');
            $ext = pathinfo($nomeArquivo, PATHINFO_EXTENSION);

            if (!$ext) {
                $nomeArquivo .= '.jpg';
            }

            $pastaProduto = $imagensPath . '/' . $id;
            if (!is_dir($pastaProduto) && !mkdir($pastaProduto, 0777, true) && !is_dir($pastaProduto)) {
                logAtualizacao("⚠️ Não foi possível criar a pasta de imagens para o produto $id");
            } else {
                $destino = $pastaProduto . '/' . $nomeArquivo;

                if (!file_exists($destino)) {
                    if (!downloadImagem($urlImg, $destino)) {
                        $localImg = null;
                        logAtualizacao("⚠️ Falha ao baixar imagem do produto $id");
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

        [$detalheStatus, $detalheResposta] = buscarDetalheProduto($id);
        if ($detalheStatus === 200) {
            $detalhes = json_decode($detalheResposta, true);
            $dadosProduto = $detalhes['data'] ?? null;
            if (is_array($dadosProduto)) {
                $gtin = extrairGtinProduto($dadosProduto);

                if ($gtin) {
                    $update = $db->prepare('UPDATE produtos SET gtin = :gtin WHERE id = :id');
                    $update->bindValue(':gtin', $gtin);
                    $update->bindValue(':id', $id);
                    $update->execute();
                } else {
                    logAtualizacao("ℹ️ Produto {$id} sem GTIN informado no Bling.");
                }
            } else {
                logAtualizacao("⚠️ Resposta inesperada ao obter detalhes do produto {$id}.");
            }
        } else {
            logAtualizacao("⚠️ Não foi possível obter detalhes do produto {$id}. HTTP {$detalheStatus}");
        }

        usleep(200000);
    }

    $pagina++;
    usleep(400000);

} while (true);

logAtualizacao("✅ Atualização concluída. Total de produtos inseridos/atualizados: $totalInseridos");
