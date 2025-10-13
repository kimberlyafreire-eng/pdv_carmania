<?php
/**
 * contatos-helper.php
 * Utilitário para sincronizar e consultar tipos de contato do Bling.
 */

require_once __DIR__ . '/token-helper.php';

const CONTACT_TYPES_CACHE = __DIR__ . '/../../cache/tipos-contato-cache.json';
const CONTACT_TYPES_CACHE_TTL = 43200; // 12 horas por padrão

/**
 * Retorna o caminho absoluto do cache de tipos de contato.
 */
function getContactTypesCachePath(): string
{
    return CONTACT_TYPES_CACHE;
}

/**
 * Carrega os tipos de contato do cache, quando disponível.
 *
 * @return array|null
 */
function loadContactTypesFromCache(): ?array
{
    $cachePath = getContactTypesCachePath();
    if (!file_exists($cachePath)) {
        return null;
    }

    $conteudo = file_get_contents($cachePath);
    if ($conteudo === false) {
        return null;
    }

    $json = json_decode($conteudo, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $json;
}

/**
 * Salva os tipos de contato no cache local.
 */
function saveContactTypesToCache(array $dados): bool
{
    $cachePath = getContactTypesCachePath();

    if (!is_dir(dirname($cachePath))) {
        mkdir(dirname($cachePath), 0777, true);
    }

    $payload = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        return false;
    }

    return file_put_contents($cachePath, $payload, LOCK_EX) !== false;
}

/**
 * Consulta os tipos de contato diretamente na API do Bling.
 *
 * @throws RuntimeException quando não for possível obter os dados.
 */
function fetchContactTypesFromBling(): array
{
    $accessToken = getAccessToken();
    if (!$accessToken) {
        throw new RuntimeException('Não foi possível obter o token de acesso do Bling.');
    }

    $url = 'https://www.bling.com.br/Api/v3/contatos/tipos';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$accessToken}",
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $resposta = curl_exec($ch);
    if ($resposta === false) {
        $erro = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Falha ao consultar os tipos de contato no Bling: ' . $erro);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException('Erro ao consultar os tipos de contato no Bling. Código HTTP: ' . $httpCode . ' Resposta: ' . $resposta);
    }

    $json = json_decode($resposta, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Resposta inválida ao consultar os tipos de contato no Bling.');
    }

    return $json;
}

/**
 * Garante que exista um cache atualizado dos tipos de contato.
 *
 * @param bool $forceRefresh Força a atualização do cache
 *
 * @throws RuntimeException
 */
function ensureContactTypesCache(bool $forceRefresh = false): array
{
    $cachePath = getContactTypesCachePath();

    if (!$forceRefresh && file_exists($cachePath)) {
        $idade = time() - filemtime($cachePath);
        if ($idade < CONTACT_TYPES_CACHE_TTL) {
            $dadosCache = loadContactTypesFromCache();
            if (is_array($dadosCache)) {
                return $dadosCache;
            }
        }
    }

    $dados = fetchContactTypesFromBling();
    if (!saveContactTypesToCache($dados)) {
        throw new RuntimeException('Não foi possível salvar o cache de tipos de contato.');
    }

    return $dados;
}

/**
 * Procura o ID de um tipo de contato a partir da descrição.
 */
function findContactTypeId(array $tipos, string $descricao): ?int
{
    $descricao = mb_strtolower(trim($descricao));

    $lista = $tipos['data'] ?? $tipos;
    if (!is_array($lista)) {
        return null;
    }

    foreach ($lista as $tipo) {
        if (!is_array($tipo)) {
            continue;
        }

        $descAtual = mb_strtolower($tipo['descricao'] ?? '');
        if ($descAtual === $descricao) {
            $id = $tipo['id'] ?? null;
            return is_numeric($id) ? (int)$id : null;
        }
    }

    return null;
}
