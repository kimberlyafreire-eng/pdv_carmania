<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/clientes-db.php';
require_once __DIR__ . '/lib/clientes-sync.php';

$caminhoCache = __DIR__ . '/../cache/clientes-cache.json';

$forcarAtualizacao = false;
if (isset($_GET['refresh'])) {
    $valorRefresh = strtolower(trim((string) $_GET['refresh']));
    $forcarAtualizacao = !in_array($valorRefresh, ['0', 'false', 'nao', 'não', 'no', ''], true);
}

$db = null;
$clientesLocal = [];

try {
    $db = getClientesDb();
    importarClientesCache($db, $caminhoCache);

    if (!$forcarAtualizacao) {
        $clientesLocal = buscarClientesLocalmente($db);
        if (!empty($clientesLocal)) {
            echo json_encode(['data' => $clientesLocal], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $db->close();
            exit();
        }
    }
} catch (Throwable $e) {
    error_log('[clientes.php] Falha ao acessar banco local: ' . $e->getMessage());
    $db = null;
}

$accessToken = getAccessToken();
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao obter token do Bling"]);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
}

if (!$db instanceof SQLite3) {
    try {
        $db = getClientesDb();
        importarClientesCache($db, $caminhoCache);
    } catch (Throwable $e) {
        error_log('[clientes.php] Não foi possível reabrir o banco de clientes: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(["erro" => "Banco de clientes indisponível"]);
        exit();
    }
}

try {
    $clientesSincronizados = sincronizarClientesComBling($db, $accessToken, $caminhoCache);
} catch (Throwable $e) {
    error_log('[clientes.php] Falha ao sincronizar clientes: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'erro' => 'Não foi possível atualizar a lista de clientes.',
    ]);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
}

$payload = json_encode(['data' => $clientesSincronizados], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($payload === false) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao preparar resposta de clientes"]);
} else {
    echo $payload;
}

if ($db instanceof SQLite3) {
    $db->close();
}

