<?php
require_once __DIR__ . '/../session.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Não autorizado']);
    exit();
}

require_once __DIR__ . '/lib/vendas-helper.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
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
    $stmt = $db->prepare('SELECT v.*, s.nome AS situacao_nome
                           FROM vendas v
                           LEFT JOIN situacoes_pedido s ON s.id = v.situacao_id
                           WHERE v.id = :id
                           LIMIT 1');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $venda = $res->fetchArray(SQLITE3_ASSOC);

    if (!$venda) {
        echo json_encode(['ok' => false, 'erro' => 'Venda não encontrada']);
        exit();
    }

    $venda['valor_total'] = (float) $venda['valor_total'];
    $venda['valor_desconto'] = (float) $venda['valor_desconto'];

    $pagamentos = [];
    $stmtPag = $db->prepare('SELECT forma_pagamento_id, forma_pagamento_nome, valor
                              FROM venda_pagamentos
                              WHERE venda_id = :id
                              ORDER BY id');
    $stmtPag->bindValue(':id', $id, SQLITE3_INTEGER);
    $resPag = $stmtPag->execute();
    while ($row = $resPag->fetchArray(SQLITE3_ASSOC)) {
        $pagamentos[] = [
            'id' => $row['forma_pagamento_id'] !== null ? (int) $row['forma_pagamento_id'] : null,
            'nome' => $row['forma_pagamento_nome'],
            'valor' => (float) $row['valor'],
        ];
    }

    $itens = [];
    $stmtItens = $db->prepare('SELECT produto_id, produto_nome, valor_unitario, quantidade
                                FROM venda_itens
                                WHERE venda_id = :id
                                ORDER BY id');
    $stmtItens->bindValue(':id', $id, SQLITE3_INTEGER);
    $resItens = $stmtItens->execute();
    while ($row = $resItens->fetchArray(SQLITE3_ASSOC)) {
        $itens[] = [
            'id' => $row['produto_id'] !== null ? (int) $row['produto_id'] : null,
            'nome' => $row['produto_nome'],
            'valor_unitario' => (float) $row['valor_unitario'],
            'quantidade' => (int) $row['quantidade'],
            'subtotal' => round((float) $row['valor_unitario'] * (int) $row['quantidade'], 2),
        ];
    }

    echo json_encode([
        'ok' => true,
        'venda' => $venda,
        'pagamentos' => $pagamentos,
        'itens' => $itens,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao carregar detalhes da venda', 'detalhe' => $e->getMessage()]);
}
