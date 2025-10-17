<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

// ✅ Carrega o token automaticamente e renova se necessário
require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/clientes-db.php';

$caminhoCache = __DIR__ . '/../cache/clientes-cache.json';

$forcarAtualizacao = false;
if (isset($_GET['refresh'])) {
    $valorRefresh = strtolower(trim((string) $_GET['refresh']));
    $forcarAtualizacao = !in_array($valorRefresh, ['0', 'false', 'nao', 'não', 'no', ''], true);
}

$db = null;
$clientesLocal = [];

try {
    $db = getClientesDb();
    importarClientesCache($db, $caminhoCache);
    if (!$forcarAtualizacao) {
        $clientesLocal = buscarClientesLocalmente($db);
        if (!empty($clientesLocal)) {
            echo json_encode(['data' => $clientesLocal], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $db->close();
            exit();
        }
    }
} catch (Throwable $e) {
    error_log('[clientes.php] Falha ao acessar banco local: ' . $e->getMessage());
    $db = null;
}

$accessToken = getAccessToken();
if(!$accessToken){
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao obter token do Bling"]);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
}

function consultarPaginaContatos(int $pagina, int $limite, string $accessToken): array
{
    $query = http_build_query([
        'pagina' => $pagina,
        'limite' => $limite,
    ], '', '&', PHP_QUERY_RFC3986);

    $url = "https://www.bling.com.br/Api/v3/contatos?$query";

    $tentativas = 0;
    $tentativasMaximas = 5;
    $atraso = 1;

    while ($tentativas < $tentativasMaximas) {
        $tentativas++;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $accessToken",
        ]);

        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erroCurl = curl_error($ch);
            curl_close($ch);

            return [
                'erro' => true,
                'status' => 500,
                'mensagem' => 'Falha na comunicação com o Bling',
                'detalhes' => $erroCurl,
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamanhoCabecalho = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $cabecalhoBruto = substr($resposta, 0, $tamanhoCabecalho);
        $corpo = substr($resposta, $tamanhoCabecalho);
        curl_close($ch);

        if ($httpCode === 429 || ($httpCode >= 500 && $httpCode < 600)) {
            $retryAfter = null;
            $linhasCabecalho = explode("\r\n", $cabecalhoBruto);
            foreach ($linhasCabecalho as $linha) {
                if (stripos($linha, 'Retry-After:') === 0) {
                    $valor = trim(substr($linha, strlen('Retry-After:')));
                    if (is_numeric($valor)) {
                        $retryAfter = (int) $valor;
                    }
                    break;
                }
            }

            $espera = $retryAfter !== null ? max(1, $retryAfter) : $atraso;
            sleep($espera);
            $atraso = min($atraso * 2, 30);
            continue;
        }

        if ($httpCode !== 200) {
            return [
                'erro' => true,
                'status' => $httpCode,
                'mensagem' => 'Erro ao consultar Bling',
                'detalhes' => $corpo,
            ];
        }

        $dados = json_decode($corpo, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'erro' => true,
                'status' => 500,
                'mensagem' => 'Resposta inválida do Bling',
            ];
        }

        return [
            'erro' => false,
            'dados' => $dados,
        ];
    }

    return [
        'erro' => true,
        'status' => 429,
        'mensagem' => 'Limite de tentativas ao consultar o Bling excedido',
    ];
}

$pagina = 1;
$limite = 100;
$todosClientes = [];

while (true) {
    $resultado = consultarPaginaContatos($pagina, $limite, $accessToken);

    if ($resultado['erro']) {
        http_response_code($resultado['status']);
        echo json_encode([
            'erro' => $resultado['mensagem'],
            'detalhes' => $resultado['detalhes'] ?? null,
        ]);
        if ($db instanceof SQLite3) {
            $db->close();
        }
        exit();
    }

    $dados = $resultado['dados'];

    $clientes = isset($dados['data']) && is_array($dados['data']) ? $dados['data'] : [];

    $todosClientes = array_merge($todosClientes, $clientes);

    $meta = isset($dados['page']) && is_array($dados['page']) ? $dados['page'] : [];
    $totalPaginas = isset($meta['totalPages']) ? (int) $meta['totalPages'] : null;
    $paginaAtual = isset($meta['number']) ? (int) $meta['number'] : $pagina;
    $temProxima = isset($meta['hasNext']) ? (bool) $meta['hasNext'] : null;

    if ($totalPaginas !== null && $paginaAtual >= $totalPaginas) {
        break;
    }

    if ($temProxima === false) {
        break;
    }

    if (count($clientes) < $limite) {
        break;
    }

    $pagina++;
}

// Normaliza os clientes para evitar dados quebrados no front-end.
$clientesNormalizados = [];
foreach ($todosClientes as $clienteBruto) {
    if (!is_array($clienteBruto)) {
        continue;
    }
    $clienteNormalizado = normalizarClienteParaResposta($clienteBruto);
    if ($clienteNormalizado === null) {
        continue;
    }
    $clientesNormalizados[$clienteNormalizado['id']] = $clienteNormalizado;
}

$clientesNormalizados = array_values($clientesNormalizados);
usort($clientesNormalizados, static function (array $a, array $b): int {
    return strcasecmp($a['nome'], $b['nome']);
});

// Atualiza o cache JSON com os dados normalizados.
$cacheJson = json_encode(['data' => $clientesNormalizados], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($cacheJson === false) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao preparar cache de clientes"]);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
}

if (file_put_contents($caminhoCache, $cacheJson, LOCK_EX) === false) {
    error_log('[clientes.php] Não foi possível gravar o clientes-cache.json.');
}

$dadosResposta = ['data' => $clientesNormalizados];

if ($db instanceof SQLite3) {
    try {
        upsertClientes($db, $todosClientes);
        removerClientesForaDaLista($db, array_column($clientesNormalizados, 'id'));
        $clientesLocal = buscarClientesLocalmente($db);
        if (!empty($clientesLocal)) {
            $dadosResposta = ['data' => $clientesLocal];
        }
    } catch (Throwable $e) {
        error_log('[clientes.php] Falha ao sincronizar banco local: ' . $e->getMessage());
    }
}

if (empty($dadosResposta['data'])) {
    $dadosResposta = ['data' => $clientesNormalizados];
}

$payload = json_encode($dadosResposta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($payload === false) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao preparar resposta de clientes"]);
} else {
    echo $payload;
}

if ($db instanceof SQLite3) {
    $db->close();
}
?>

