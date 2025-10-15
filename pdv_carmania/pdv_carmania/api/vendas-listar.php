<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Não autorizado']);
    exit();
}

require_once __DIR__ . '/lib/vendas-helper.php';

try {
    $db = getVendasDb();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao acessar banco local', 'detalhe' => $e->getMessage()]);
    exit();
}

$dataInicio = isset($_GET['dataInicio']) ? trim((string) $_GET['dataInicio']) : '';
$dataFim = isset($_GET['dataFim']) ? trim((string) $_GET['dataFim']) : '';
$formaPagamentoId = isset($_GET['formaPagamentoId']) ? trim((string) $_GET['formaPagamentoId']) : '';

$hoje = date('Y-m-d');
if ($dataInicio === '') {
    $dataInicio = $hoje;
}
if ($dataFim === '') {
    $dataFim = $hoje;
}

if ($dataInicio > $dataFim) {
    [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
}

$formaPagamentoFiltro = null;
if ($formaPagamentoId !== '') {
    if (!ctype_digit($formaPagamentoId)) {
        echo json_encode(['ok' => false, 'erro' => 'Forma de pagamento inválida']);
        exit();
    }
    $formaPagamentoFiltro = (int) $formaPagamentoId;
}

try {
    $sql = 'SELECT v.id, v.data_hora, v.contato_id, v.contato_nome, v.usuario_nome, v.usuario_login,
                   v.valor_total, v.valor_desconto, v.situacao_id, s.nome AS situacao_nome
            FROM vendas v
            LEFT JOIN situacoes_pedido s ON s.id = v.situacao_id
            WHERE DATE(v.data_hora) BETWEEN :inicio AND :fim';

    if ($formaPagamentoFiltro !== null) {
        $sql .= ' AND EXISTS (SELECT 1 FROM venda_pagamentos vp WHERE vp.venda_id = v.id AND vp.forma_pagamento_id = :forma)';
    }

    $sql .= ' ORDER BY v.data_hora DESC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':inicio', $dataInicio, SQLITE3_TEXT);
    $stmt->bindValue(':fim', $dataFim, SQLITE3_TEXT);
    if ($formaPagamentoFiltro !== null) {
        $stmt->bindValue(':forma', $formaPagamentoFiltro, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();

    $vendas = [];
    $ids = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['valor_total'] = (float) $row['valor_total'];
        $row['valor_desconto'] = (float) $row['valor_desconto'];
        $row['situacao_id'] = $row['situacao_id'] !== null ? (int) $row['situacao_id'] : null;
        $vendas[] = $row;
        $ids[] = (int) $row['id'];
    }

    $pagamentosPorVenda = [];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtPag = $db->prepare('SELECT venda_id, forma_pagamento_id, forma_pagamento_nome, valor
                                 FROM venda_pagamentos
                                 WHERE venda_id IN (' . $placeholders . ')
                                 ORDER BY id');
        foreach ($ids as $idx => $id) {
            $stmtPag->bindValue($idx + 1, $id, SQLITE3_INTEGER);
        }
        $resPag = $stmtPag->execute();
        while ($pag = $resPag->fetchArray(SQLITE3_ASSOC)) {
            $vid = (int) $pag['venda_id'];
            if (!isset($pagamentosPorVenda[$vid])) {
                $pagamentosPorVenda[$vid] = [];
            }
            $pagamentosPorVenda[$vid][] = [
                'id' => $pag['forma_pagamento_id'] !== null ? (int) $pag['forma_pagamento_id'] : null,
                'nome' => $pag['forma_pagamento_nome'],
                'valor' => (float) $pag['valor'],
            ];
        }
    }

    foreach ($vendas as &$venda) {
        $idVenda = (int) $venda['id'];
        $venda['pagamentos'] = $pagamentosPorVenda[$idVenda] ?? [];
    }
    unset($venda);

    $formasDisponiveis = [];
    $resFormas = $db->query('SELECT DISTINCT forma_pagamento_id AS id, forma_pagamento_nome AS nome
                              FROM venda_pagamentos
                              WHERE forma_pagamento_id IS NOT NULL AND TRIM(IFNULL(forma_pagamento_nome, "")) <> ""
                              ORDER BY nome COLLATE NOCASE');
    while ($row = $resFormas->fetchArray(SQLITE3_ASSOC)) {
        $formasDisponiveis[] = [
            'id' => (int) $row['id'],
            'nome' => $row['nome'],
        ];
    }

    $stmtDia = $db->prepare('SELECT COALESCE(SUM(valor_total), 0) AS total FROM vendas WHERE situacao_id = 9 AND DATE(data_hora) = :hoje');
    $stmtDia->bindValue(':hoje', $hoje, SQLITE3_TEXT);
    $totalDia = (float) ($stmtDia->execute()->fetchArray(SQLITE3_ASSOC)['total'] ?? 0);

    $inicioMes = date('Y-m-01');
    $fimMes = date('Y-m-t');
    $stmtMes = $db->prepare('SELECT COALESCE(SUM(valor_total), 0) AS total FROM vendas WHERE situacao_id = 9 AND DATE(data_hora) BETWEEN :inicio AND :fim');
    $stmtMes->bindValue(':inicio', $inicioMes, SQLITE3_TEXT);
    $stmtMes->bindValue(':fim', $fimMes, SQLITE3_TEXT);
    $totalMes = (float) ($stmtMes->execute()->fetchArray(SQLITE3_ASSOC)['total'] ?? 0);

    echo json_encode([
        'ok' => true,
        'resumo' => [
            'totalDia' => round($totalDia, 2),
            'totalMes' => round($totalMes, 2),
        ],
        'vendas' => $vendas,
        'formasPagamento' => $formasDisponiveis,
        'filtros' => [
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'formaPagamentoId' => $formaPagamentoFiltro,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao listar vendas', 'detalhe' => $e->getMessage()]);
}
