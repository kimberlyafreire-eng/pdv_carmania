<?php
require_once __DIR__ . '/../session.php';
header('Content-Type: application/json; charset=utf-8');
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
$vendedorParam = isset($_GET['vendedor']) ? trim((string) $_GET['vendedor']) : '';

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

$lowercase = static function (string $texto): string {
    return function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
};

$usuarioFiltro = null;
if ($vendedorParam !== '') {
    $tipo = 'id';
    $valorBruto = $vendedorParam;
    $partes = explode(':', $vendedorParam, 2);
    if (count($partes) === 2) {
        [$tipo, $valorBruto] = $partes;
    }

    $tipo = trim((string) $tipo);
    $valorBruto = trim((string) $valorBruto);

    switch ($tipo) {
        case 'id':
            if ($valorBruto === '' || !ctype_digit($valorBruto)) {
                echo json_encode(['ok' => false, 'erro' => 'Vendedor inválido (id)']);
                exit();
            }
            $usuarioFiltro = [
                'condicao' => 'v.usuario_id = :usuario_id_filtro',
                'parametro' => ':usuario_id_filtro',
                'valor' => (int) $valorBruto,
                'tipo' => SQLITE3_INTEGER,
            ];
            break;
        case 'login':
        case 'nome':
            if ($valorBruto === '') {
                echo json_encode(['ok' => false, 'erro' => 'Vendedor inválido']);
                exit();
            }
            $valorDecodificado = rawurldecode($valorBruto);
            $valorNormalizado = $lowercase(trim($valorDecodificado));
            if ($valorNormalizado === '') {
                echo json_encode(['ok' => false, 'erro' => 'Vendedor inválido']);
                exit();
            }
            if ($tipo === 'login') {
                $usuarioFiltro = [
                    'condicao' => 'LOWER(TRIM(IFNULL(v.usuario_login, ""))) = :usuario_login_filtro',
                    'parametro' => ':usuario_login_filtro',
                    'valor' => $valorNormalizado,
                    'tipo' => SQLITE3_TEXT,
                ];
            } else {
                $usuarioFiltro = [
                    'condicao' => 'LOWER(TRIM(IFNULL(v.usuario_nome, ""))) = :usuario_nome_filtro',
                    'parametro' => ':usuario_nome_filtro',
                    'valor' => $valorNormalizado,
                    'tipo' => SQLITE3_TEXT,
                ];
            }
            break;
        default:
            echo json_encode(['ok' => false, 'erro' => 'Vendedor inválido']);
            exit();
    }
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

    if ($usuarioFiltro !== null) {
        $sql .= ' AND ' . $usuarioFiltro['condicao'];
    }

    $sql .= ' ORDER BY v.data_hora DESC';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':inicio', $dataInicio, SQLITE3_TEXT);
    $stmt->bindValue(':fim', $dataFim, SQLITE3_TEXT);
    if ($formaPagamentoFiltro !== null) {
        $stmt->bindValue(':forma', $formaPagamentoFiltro, SQLITE3_INTEGER);
    }
    if ($usuarioFiltro !== null) {
        $stmt->bindValue($usuarioFiltro['parametro'], $usuarioFiltro['valor'], $usuarioFiltro['tipo']);
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

    $usuariosMap = [];
    $resUsuarios = $db->query('SELECT usuario_id, usuario_login, usuario_nome FROM vendas');
    while ($row = $resUsuarios->fetchArray(SQLITE3_ASSOC)) {
        $id = isset($row['usuario_id']) ? (int) $row['usuario_id'] : null;
        $login = trim((string) ($row['usuario_login'] ?? ''));
        $nome = trim((string) ($row['usuario_nome'] ?? ''));

        if ($id === null && $login === '' && $nome === '') {
            continue;
        }

        if ($id !== null) {
            $chave = 'id:' . $id;
        } elseif ($login !== '') {
            $chave = 'login:' . $lowercase($login);
        } else {
            $chave = 'nome:' . $lowercase($nome);
        }

        if (!isset($usuariosMap[$chave])) {
            $usuariosMap[$chave] = [
                'id' => $id,
                'login' => $login !== '' ? $login : null,
                'nome' => $nome !== '' ? $nome : null,
            ];
        }
    }

    $usuariosDisponiveis = array_values($usuariosMap);
    usort($usuariosDisponiveis, static function (array $a, array $b) use ($lowercase): int {
        $rotuloA = $a['nome'] ?? $a['login'] ?? '';
        $rotuloB = $b['nome'] ?? $b['login'] ?? '';
        return strcmp($lowercase((string) $rotuloA), $lowercase((string) $rotuloB));
    });

    $totalFiltrado = 0.0;
    foreach ($vendas as $venda) {
        $totalFiltrado += (float) ($venda['valor_total'] ?? 0);
    }

    echo json_encode([
        'ok' => true,
        'resumo' => [
            'totalDia' => round($totalFiltrado, 2),
        ],
        'vendas' => $vendas,
        'formasPagamento' => $formasDisponiveis,
        'usuarios' => $usuariosDisponiveis,
        'filtros' => [
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'formaPagamentoId' => $formaPagamentoFiltro,
            'vendedor' => $vendedorParam,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro ao listar vendas', 'detalhe' => $e->getMessage()]);
}
