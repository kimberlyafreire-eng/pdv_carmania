<?php
header('Content-Type: application/json; charset=utf-8');

// ❌ Removido o refresh-token.php (não precisa de autenticação Bling)

$dbFile = __DIR__ . '/../db/produtos.db';

if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode(['erro' => 'Banco de dados não encontrado.']);
    exit;
}

$baseImagemDir = realpath(__DIR__ . '/../imagens') ?: __DIR__ . '/../imagens';
$baseImagemUrl = '/pdv_carmania/imagens';

if (!function_exists('resolverImagemProduto')) {
    function resolverImagemProduto(array $row, string $baseDir, string $baseUrl): string
    {
        $idProduto = $row['id'];
        $imagemLocal = $row['imagem_local'];

        $baseDirNormalizado = rtrim(str_replace('\\', '/', $baseDir), '/');
        $baseUrlNormalizado = rtrim($baseUrl, '/');

        $normalizarCaminho = static function (string $path) use ($baseDirNormalizado, $baseUrlNormalizado): string {
            $caminhoNormalizado = str_replace('\\', '/', $path);
            if (strpos($caminhoNormalizado, $baseDirNormalizado . '/') === 0) {
                $relativo = substr($caminhoNormalizado, strlen($baseDirNormalizado) + 1) ?: '';
            } else {
                $relativo = ltrim($caminhoNormalizado, '/');
            }

            return $baseUrlNormalizado . '/' . ltrim($relativo, '/');
        };

        if (!empty($imagemLocal)) {
            $caminho = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $imagemLocal;
            if (file_exists($caminho)) {
                return $normalizarCaminho($caminho);
            }
        }

        $pastaProduto = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $idProduto;
        if (is_dir($pastaProduto)) {
            $padroes = ['*.jpg', '*.jpeg', '*.png', '*.webp', '*.gif'];
            $arquivos = [];
            foreach ($padroes as $padrao) {
                $encontrados = glob($pastaProduto . DIRECTORY_SEPARATOR . $padrao, GLOB_NOSORT) ?: [];
                $arquivos = array_merge($arquivos, $encontrados);
            }

            if (!empty($arquivos)) {
                sort($arquivos);
                return $normalizarCaminho($arquivos[0]);
            }
        }

        return $baseUrlNormalizado . '/sem-imagem.png';
    }
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
            'imagemURL' => resolverImagemProduto($row, $baseImagemDir, $baseImagemUrl)
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
