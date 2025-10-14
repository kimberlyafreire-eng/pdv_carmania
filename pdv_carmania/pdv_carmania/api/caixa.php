<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/lib/caixa-helper.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? null;
        if ($action === 'tipos') {
            $db = getCaixaDb();
            $tipos = listarTiposMovimentacao($db);
            echo json_encode(['ok' => true, 'tipos' => $tipos], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $depositoId = trim((string)($_GET['depositoId'] ?? ''));
        if ($depositoId === '') {
            throw new InvalidArgumentException('Informe o depósito do caixa.');
        }
        $depositoNome = trim((string)($_GET['depositoNome'] ?? ''));

        $dados = obterDadosCaixa($depositoId, $depositoNome);
        echo json_encode(['ok' => true] + $dados, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Dados inválidos.');
        }

        $depositoId = trim((string)($payload['depositoId'] ?? ''));
        $depositoNome = trim((string)($payload['depositoNome'] ?? ''));
        $tipoSlug = strtolower(trim((string)($payload['tipo'] ?? '')));
        $valor = $payload['valor'] ?? null;
        $observacao = (string)($payload['observacao'] ?? '');

        if ($depositoId === '') {
            throw new InvalidArgumentException('Depósito do caixa não informado.');
        }
        if ($tipoSlug === '') {
            throw new InvalidArgumentException('Tipo de movimentação obrigatório.');
        }
        if (!is_numeric($valor)) {
            throw new InvalidArgumentException('Valor inválido.');
        }

        $valor = (float)$valor;
        if ($valor <= 0) {
            throw new InvalidArgumentException('Informe um valor maior que zero.');
        }

        $dados = registrarMovimentacaoCaixa($depositoId, $depositoNome, $tipoSlug, $valor, $observacao, $_SESSION['usuario']);
        echo json_encode(['ok' => true, 'mensagem' => 'Movimentação registrada com sucesso.'] + $dados, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (LogicException $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Erro no caixa.php: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'erro' => 'Erro inesperado.'], JSON_UNESCAPED_UNICODE);
}
