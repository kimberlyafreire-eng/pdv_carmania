<?php
header('Content-Type: application/json; charset=utf-8');

// ❌ Removido o refresh-token.php (não precisa de autenticação Bling)

$dbFile = __DIR__ . '/../db/produtos.db';

if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['erro' => 'Banco de dados não encontrado.']);
    exit;
}

try {
    $db = new SQLite3($dbFile);

    $result = $db->query("SELECT id, codigo, nome, preco, imagem_local FROM produtos");

    $produtos = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $produtos[] = [
            'id' => $row['id'],
            'codigo' => $row['codigo'],
            'nome' => $row['nome'],
            'preco' => (float) $row['preco'],
            'imagemURL' => $row['imagem_local']
                ? "/pdv_carmania/imagens/" . $row['imagem_local']
                : "/pdv_carmania/imagens/sem-imagem.png"
        ];
    }

    echo json_encode([
        'total' => count($produtos),
        'data'  => $produtos
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Erro ao consultar banco de dados',
        'detalhe' => $e->getMessage()
    ]);
}
