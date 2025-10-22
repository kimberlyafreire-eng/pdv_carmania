<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/token-helper.php';

$input = json_decode(file_get_contents('php://input'), true);
$acao = isset($input['acao']) ? trim((string)$input['acao']) : '';
$idProduto = isset($input['idProduto']) ? trim((string)$input['idProduto']) : '';

if ($idProduto === '' || $acao === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'erro' => 'Parâmetros obrigatórios ausentes.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dbFile = __DIR__ . '/../db/produtos.db';
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'Banco de dados não encontrado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function buscarProdutoBling(string $idProduto, int $tentativa = 0)
{
    $idSanitizado = rawurlencode($idProduto);
    $url = "https://bling.com.br/Api/v3/produtos/{$idSanitizado}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . getAccessToken(),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $resposta = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErro = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 401 && $tentativa === 0) {
        if (refreshAccessToken()) {
            return buscarProdutoBling($idProduto, 1);
        }
    }

    if ($resposta === false) {
        throw new RuntimeException('Falha de comunicação com a API do Bling: ' . ($curlErro ?: 'erro desconhecido'));
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("Erro ao consultar produto no Bling (HTTP {$httpCode})");
    }

    $json = json_decode($resposta, true);
    if (!is_array($json)) {
        throw new RuntimeException('Retorno inválido da API do Bling.');
    }

    return $json['data'] ?? $json;
}

function extrairGtinProduto(array $dados): ?string
{
    $possiveis = [
        $dados['gtin'] ?? null,
        $dados['gtinEmbalagem'] ?? null,
    ];

    foreach ($possiveis as $valor) {
        $gtin = trim((string)$valor);
        if ($gtin !== '') {
            return $gtin;
        }
    }

    if (!empty($dados['variacoes']) && is_array($dados['variacoes'])) {
        foreach ($dados['variacoes'] as $variacao) {
            $possiveisVar = [
                $variacao['gtin'] ?? null,
                $variacao['gtinEmbalagem'] ?? null,
            ];
            foreach ($possiveisVar as $valor) {
                $gtin = trim((string)$valor);
                if ($gtin !== '') {
                    return $gtin;
                }
            }
        }
    }

    return null;
}

function garantirColunaGtin(SQLite3 $db): void
{
    static $verificado = false;
    if ($verificado) {
        return;
    }

    $colunas = [];
    $info = $db->query('PRAGMA table_info(produtos)');
    while ($info && ($row = $info->fetchArray(SQLITE3_ASSOC))) {
        $colunas[$row['name']] = true;
    }

    if (!isset($colunas['gtin'])) {
        $db->exec('ALTER TABLE produtos ADD COLUMN gtin TEXT');
    }

    $verificado = true;
}

try {
    $dadosProduto = buscarProdutoBling($idProduto);

    $db = new SQLite3($dbFile);

    switch ($acao) {
        case 'consultar_gtin':
            garantirColunaGtin($db);
            $gtin = extrairGtinProduto($dadosProduto);

            if ($gtin === null) {
                echo json_encode([
                    'ok' => true,
                    'mensagem' => 'Produto consultado, mas nenhum GTIN foi encontrado.',
                    'gtin' => null,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $db->prepare('UPDATE produtos SET gtin = :gtin WHERE id = :id');
            $stmt->bindValue(':gtin', $gtin, SQLITE3_TEXT);
            $stmt->bindValue(':id', $idProduto, SQLITE3_TEXT);
            $stmt->execute();

            echo json_encode([
                'ok' => true,
                'gtin' => $gtin,
                'mensagem' => 'GTIN atualizado com sucesso.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'excluir_local':
            $situacao = strtoupper(trim((string)($dadosProduto['situacao'] ?? '')));
            if ($situacao === 'A') {
                echo json_encode([
                    'ok' => false,
                    'erro' => 'Produto ativo no Bling. Não é permitido excluir localmente.'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $db->exec('BEGIN');
            try {
                $stmtProd = $db->prepare('DELETE FROM produtos WHERE id = :id');
                $stmtProd->bindValue(':id', $idProduto, SQLITE3_TEXT);
                $stmtProd->execute();

                $infoEstoque = $db->query('PRAGMA table_info(estoque_local)');
                $colunasEstoque = [];
                while ($infoEstoque && ($row = $infoEstoque->fetchArray(SQLITE3_ASSOC))) {
                    $colunasEstoque[$row['name']] = true;
                }

                $colunaIdProduto = null;
                foreach (['idProduto', 'produto_id', 'id_produto'] as $col) {
                    if (isset($colunasEstoque[$col])) {
                        $colunaIdProduto = $col;
                        break;
                    }
                }

                if ($colunaIdProduto) {
                    $sqlDeleteEstoque = sprintf('DELETE FROM estoque_local WHERE %s = :id', $colunaIdProduto);
                    $stmtEstoque = $db->prepare($sqlDeleteEstoque);
                    $stmtEstoque->bindValue(':id', $idProduto, SQLITE3_TEXT);
                    $stmtEstoque->execute();
                }

                $db->exec('COMMIT');
            } catch (Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }

            echo json_encode([
                'ok' => true,
                'mensagem' => 'Produto removido do banco local.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'erro' => 'Ação inválida.'
            ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
