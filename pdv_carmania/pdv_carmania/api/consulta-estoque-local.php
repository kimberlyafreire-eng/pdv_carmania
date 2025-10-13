<?php
header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/../logs/consulta-estoque.log';

try {
       $input = json_decode(file_get_contents('php://input'), true);
    $depositoId = $input['depositoId'] ?? null;
    $produtos = $input['produtos'] ?? [];

    if (!$depositoId || empty($produtos)) {
        echo json_encode(['ok' => false, 'erro' => 'Parâmetros inválidos']);
        exit;
    }

    $dbFile = __DIR__ . '/../db/produtos.db';
    if (!file_exists($dbFile)) {
        echo json_encode(['ok' => false, 'erro' => 'Banco de dados não encontrado']);
        exit;
    }

    $db = new SQLite3($dbFile);
    $estoques = [];

    // monta lista de IDs segura para o IN()
    $ids = array_map('intval', $produtos);
    $idsList = implode(',', $ids);

    $sql = "SELECT idProduto, saldo 
            FROM estoque_local 
            WHERE idDeposito = :dep 
              AND idProduto IN ($idsList)";
              
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':dep', $depositoId, SQLITE3_INTEGER);

    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $estoques[$row['idProduto']] = (float)$row['saldo'];
    }

    echo json_encode(['ok' => true, 'estoques' => $estoques], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    logMsg("💥 Erro: " . $e->getMessage());
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
