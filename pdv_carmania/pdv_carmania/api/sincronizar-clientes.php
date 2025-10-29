<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/clientes-db.php';
require_once __DIR__ . '/lib/clientes-sync.php';

$db = null;
try {
    $db = getClientesDb();
} catch (Throwable $e) {
    error_log('[sincronizar-clientes.php] Falha ao acessar banco local: ' . $e->getMessage());
    $db = null;
}

$accessToken = getAccessToken();
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha ao obter token do Bling.']);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
}

$caminhoCache = __DIR__ . '/../cache/clientes-cache.json';

try {
    $resultado = sincronizarClientesComBling($accessToken, $db instanceof SQLite3 ? $db : null, $caminhoCache);
    $clientesLocais = $resultado['clientesLocais'];
    if (empty($clientesLocais)) {
        $clientesLocais = $resultado['clientesNormalizados'];
    }

    $totais = [
        'totalRemoto' => count($resultado['clientesNormalizados']),
        'totalLocal' => count($clientesLocais),
        'novos' => count($resultado['idsInseridos']),
        'removidos' => count($resultado['idsRemovidos']),
    ];

    $mensagem = sprintf(
        'Sincronização concluída. %d cliente%s disponível%s no banco local.',
        $totais['totalLocal'],
        $totais['totalLocal'] === 1 ? '' : 's',
        $totais['totalLocal'] === 1 ? '' : 's'
    );

    echo json_encode([
        'sucesso' => true,
        'mensagem' => $mensagem,
        'totais' => $totais,
        'clientes' => $clientesLocais,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
