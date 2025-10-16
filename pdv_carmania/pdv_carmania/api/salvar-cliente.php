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

$dadosEntrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($dadosEntrada)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Payload inválido.']);
    exit();
}

$nome = trim($dadosEntrada['nome'] ?? '');
$tipoPessoaEntrada = strtoupper(substr(trim($dadosEntrada['tipoPessoa'] ?? ''), 0, 1));
$tipoPessoa = in_array($tipoPessoaEntrada, ['F', 'J'], true) ? $tipoPessoaEntrada : '';
$documento = preg_replace('/\D+/', '', $dadosEntrada['documento'] ?? '');
$celular = trim($dadosEntrada['celular'] ?? '');

$erros = [];
if ($nome === '') {
    $erros[] = 'Nome completo é obrigatório.';
}
if ($tipoPessoaEntrada !== '' && $tipoPessoa === '') {
    $erros[] = 'Tipo de pessoa inválido. Escolha Física ou Jurídica.';
}
if ($documento !== '' && $tipoPessoa === '') {
    $erros[] = 'Informe o tipo de pessoa ao preencher CPF/CNPJ.';
}
if ($celular === '') {
    $erros[] = 'Celular é obrigatório.';
}

if ($erros) {
    http_response_code(422);
    echo json_encode(['erro' => implode(' ', $erros)]);
    exit();
}

try {
    $tiposContato = ensureContactTypesCache();
    $tipoClienteId = findContactTypeId($tiposContato, 'cliente');
    if (!$tipoClienteId) {
        throw new RuntimeException('Tipo de contato "Cliente" não encontrado no cache.');
    }
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
    exit();
}

$payload = [
    'nome' => $nome,
    'situacao' => 'A',
    'celular' => $celular,
    'tiposContato' => [
        ['id' => $tipoClienteId],
    ],
];

if ($tipoPessoa !== '') {
    $payload['tipo'] = $tipoPessoa;
}

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

$contatoId = isset($dadosEntrada['id']) && $dadosEntrada['id'] !== '' ? trim((string)$dadosEntrada['id']) : null;

$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($payloadJson === false) {
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível preparar os dados para envio.']);
    exit();
}

$accessToken = getAccessToken();
if (!$accessToken) {
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
    exit();
}

$clienteAtualizado = $dadosResposta['data'] ?? null;
$clienteNormalizado = null;

// Atualiza o cache local de clientes quando possível.
if (is_array($clienteAtualizado)) {
    $clienteNormalizado = normalizarClienteParaResposta($clienteAtualizado);
    $cacheClientes = __DIR__ . '/../cache/clientes-cache.json';
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
        $db = getClientesDb();
        upsertCliente($db, $clienteAtualizado);
        $db->close();
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
