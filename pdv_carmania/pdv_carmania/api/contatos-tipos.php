<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/contatos-helper.php';

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

try {
    $dados = ensureContactTypesCache($forceRefresh);
    echo json_encode([
        'data' => $dados['data'] ?? $dados,
        'fonte' => file_exists(getContactTypesCachePath()) ? 'cache' : 'api',
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
