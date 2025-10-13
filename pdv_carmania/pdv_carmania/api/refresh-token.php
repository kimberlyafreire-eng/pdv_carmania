<?php
header('Content-Type: application/json; charset=utf-8');

// ✅ Usa o helper centralizado (já tem o logMsg)
require_once __DIR__ . '/lib/token-helper.php';

$logFile = __DIR__ . '/../logs/refresh-token.log';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0777, true);

// 🔍 Tenta renovar o token
logMsg("🔄 Iniciando tentativa de renovação manual...");

try {
    $ok = refreshAccessToken();

    if ($ok) {
        $tokenData = json_decode(file_get_contents(__DIR__ . '/token.json'), true);
        $expiraEm = isset($tokenData['expires_at'])
            ? date('d/m/Y H:i:s', $tokenData['expires_at'])
            : '(sem data de expiração)';

        logMsg("✅ Token renovado com sucesso! Expira em: $expiraEm");

        echo json_encode([
            'ok' => true,
            'msg' => 'Token renovado com sucesso',
            'expiraEm' => $expiraEm
        ], JSON_UNESCAPED_UNICODE);
    } else {
        logMsg("❌ Falha ao renovar token (função retornou falso)");
        echo json_encode(['ok' => false, 'erro' => 'Falha ao renovar token'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    logMsg("💥 Exceção ao renovar token: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'erro' => 'Erro ao renovar token',
        'detalhe' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
