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
        'with' => 'enderecos',
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

function valorClienteVazio($valor): bool
{
    if ($valor === null) {
        return true;
    }

    if (is_string($valor)) {
        return trim($valor) === '';
    }

    if (is_array($valor)) {
        if ($valor === []) {
            return true;
        }

        foreach ($valor as $item) {
            if (!valorClienteVazio($item)) {
                return false;
            }
        }

        return true;
    }

    return false;
}

function mesclarEstruturaCliente(array $destino, array $fonte): array
{
    foreach ($fonte as $chave => $valorFonte) {
        if (!array_key_exists($chave, $destino)) {
            $destino[$chave] = $valorFonte;
            continue;
        }

        $valorDestino = $destino[$chave];

        if (is_array($valorDestino) && is_array($valorFonte)) {
            $destino[$chave] = mesclarEstruturaClienteArray($valorDestino, $valorFonte);
            continue;
        }

        if (valorClienteVazio($valorDestino) && !valorClienteVazio($valorFonte)) {
            $destino[$chave] = $valorFonte;
        }
    }

    return $destino;
}

function mesclarEstruturaClienteArray(array $destino, array $fonte): array
{
    $destinoSequencial = array_keys($destino) === range(0, count($destino) - 1);
    $fonteSequencial = array_keys($fonte) === range(0, count($fonte) - 1);

    if ($destinoSequencial && $fonteSequencial) {
        if (empty($destino) && !empty($fonte)) {
            return $fonte;
        }

        return $destino;
    }

    foreach ($fonte as $chave => $valorFonte) {
        if (!array_key_exists($chave, $destino)) {
            $destino[$chave] = $valorFonte;
            continue;
        }

        if (valorClienteVazio($destino[$chave]) && !valorClienteVazio($valorFonte)) {
            $destino[$chave] = $valorFonte;
            continue;
        }

        if (is_array($destino[$chave]) && is_array($valorFonte)) {
            $destino[$chave] = mesclarEstruturaClienteArray($destino[$chave], $valorFonte);
        }
    }

    return $destino;
}

function consultarContatoIndividual(string $idContato, string $accessToken): ?array
{
    $idNormalizado = trim($idContato);
    if ($idNormalizado === '') {
        return null;
    }

    $query = http_build_query([
        'with' => 'enderecos',
    ], '', '&', PHP_QUERY_RFC3986);

    $url = 'https://www.bling.com.br/Api/v3/contatos/' . rawurlencode($idNormalizado);
    if ($query !== '') {
        $url .= '?' . $query;
    }

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
            'Authorization: Bearer ' . $accessToken,
        ]);

        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erroCurl = curl_error($ch);
            curl_close($ch);
            error_log('[clientes.php] Falha ao consultar contato individual ' . $idNormalizado . ': ' . $erroCurl);
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamanhoCabecalho = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $cabecalhoBruto = substr($resposta, 0, $tamanhoCabecalho);
        $corpo = substr($resposta, $tamanhoCabecalho);
        curl_close($ch);

        if ($httpCode === 404) {
            return null;
        }

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
            error_log('[clientes.php] Erro ao consultar contato individual ' . $idNormalizado . ': ' . $corpo);
            return null;
        }

        $dados = json_decode($corpo, true);
        if (!is_array($dados) || !isset($dados['data']) || !is_array($dados['data'])) {
            error_log('[clientes.php] Resposta inesperada ao consultar contato individual ' . $idNormalizado . '.');
            return null;
        }

        return $dados['data'];
    }

    return null;
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
$clientesCompletos = [];
foreach ($todosClientes as $clienteBruto) {
    if (!is_array($clienteBruto)) {
        continue;
    }

    $clienteCompleto = $clienteBruto;

    if (empty(extrairEnderecoPrincipal($clienteCompleto)) && $db instanceof SQLite3) {
        $clienteLocal = buscarClienteLocalPorId($db, (string) ($clienteBruto['id'] ?? ''));
        if (is_array($clienteLocal)) {
            $clienteCompleto = mesclarEstruturaCliente($clienteCompleto, $clienteLocal);
        }
    }

    if (empty(extrairEnderecoPrincipal($clienteCompleto))) {
        $detalhes = consultarContatoIndividual((string) ($clienteBruto['id'] ?? ''), $accessToken);
        if (is_array($detalhes)) {
            $clienteCompleto = mesclarEstruturaCliente($clienteCompleto, $detalhes);
        }
    }

    $clienteNormalizado = normalizarClienteParaResposta($clienteCompleto);
    if ($clienteNormalizado === null) {
        continue;
    }
    $clientesNormalizados[$clienteNormalizado['id']] = $clienteNormalizado;
    $clientesCompletos[$clienteNormalizado['id']] = $clienteCompleto;
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
        $clientesParaPersistir = array_values($clientesCompletos);
        upsertClientes($db, $clientesParaPersistir);
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

