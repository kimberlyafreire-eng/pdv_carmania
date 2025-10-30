<?php
require_once __DIR__ . '/../session.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Não autorizado']);
    exit();
}

require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/vendas-helper.php';

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/venda-cancelar.log';

function logCancelar(string $mensagem): void
{
    global $logFile;
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    file_put_contents($logFile, $linha, FILE_APPEND);
}

function blingRequest(string $method, string $path, $body = null): array
{
    $blingBase = 'https://api.bling.com.br/Api/v3';
    $url = rtrim($blingBase, '/') . '/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . getAccessToken(),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40,
    ]);

    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        logCancelar('Payload: ' . $payload);
    }

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    logCancelar(sprintf('[%s] %s -> HTTP %d%s', strtoupper($method), $url, $http, $err ? " ERR=$err" : ''));
    if ($resp !== false && $resp !== null && $resp !== '') {
        logCancelar('Resp: ' . $resp);
    }

    if (in_array($http, [401, 403], true)) {
        logCancelar('⚠️ Token expirado ao cancelar. Renovando e tentando novamente.');
        refreshAccessToken();
        return blingRequest($method, $path, $body);
    }

    return ['http' => $http, 'body' => $resp];
}

function executarBlingAcao(string $etapa, string $path, string $method = 'POST'): void
{
    $resultado = blingRequest($method, $path);
    $http = (int) ($resultado['http'] ?? 0);
    if ($http < 200 || $http >= 300) {
        $detalhe = trim((string) ($resultado['body'] ?? ''));
        logCancelar(sprintf('❌ Falha ao %s (HTTP %d): %s', $etapa, $http, $detalhe));
        throw new RuntimeException('Falha ao ' . $etapa);
    }
    logCancelar(sprintf('✅ %s concluído (HTTP %d)', $etapa, $http));
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'JSON inválido']);
    exit();
}

$vendaId = isset($input['id']) ? (int) $input['id'] : 0;
if ($vendaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'ID de venda inválido']);
    exit();
}

try {
    $db = getVendasDb();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao acessar banco local', 'detalhe' => $e->getMessage()]);
    exit();
}

try {
    $stmt = $db->prepare('SELECT id, transmitido, situacao_id FROM vendas WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $vendaId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $venda = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if (!$venda) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'erro' => 'Venda não encontrada.']);
        exit();
    }

    $transmitido = isset($venda['transmitido']) ? (int) $venda['transmitido'] : 0;
    if ($transmitido === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Venda ainda não foi transmitida ao Bling.']);
        exit();
    }

    if (isset($venda['situacao_id']) && (int) $venda['situacao_id'] === 12) {
        echo json_encode(['ok' => true, 'mensagem' => 'Venda já está cancelada.']);
        exit();
    }

    executarBlingAcao('estornar o estoque', "/pedidos/vendas/{$vendaId}/estornar-estoque");
    executarBlingAcao('estornar o contas a receber', "/pedidos/vendas/{$vendaId}/estornar-contas");
    executarBlingAcao('alterar a situação para cancelada', "/pedidos/vendas/{$vendaId}/situacoes/12", 'PATCH');

    $db->exec('BEGIN IMMEDIATE');
    try {
        $update = $db->prepare('UPDATE vendas SET situacao_id = :situacao, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id');
        $update->bindValue(':situacao', 12, SQLITE3_INTEGER);
        $update->bindValue(':id', $vendaId, SQLITE3_INTEGER);
        if (!$update->execute()) {
            throw new RuntimeException('Falha ao atualizar situação local.');
        }

        if ($db->changes() === 0) {
            throw new RuntimeException('Nenhuma venda atualizada localmente.');
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        logCancelar('Erro ao atualizar situação local: ' . $e->getMessage());
        throw $e;
    }

    echo json_encode([
        'ok' => true,
        'mensagem' => 'Venda cancelada com sucesso.',
        'situacao_id' => 12,
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Não foi possível cancelar a venda, solicite o cancelamento pelo Bling.']);
} catch (Throwable $e) {
    logCancelar('Erro inesperado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Não foi possível cancelar a venda, solicite o cancelamento pelo Bling.']);
}
