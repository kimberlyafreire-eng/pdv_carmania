<?php
declare(strict_types=1);

require_once __DIR__ . '/clientes-db.php';

/**
 * Consulta uma página de contatos no Bling aplicando política de retry.
 *
 * @throws RuntimeException Quando não é possível obter os dados solicitados.
 */
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
            throw new RuntimeException('Falha na comunicação com o Bling: ' . $erroCurl);
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
            throw new RuntimeException('Erro ao consultar Bling: ' . $corpo, $httpCode);
        }

        $dados = json_decode($corpo, true);
        if (!is_array($dados)) {
            throw new RuntimeException('Resposta inválida do Bling.');
        }

        return $dados;
    }

    throw new RuntimeException('Limite de tentativas ao consultar o Bling excedido.');
}

/**
 * Sincroniza os clientes do Bling com o banco e cache locais.
 *
 * @return array Lista de clientes normalizados para retorno ao front-end.
 *
 * @throws RuntimeException Quando a sincronização falha.
 */
function sincronizarClientesComBling(SQLite3 $db, string $accessToken, string $cachePath): array
{
    $pagina = 1;
    $limite = 100;
    $todosClientes = [];

    while (true) {
        $dados = consultarPaginaContatos($pagina, $limite, $accessToken);

        $clientesPagina = [];
        if (isset($dados['data']) && is_array($dados['data'])) {
            $clientesPagina = $dados['data'];
        }

        if (!empty($clientesPagina)) {
            $todosClientes = array_merge($todosClientes, $clientesPagina);
        }

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

        if (empty($clientesPagina) || count($clientesPagina) < $limite) {
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
    if (!empty($clientesNormalizados)) {
        usort($clientesNormalizados, static function (array $a, array $b): int {
            $nomeA = isset($a['nome']) ? (string) $a['nome'] : '';
            $nomeB = isset($b['nome']) ? (string) $b['nome'] : '';
            return strcasecmp($nomeA, $nomeB);
        });
    }

    $cacheJson = json_encode(['data' => $clientesNormalizados], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($cacheJson !== false) {
        if (file_put_contents($cachePath, $cacheJson, LOCK_EX) === false) {
            error_log('[clientes-sync] Não foi possível gravar o clientes-cache.json.');
        }
    }

    if (!empty($todosClientes)) {
        upsertClientes($db, $todosClientes);
        removerClientesForaDaLista($db, array_column($clientesNormalizados, 'id'));
    }

    $clientesLocal = buscarClientesLocalmente($db);
    if (!empty($clientesLocal)) {
        return $clientesLocal;
    }

    return $clientesNormalizados;
}

