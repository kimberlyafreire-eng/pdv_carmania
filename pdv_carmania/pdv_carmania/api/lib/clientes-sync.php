<?php
declare(strict_types=1);

require_once __DIR__ . '/clientes-db.php';

/**
 * Consulta uma página de contatos no Bling.
 *
 * @throws RuntimeException Quando ocorre falha na comunicação ou resposta inválida.
 */
function consultarPaginaContatosBling(int $pagina, int $limite, string $accessToken): array
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
            throw new RuntimeException('Falha na comunicação com o Bling: ' . $erroCurl, 500);
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
            throw new RuntimeException('Erro ao consultar Bling (HTTP ' . $httpCode . ').', $httpCode);
        }

        $dados = json_decode($corpo, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Resposta inválida do Bling.', 500);
        }

        return $dados;
    }

    throw new RuntimeException('Limite de tentativas ao consultar o Bling excedido.', 429);
}

/**
 * Sincroniza os clientes do Bling com o banco local e o cache.
 *
 * @return array{
 *     clientesBrutos: array<int, mixed>,
 *     clientesNormalizados: array<int, array<string, mixed>>,
 *     clientesLocais: array<int, array<string, mixed>>,
 *     idsInseridos: array<int, string>,
 *     idsRemovidos: array<int, string>
 * }
 *
 * @throws RuntimeException Quando ocorre falha durante a sincronização.
 */
function sincronizarClientesComBling(string $accessToken, ?SQLite3 $db, string $cachePath): array
{
    $pagina = 1;
    $limite = 100;
    $todosClientes = [];

    while (true) {
        $dados = consultarPaginaContatosBling($pagina, $limite, $accessToken);
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

    $cachePayload = json_encode(['data' => $clientesNormalizados], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($cachePayload === false) {
        throw new RuntimeException('Falha ao preparar cache de clientes.', 500);
    }

    if (file_put_contents($cachePath, $cachePayload, LOCK_EX) === false) {
        error_log('[clientes-sync] Não foi possível gravar o clientes-cache.json.');
    }

    $idsAntes = [];
    if ($db instanceof SQLite3) {
        $resultadoIds = $db->query('SELECT id FROM clientes');
        if ($resultadoIds instanceof SQLite3Result) {
            while ($linha = $resultadoIds->fetchArray(SQLITE3_ASSOC)) {
                if (isset($linha['id'])) {
                    $idsAntes[(string) $linha['id']] = true;
                }
            }
            $resultadoIds->finalize();
        }
    }

    $clientesLocais = [];
    if ($db instanceof SQLite3) {
        upsertClientes($db, $todosClientes);
        $idsRemotos = array_column($clientesNormalizados, 'id');
        removerClientesForaDaLista($db, $idsRemotos);
        $clientesLocais = buscarClientesLocalmente($db);
    }

    $idsRemotosAssociativos = [];
    foreach ($clientesNormalizados as $cliente) {
        $idsRemotosAssociativos[(string) $cliente['id']] = true;
    }

    $idsInseridos = [];
    $idsRemovidos = [];
    if ($db instanceof SQLite3) {
        $idsInseridos = array_keys(array_diff_key($idsRemotosAssociativos, $idsAntes));
        $idsRemovidos = array_keys(array_diff_key($idsAntes, $idsRemotosAssociativos));
    }

    return [
        'clientesBrutos' => $todosClientes,
        'clientesNormalizados' => $clientesNormalizados,
        'clientesLocais' => $clientesLocais,
        'idsInseridos' => $idsInseridos,
        'idsRemovidos' => $idsRemovidos,
    ];
}
