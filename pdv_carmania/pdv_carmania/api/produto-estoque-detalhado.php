<?php
header('Content-Type: application/json; charset=utf-8');

$idProduto = isset($_GET['idProduto']) ? trim((string) $_GET['idProduto']) : '';
if ($idProduto === '') {
    echo json_encode([
        'ok' => false,
        'erro' => 'Produto não informado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dbFile = __DIR__ . '/../db/produtos.db';
if (!file_exists($dbFile)) {
    echo json_encode([
        'ok' => false,
        'erro' => 'Banco de dados não encontrado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
            'produtoId' => $idProduto,
            'possuiDepositos' => false,
            'depositos' => [],
            'total' => 0.0,
            'mensagem' => 'Tabela de estoque não encontrada.'
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

    $possuiDepositos = $colunaIdDeposito !== null;
    $depositos = [];
    $total = 0.0;

    if ($possuiDepositos) {
        $sql = sprintf(
            'SELECT %s AS deposito, SUM(%s) AS saldo FROM estoque_local WHERE %s = :produto GROUP BY %s',
            $colunaIdDeposito,
            $colunaSaldo,
            $colunaIdProduto,
            $colunaIdDeposito
        );
    } else {
        $sql = sprintf(
            'SELECT SUM(%s) AS saldo FROM estoque_local WHERE %s = :produto',
            $colunaSaldo,
            $colunaIdProduto
        );
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta de estoque.');
    }
    $stmt->bindValue(':produto', $idProduto, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $saldo = (float) ($row['saldo'] ?? 0);
            $total += $saldo;

            if ($possuiDepositos) {
                $depositos[] = [
                    'idDeposito' => $row['deposito'] === null ? null : (string) $row['deposito'],
                    'saldo' => $saldo
                ];
            }
        }

        if (!$possuiDepositos) {
            // Quando não há coluna de depósito, retornamos o total consolidado.
            $depositos[] = [
                'idDeposito' => null,
                'saldo' => $total
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'produtoId' => $idProduto,
        'possuiDepositos' => $possuiDepositos,
        'depositos' => $depositos,
        'total' => $total
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
