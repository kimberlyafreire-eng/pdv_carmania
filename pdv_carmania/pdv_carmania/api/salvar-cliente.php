<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/contatos-helper.php';
require_once __DIR__ . '/lib/clientes-db.php';
require_once __DIR__ . '/lib/clientes-sync.php';

$dadosEntrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($dadosEntrada)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Payload inválido.']);
    exit();
}

$nome = trim($dadosEntrada['nome'] ?? '');
$erros = [];
$tipoPessoaEntrada = strtoupper(substr(trim($dadosEntrada['tipoPessoa'] ?? ''), 0, 1));
$tipoPessoa = 'F';
$contatoId = isset($dadosEntrada['id']) && $dadosEntrada['id'] !== '' ? trim((string)$dadosEntrada['id']) : null;
if ($tipoPessoaEntrada !== '') {
    if (in_array($tipoPessoaEntrada, ['F', 'J'], true)) {
        $tipoPessoa = $tipoPessoaEntrada;
    } else {
        $erros[] = 'Tipo de pessoa inválido. Escolha Física ou Jurídica.';
    }
}
$documento = preg_replace('/\D+/', '', $dadosEntrada['documento'] ?? '');
$celular = trim($dadosEntrada['celular'] ?? '');

if ($nome === '') {
    $erros[] = 'Nome completo é obrigatório.';
}
if ($celular === '') {
    $erros[] = 'Celular é obrigatório.';
}

if ($erros) {
    http_response_code(422);
    echo json_encode(['erro' => implode(' ', $erros)]);
    exit();
}

$cacheClientes = __DIR__ . '/../cache/clientes-cache.json';
$db = null;
$clienteExistente = null;
$accessToken = null;

try {
    $db = getClientesDb();
    importarClientesCache($db, $cacheClientes);

    $totalClientes = contarClientes($db);
    if ($totalClientes > 0) {
        $clienteExistente = encontrarClientePorCelular($db, $celular, $contatoId);
    }

    $deveSincronizar = $totalClientes === 0;
    if (!$deveSincronizar && $clienteExistente === null) {
        $ultimaAtualizacaoCache = is_file($cacheClientes) ? @filemtime($cacheClientes) : false;
        if ($ultimaAtualizacaoCache === false || $ultimaAtualizacaoCache < (time() - 600)) {
            $deveSincronizar = true;
        }
    }

    if ($clienteExistente === null && $deveSincronizar) {
        if ($accessToken === null) {
            $accessToken = getAccessToken();
        }
        if (!$accessToken) {
            throw new RuntimeException('Token de acesso inválido.');
        }

        sincronizarClientesComBling($db, $accessToken, $cacheClientes);
        $clienteExistente = encontrarClientePorCelular($db, $celular, $contatoId);
    }
} catch (Throwable $e) {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    error_log('[salvar-cliente.php] Falha ao validar celular duplicado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível validar o celular informado.']);
    exit();
}

if ($clienteExistente !== null) {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    http_response_code(409);
    echo json_encode(['erro' => 'Já tem cliente cadastrado com esse número de celular.']);
    exit();
}

try {
    $tiposContato = ensureContactTypesCache();
    $tipoClienteId = findContactTypeId($tiposContato, 'cliente');
    if (!$tipoClienteId) {
        throw new RuntimeException('Tipo de contato "Cliente" não encontrado no cache.');
    }
} catch (RuntimeException $e) {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
    exit();
}

$payload = [
    'nome' => $nome,
    'situacao' => 'A',
    'celular' => $celular,
    'tipo' => $tipoPessoa,
    'tiposContato' => [
        ['id' => $tipoClienteId],
    ],
];

if ($documento !== '') {
    $payload['numeroDocumento'] = $documento;
}

$telefone = trim($dadosEntrada['telefone'] ?? '');
if ($telefone !== '') {
    $payload['telefone'] = $telefone;
}

$fantasia = trim($dadosEntrada['fantasia'] ?? '');
if ($fantasia !== '') {
    $payload['fantasia'] = $fantasia;
}

$endereco = [];
$rua = trim($dadosEntrada['endereco'] ?? '');
if ($rua !== '') {
    $endereco['endereco'] = $rua;
}
$bairro = trim($dadosEntrada['bairro'] ?? '');
if ($bairro !== '') {
    $endereco['bairro'] = $bairro;
}
$cidade = trim($dadosEntrada['cidade'] ?? '');
if ($cidade !== '') {
    $endereco['municipio'] = $cidade;
}
$estado = strtoupper(trim($dadosEntrada['estado'] ?? ''));
if ($estado !== '') {
    $endereco['uf'] = $estado;
}
$cep = preg_replace('/\D+/', '', $dadosEntrada['cep'] ?? '');
if ($cep !== '') {
    if (strlen($cep) === 8) {
        $cep = substr($cep, 0, 5) . '-' . substr($cep, 5);
    }
    $endereco['cep'] = $cep;
}

if (!empty($endereco)) {
    $payload['endereco'] = ['geral' => $endereco];
}

$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($payloadJson === false) {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível preparar os dados para envio.']);
    exit();
}

if ($accessToken === null) {
    $accessToken = getAccessToken();
}
if (!$accessToken) {
    if ($db instanceof SQLite3) {
        $db->close();
    }
    http_response_code(500);
    echo json_encode(['erro' => 'Token de acesso inválido.']);
    exit();
}

$url = 'https://www.bling.com.br/Api/v3/contatos';
$metodo = 'POST';
if ($contatoId) {
    $url .= '/' . urlencode($contatoId);
    $metodo = 'PUT';
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$accessToken}",
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => $payloadJson,
    CURLOPT_TIMEOUT => 30,
]);

if ($metodo === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
} else {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
}

$resposta = curl_exec($ch);
if ($resposta === false) {
    $erro = curl_error($ch);
    curl_close($ch);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    http_response_code(500);
    echo json_encode(['erro' => 'Falha ao comunicar com o Bling: ' . $erro]);
    exit();
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$dadosResposta = json_decode($resposta, true);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode([
        'erro' => 'Não foi possível salvar o cliente.',
        'detalhes' => $dadosResposta ?? $resposta,
    ], JSON_UNESCAPED_UNICODE);
    if ($db instanceof SQLite3) {
        $db->close();
    }
    exit();
}

$clienteAtualizado = extrairClienteDosDados($dadosResposta);
$clienteIdResposta = extrairContatoIdDosDados($dadosResposta);
if ($clienteIdResposta === null && $contatoId !== null) {
    $clienteIdResposta = $contatoId;
}

if (($clienteAtualizado === null || !isset($clienteAtualizado['celular']) || trim((string) $clienteAtualizado['celular']) === '')
    && $clienteIdResposta !== null
) {
    $detalhesContato = buscarContatoPorId($accessToken, $clienteIdResposta);
    if (is_array($detalhesContato)) {
        $clienteAtualizado = $detalhesContato;
    }
}

if ($clienteAtualizado === null && $clienteIdResposta !== null) {
    $clienteAtualizado = [
        'id' => $clienteIdResposta,
    ];
}

if (is_array($clienteAtualizado)) {
    if (!isset($clienteAtualizado['id']) || trim((string) $clienteAtualizado['id']) === '') {
        if ($clienteIdResposta !== null) {
            $clienteAtualizado['id'] = $clienteIdResposta;
        } else {
            $clienteAtualizado = null;
        }
    }
}

$clienteNormalizado = null;

if (is_array($clienteAtualizado)) {
    if (!isset($clienteAtualizado['nome']) || trim((string) $clienteAtualizado['nome']) === '') {
        $clienteAtualizado['nome'] = $nome;
    }

    $clienteAtualizado['celular'] = $celular;

    if ($telefone !== '') {
        $clienteAtualizado['telefone'] = $telefone;
    }

    if ($fantasia !== '') {
        $clienteAtualizado['fantasia'] = $fantasia;
    }

    if ($documento !== '') {
        $clienteAtualizado['numeroDocumento'] = $documento;
    }

    $enderecoPayload = $payload['endereco']['geral'] ?? null;
    if (is_array($enderecoPayload) && !empty($enderecoPayload)) {
        if (!isset($clienteAtualizado['endereco']) || !is_array($clienteAtualizado['endereco'])) {
            $clienteAtualizado['endereco'] = [];
        }
        if (!isset($clienteAtualizado['endereco']['geral']) || !is_array($clienteAtualizado['endereco']['geral'])) {
            $clienteAtualizado['endereco']['geral'] = [];
        }
        $clienteAtualizado['endereco']['geral'] = array_merge($clienteAtualizado['endereco']['geral'], $enderecoPayload);
    }

    $clienteAtualizado['tipo'] = $tipoPessoa;
}

// Atualiza o cache local de clientes quando possível.
if (is_array($clienteAtualizado)) {
    $clienteNormalizado = normalizarClienteParaResposta($clienteAtualizado);
    $clientes = ['data' => []];

    if (file_exists($cacheClientes)) {
        $conteudoCache = file_get_contents($cacheClientes);
        if ($conteudoCache !== false) {
            $jsonCache = json_decode($conteudoCache, true);
            if (is_array($jsonCache)) {
                $listaCache = [];
                if (isset($jsonCache['data']) && is_array($jsonCache['data'])) {
                    $listaCache = $jsonCache['data'];
                } else {
                    foreach ($jsonCache as $valor) {
                        if (is_array($valor)) {
                            $listaCache[] = $valor;
                        }
                    }
                }

                $clientesNormalizados = [];
                foreach ($listaCache as $cliente) {
                    if (!is_array($cliente)) {
                        continue;
                    }
                    $normalizado = normalizarClienteParaResposta($cliente);
                    if ($normalizado === null) {
                        continue;
                    }
                    $clientesNormalizados[$normalizado['id']] = $normalizado;
                }

                if (!empty($clientesNormalizados)) {
                    $clientes['data'] = array_values($clientesNormalizados);
                }
            }
        }
    }

    $atualizado = false;
    if ($clienteNormalizado !== null) {
        foreach ($clientes['data'] as &$cliente) {
            if (isset($cliente['id']) && (string) $cliente['id'] === (string) $clienteNormalizado['id']) {
                $cliente = $clienteNormalizado;
                $atualizado = true;
                break;
            }
        }
        unset($cliente);

        if (!$atualizado) {
            $clientes['data'][] = $clienteNormalizado;
        }
    } elseif (isset($clienteAtualizado['id'])) {
        foreach ($clientes['data'] as &$cliente) {
            if (isset($cliente['id']) && (string) $cliente['id'] === (string) $clienteAtualizado['id']) {
                $cliente = $clienteAtualizado;
                $atualizado = true;
                break;
            }
        }
        unset($cliente);

        if (!$atualizado) {
            $clientes['data'][] = $clienteAtualizado;
        }
    }

    if (!empty($clientes['data'])) {
        usort($clientes['data'], static function (array $a, array $b): int {
            $nomeA = isset($a['nome']) ? (string) $a['nome'] : '';
            $nomeB = isset($b['nome']) ? (string) $b['nome'] : '';
            return strcasecmp($nomeA, $nomeB);
        });
    }

    $jsonFinal = json_encode($clientes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonFinal !== false) {
        $tmpFile = $cacheClientes . '.tmp';
        if (file_put_contents($tmpFile, $jsonFinal, LOCK_EX) !== false) {
            if (!@rename($tmpFile, $cacheClientes)) {
                file_put_contents($cacheClientes, $jsonFinal, LOCK_EX);
                @unlink($tmpFile);
            }
        } else {
            @unlink($tmpFile);
        }
    }

    try {
        if (!$db instanceof SQLite3) {
            $db = getClientesDb();
        }
        upsertCliente($db, $clienteAtualizado);
    } catch (Throwable $e) {
        error_log('[salvar-cliente.php] Falha ao atualizar banco local: ' . $e->getMessage());
    }

    // Agenda a atualização completa do cache de clientes para rodar em background.
    try {
        $refreshUrl = buildClientesRefreshUrl();
        if ($refreshUrl !== null) {
            scheduleClientesRefresh($refreshUrl, session_name(), session_id());
        }
    } catch (Throwable $e) {
        error_log('[salvar-cliente.php] Erro ao preparar atualização do cache de clientes: ' . $e->getMessage());
    }
}

$mensagem = $contatoId ? 'Cliente atualizado com sucesso!' : 'Cliente cadastrado com sucesso!';

echo json_encode([
    'sucesso' => true,
    'mensagem' => $mensagem,
    'cliente' => $clienteNormalizado ?? $clienteAtualizado,
]);

session_write_close();

if (function_exists('fastcgi_finish_request')) {
    // Libera a resposta para o cliente enquanto a atualização roda no shutdown handler.
    fastcgi_finish_request();
}

if ($db instanceof SQLite3) {
    $db->close();
}

/**
 * Calcula a URL do endpoint responsável por reconstruir o cache de clientes.
 */
function buildClientesRefreshUrl(): ?string
{
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return null;
    }

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $path = ($basePath === '' || $basePath === '.') ? '/clientes.php' : $basePath . '/clientes.php';

    return sprintf('%s://%s%s?refresh=1', $scheme, $host, $path);
}

/**
 * Registra uma chamada assíncrona para atualizar o cache de clientes.
 */
function scheduleClientesRefresh(string $url, string $sessionName, string $sessionId): void
{
    $cookieHeader = sprintf('Cookie: %s=%s\r\n', $sessionName, $sessionId);

    register_shutdown_function(
        static function (string $url, string $cookieHeader): void {
            try {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => $cookieHeader,
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ],
                ]);

                if (@file_get_contents($url, false, $context) === false) {
                    error_log('[salvar-cliente.php] Falha ao solicitar atualização do cache de clientes em ' . $url);
                }
            } catch (Throwable $e) {
                error_log('[salvar-cliente.php] Erro ao chamar clientes.php: ' . $e->getMessage());
            }
        },
        $url,
        $cookieHeader
    );
}

/**
 * Tenta localizar os dados completos de um contato em estruturas variadas da API.
 */
function extrairClienteDosDados($dados): ?array
{
    if (!is_array($dados)) {
        return null;
    }

    if (normalizarClienteParaResposta($dados) !== null) {
        return $dados;
    }

    foreach ($dados as $valor) {
        if (!is_array($valor)) {
            continue;
        }

        $encontrado = extrairClienteDosDados($valor);
        if ($encontrado !== null) {
            return $encontrado;
        }
    }

    return null;
}

/**
 * Recupera o ID de contato presente em diferentes formatos de resposta.
 */
function extrairContatoIdDosDados($dados): ?string
{
    if (!is_array($dados)) {
        return null;
    }

    if (isset($dados['id'])) {
        $id = trim((string) $dados['id']);
        if ($id !== '') {
            return $id;
        }
    }

    foreach ($dados as $valor) {
        if (!is_array($valor)) {
            continue;
        }

        $id = extrairContatoIdDosDados($valor);
        if ($id !== null) {
            return $id;
        }
    }

    return null;
}

/**
 * Consulta o Bling para obter os detalhes completos de um contato específico.
 */
function buscarContatoPorId(string $accessToken, string $contatoId): ?array
{
    $contatoId = trim($contatoId);
    if ($contatoId === '') {
        return null;
    }

    $url = 'https://www.bling.com.br/Api/v3/contatos/' . urlencode($contatoId);

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
        error_log('[salvar-cliente.php] Falha ao buscar contato ' . $contatoId . ': ' . $erro);
        return null;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[salvar-cliente.php] Erro ao buscar contato ' . $contatoId . ': HTTP ' . $httpCode . ' ' . $resposta);
        return null;
    }

    $dados = json_decode($resposta, true);
    if (!is_array($dados)) {
        error_log('[salvar-cliente.php] Resposta inválida ao buscar contato ' . $contatoId);
        return null;
    }

    $contato = extrairClienteDosDados($dados);
    if (!is_array($contato)) {
        error_log('[salvar-cliente.php] Não foi possível extrair dados do contato ' . $contatoId);
        return null;
    }

    return $contato;
}
