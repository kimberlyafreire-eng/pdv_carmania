<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'erro' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/lib/token-helper.php';

set_time_limit(0);

$dbFile = __DIR__ . '/../db/produtos.db';

if (!file_exists($dbFile)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'Banco de dados local de produtos não encontrado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = new SQLite3($dbFile);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'Não foi possível abrir o banco de dados local.',
        'detalhe' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

const BLING_BASE = 'https://bling.com.br/Api/v3';
$paginaLimite = 100;

/**
 * @return array{0:int,1:string}
 */
function chamarApiBling(string $url, string $contexto, int $tentativa = 0): array
{
    $token = getAccessToken();
    if (!$token) {
        if ($tentativa === 0 && refreshAccessToken()) {
            $token = getAccessToken();
        }

        if (!$token) {
            throw new RuntimeException('Token de acesso indisponível para consultar o Bling.');
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 45,
    ]);

    $body = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Falha de comunicação com o Bling ao {$contexto}: " . ($erroCurl ?: 'erro desconhecido'));
    }

    if ($httpcode === 401 && $tentativa === 0) {
        if (refreshAccessToken()) {
            return chamarApiBling($url, $contexto, 1);
        }
        throw new RuntimeException('Token inválido ao consultar o Bling e não foi possível renovar.');
    }

    if ($httpcode === 429 && $tentativa < 5) {
        $espera = min(60, ($tentativa + 1) * 5);
        sleep($espera);
        return chamarApiBling($url, $contexto, $tentativa + 1);
    }

    if ($httpcode >= 500 && $httpcode < 600 && $tentativa < 4) {
        $espera = max(5, ($tentativa + 1) * 5);
        sleep($espera);
        return chamarApiBling($url, $contexto, $tentativa + 1);
    }

    if ($httpcode < 200 || $httpcode >= 300) {
        throw new RuntimeException("Erro {$httpcode} ao {$contexto}.");
    }

    return [$httpcode, $body];
}

/**
 * @return array<int, array<string, mixed>>
 */
function obterProdutosRemotos(int $paginaLimite): array
{
    $pagina = 1;
    $todos = [];

    do {
        $url = rtrim(BLING_BASE, '/') . "/produtos?pagina={$pagina}&limite={$paginaLimite}";
        [, $body] = chamarApiBling($url, "listar produtos (página {$pagina})");

        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Resposta inválida ao listar produtos do Bling.');
        }

        $dados = $json['data'] ?? [];
        if (!is_array($dados) || empty($dados)) {
            break;
        }

        $todos = array_merge($todos, $dados);

        if (count($dados) < $paginaLimite) {
            break;
        }

        $pagina++;
    } while (true);

    return $todos;
}

/**
 * @return array<string, bool>
 */
function listarIdsLocais(SQLite3 $db): array
{
    $ids = [];
    $resultado = $db->query('SELECT id FROM produtos');
    while ($resultado && ($row = $resultado->fetchArray(SQLITE3_ASSOC))) {
        if (!isset($row['id'])) {
            continue;
        }
        $id = (string) $row['id'];
        if ($id === '') {
            continue;
        }
        $ids[$id] = true;
    }

    return $ids;
}

function descobrirColunaEstoque(SQLite3 $db): ?string
{
    $info = $db->query('PRAGMA table_info(estoque_local)');
    if (!$info) {
        return null;
    }

    $possiveis = ['idProduto', 'produto_id', 'id_produto'];
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        $nome = $row['name'] ?? null;
        if (!$nome) {
            continue;
        }
        if (in_array($nome, $possiveis, true)) {
            return $nome;
        }
    }

    return null;
}

function removerProdutoLocal(SQLite3 $db, string $idProduto, ?string $colunaEstoque): bool
{
    $db->exec('BEGIN');
    try {
        $stmt = $db->prepare('DELETE FROM produtos WHERE id = :id');
        $stmt->bindValue(':id', $idProduto, SQLITE3_TEXT);
        $stmt->execute();

        if ($db->changes() === 0) {
            $db->exec('ROLLBACK');
            return false;
        }

        if ($colunaEstoque) {
            $sqlEstoque = sprintf('DELETE FROM estoque_local WHERE %s = :id', $colunaEstoque);
            $stmtEstoque = $db->prepare($sqlEstoque);
            $stmtEstoque->bindValue(':id', $idProduto, SQLITE3_TEXT);
            $stmtEstoque->execute();
        }

        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

try {
    $produtosRemotos = obterProdutosRemotos($paginaLimite);

    $idsRemotos = [];
    $idsInativos = [];
    foreach ($produtosRemotos as $produto) {
        if (!is_array($produto)) {
            continue;
        }
        $id = (string) ($produto['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $idsRemotos[$id] = true;

        $situacao = strtoupper(trim((string)($produto['situacao'] ?? '')));
        if ($situacao !== '' && $situacao !== 'A') {
            $idsInativos[$id] = true;
        }
    }

    $idsLocais = listarIdsLocais($db);
    $colunaEstoque = descobrirColunaEstoque($db);

    $removidosAusentes = 0;
    $removidosInativos = 0;

    foreach ($idsLocais as $idLocal => $_) {
        $idLocalStr = (string) $idLocal;
        if (!isset($idsRemotos[$idLocalStr])) {
            if (removerProdutoLocal($db, $idLocalStr, $colunaEstoque)) {
                $removidosAusentes++;
            }
        }
    }

    foreach ($idsInativos as $idInativo => $_) {
        $idInativoStr = (string) $idInativo;
        if (!isset($idsLocais[$idInativoStr])) {
            continue;
        }

        if (removerProdutoLocal($db, $idInativoStr, $colunaEstoque)) {
            $removidosInativos++;
        }
    }

    $phpBinary = PHP_BINARY ?: 'php';
    $scriptPath = __DIR__ . '/atualizar-produtos-db.php';
    $command = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($scriptPath);
    $outputLinhas = [];
    $exitCode = 0;

    chdir(__DIR__);
    exec($command . ' 2>&1', $outputLinhas, $exitCode);

    $logResumo = implode("\n", array_slice($outputLinhas, -50));

    echo json_encode([
        'ok' => true,
        'mensagem' => 'Sincronização concluída com sucesso.',
        'resumo' => sprintf(
            'Sincronização finalizada. Removidos %d produtos ausentes e %d produtos inativos. Atualização do cadastro retornou código %d.',
            $removidosAusentes,
            $removidosInativos,
            $exitCode
        ),
        'removidos' => [
            'ausentes' => $removidosAusentes,
            'inativos' => $removidosInativos,
        ],
        'atualizacao' => [
            'codigoSaida' => $exitCode,
            'log' => $logResumo,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

