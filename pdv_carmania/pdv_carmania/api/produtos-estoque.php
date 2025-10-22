<?php
header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/../db/produtos.db';
if (!file_exists($dbFile)) {
    echo json_encode([
        'ok' => false,
        'erro' => 'Banco de dados não encontrado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$depositoId = isset($_GET['depositoId']) ? trim((string)$_GET['depositoId']) : '';

try {
    $db = new SQLite3($dbFile);

    $tabelaExiste = false;
    $colunas = [];
    $info = $db->query('PRAGMA table_info(estoque_local)');
    while ($info && ($row = $info->fetchArray(SQLITE3_ASSOC))) {
        $tabelaExiste = true;
        $colunas[$row['name']] = true;
    }

    if (!$tabelaExiste) {
        echo json_encode([
            'ok' => true,
            'estoque' => [],
            'depositoId' => $depositoId,
            'mensagem' => 'Tabela de estoque não encontrada, retornando valores vazios.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $colunaIdProduto = null;
    foreach (['idProduto', 'produto_id', 'id_produto'] as $col) {
        if (isset($colunas[$col])) {
            $colunaIdProduto = $col;
            break;
        }
    }

    $colunaIdDeposito = null;
    foreach (['idDeposito', 'deposito_id', 'id_deposito'] as $col) {
        if (isset($colunas[$col])) {
            $colunaIdDeposito = $col;
            break;
        }
    }

    $colunaSaldo = null;
    foreach (['saldo', 'saldo_disponivel', 'saldoDisponivel'] as $col) {
        if (isset($colunas[$col])) {
            $colunaSaldo = $col;
            break;
        }
    }

    if (!$colunaIdProduto || !$colunaSaldo) {
        echo json_encode([
            'ok' => false,
            'erro' => 'Estrutura da tabela estoque_local inválida.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $estoque = [];

    if ($depositoId === '' || strtolower($depositoId) === 'geral') {
        $sql = sprintf(
            'SELECT %s AS idProduto, SUM(%s) AS saldo FROM estoque_local GROUP BY %s',
            $colunaIdProduto,
            $colunaSaldo,
            $colunaIdProduto
        );
        $result = $db->query($sql);
    } else {
        if (!$colunaIdDeposito) {
            echo json_encode([
                'ok' => false,
                'erro' => 'Tabela de estoque não possui coluna de depósito.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql = sprintf(
            'SELECT %s AS idProduto, %s AS saldo FROM estoque_local WHERE %s = :deposito',
            $colunaIdProduto,
            $colunaSaldo,
            $colunaIdDeposito
        );
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':deposito', $depositoId, SQLITE3_TEXT);
        $result = $stmt->execute();
    }

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $pid = $row['idProduto'];
            if ($pid === null) {
                continue;
            }
            $estoque[$pid] = (float)($row['saldo'] ?? 0);
        }
    }

    echo json_encode([
        'ok' => true,
        'estoque' => $estoque,
        'depositoId' => ($depositoId === '' ? 'geral' : $depositoId)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
