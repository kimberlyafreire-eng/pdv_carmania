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

function buscarPaginaProdutos(int $pagina, int $tentativa = 0)
{
    global $blingBase, $access_token;

    $url = rtrim($blingBase, '/') . "/produtos?pagina={$pagina}&limite=100";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 401 && $tentativa < 1) {
        logAtualizacao("⚠️ Token expirado ao buscar página {$pagina}. Renovando...");
        if (refreshAccessToken()) {
            $access_token = getAccessToken();
            if ($access_token) {
                return buscarPaginaProdutos($pagina, $tentativa + 1);
            }
        }
        logAtualizacao("❌ Falha ao renovar token durante a sincronização.");
    }

    return [$httpcode, $response];
}

// CONECTAR OU CRIAR BANCO DE DADOS
$db = new SQLite3($dbFile);
$db->exec("CREATE TABLE IF NOT EXISTS produtos (
    id TEXT PRIMARY KEY,
    codigo TEXT,
    nome TEXT,
    preco REAL,
    imagem_url TEXT,
    imagem_local TEXT
)");

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
        $id = $db->escapeString($p['id']);
        $codigo = $db->escapeString($p['codigo']);
        $nome = $db->escapeString($p['nome']);
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

        $stmt = $db->prepare("INSERT OR REPLACE INTO produtos
            (id, codigo, nome, preco, imagem_url, imagem_local) 
            VALUES (:id, :codigo, :nome, :preco, :url, :local)");
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':codigo', $codigo);
        $stmt->bindValue(':nome', $nome);
        $stmt->bindValue(':preco', $preco);
        $stmt->bindValue(':url', $urlImg);
        $stmt->bindValue(':local', $localImg);
        $stmt->execute();

        $totalInseridos++;
    }

    $pagina++;
    usleep(400000);

} while (true);

logAtualizacao("✅ Atualização concluída. Total de produtos inseridos/atualizados: $totalInseridos");
?>