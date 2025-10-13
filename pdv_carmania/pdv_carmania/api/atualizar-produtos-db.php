<?php
// CONFIGURAÇÕES
$client_id = 'c04c4a60229a850f4c932da08d3f0a7e5e32b976';
$client_secret = '104ac382735af76c4b1c380b481bc3ff3c9146d88dcd5d7553bb84b24b49';

$tokenFile   = __DIR__ . '/token.json';
$dbFile      = __DIR__ . '/../db/produtos.db';
$imagensPath = __DIR__ . '/../imagens';
$logDir      = __DIR__ . '/../logs';
$logFile     = $logDir . '/atualizar.log';

// GARANTIR PASTAS
if (!is_dir(dirname($dbFile))) mkdir(dirname($dbFile), 0777, true);
if (!is_dir($imagensPath)) mkdir($imagensPath, 0777, true);
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

// FUNÇÃO DE LOG
function logMsg($msg) {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    echo $msg . "\n"; // também mostra no terminal
    file_put_contents($logFile, $line, FILE_APPEND);
}

logMsg("🔁 Iniciando atualização de produtos...");

// VALIDAR TOKEN
if (!file_exists($tokenFile)) {
    logMsg("❌ Token não encontrado. Execute o auth.php manualmente.");
    exit;
}

$tokenData = json_decode(file_get_contents($tokenFile), true);
$refresh_token = $tokenData['refresh_token'] ?? '';
$credentials = base64_encode("$client_id:$client_secret");

// RENOVAR TOKEN
$ch = curl_init('https://bling.com.br/Api/v3/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refresh_token
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Basic $credentials",
    "Content-Type: application/x-www-form-urlencoded"
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode !== 200) {
    logMsg("❌ Erro ao renovar token: HTTP $httpcode");
    logMsg("Resposta: $response");
    exit;
}

$newToken = json_decode($response, true);
file_put_contents($tokenFile, json_encode($newToken, JSON_PRETTY_PRINT));
$access_token = $newToken['access_token'] ?? '';
if (!$access_token) {
    logMsg("❌ Access token ausente.");
    exit;
}

logMsg("✅ Token renovado.");

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
    $url = "https://bling.com.br/Api/v3/produtos?pagina=$pagina&limite=100";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        logMsg("❌ Erro ao buscar página $pagina: HTTP $httpcode");
        logMsg("⏳ Tentando novamente após 5s...");
        sleep(5);
        continue;
    }

    $dados = json_decode($response, true);
    $produtos = $dados['data'] ?? [];

    logMsg("📦 Página $pagina - " . count($produtos) . " produtos");

    if (empty($produtos)) break;

    foreach ($produtos as $p) {
        $id = $db->escapeString($p['id']);
        $codigo = $db->escapeString($p['codigo']);
        $nome = $db->escapeString($p['nome']);
        $preco = isset($p['preco']) ? (float) $p['preco'] : 0.00;
        $urlImg = $p['imagem'] ?? null;
        $localImg = null;

        if ($urlImg && filter_var($urlImg, FILTER_VALIDATE_URL)) {
            $ext = pathinfo(parse_url($urlImg, PHP_URL_PATH), PATHINFO_EXTENSION);
            $localImg = "$id." . ($ext ?: 'jpg');
            $destino = "$imagensPath/$localImg";

            try {
                if (!file_exists($destino)) {
                    file_put_contents($destino, file_get_contents($urlImg));
                }
            } catch (Exception $e) {
                $localImg = null;
                logMsg("⚠️ Falha ao baixar imagem do produto $id");
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

logMsg("✅ Atualização concluída. Total de produtos inseridos/atualizados: $totalInseridos");
?>