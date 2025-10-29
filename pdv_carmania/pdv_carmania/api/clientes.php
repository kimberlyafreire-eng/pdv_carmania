<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

// ✅ Carrega o token automaticamente e renova se necessário
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
    $clientesLocal = buscarClientesLocalmente($db);
} catch (Throwable $e) {
    error_log('[clientes.php] Falha ao acessar banco local: ' . $e->getMessage());
    $db = null;
    $clientesLocal = [];
}

if (!$forcarAtualizacao && !empty($clientesLocal)) {
    echo json_encode(['data' => $clientesLocal], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
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

try {
    $resultado = sincronizarClientesComBling($accessToken, $db instanceof SQLite3 ? $db : null, $caminhoCache);
    $clientesSincronizados = $resultado['clientesLocais'];
    if (empty($clientesSincronizados)) {
        $clientesSincronizados = $resultado['clientesNormalizados'];
    }

    $payload = json_encode(['data' => $clientesSincronizados], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        http_response_code(500);
        echo json_encode(["erro" => "Falha ao preparar resposta de clientes"]);
    } else {
        echo $payload;
    }
} catch (RuntimeException $e) {
    $status = $e->getCode();
    if (!is_int($status) || $status < 200 || $status >= 600) {
        $status = 500;
    }
    http_response_code($status);
    echo json_encode(['erro' => $e->getMessage()]);
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }
}
