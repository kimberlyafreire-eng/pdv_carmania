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

$dadosEntrada = json_decode(file_get_contents('php://input'), true);
if (!is_array($dadosEntrada)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Payload inválido.']);
    exit();
}

$nome = trim($dadosEntrada['nome'] ?? '');
$tipoPessoa = strtoupper(substr(trim($dadosEntrada['tipoPessoa'] ?? ''), 0, 1));
$documento = preg_replace('/\D+/', '', $dadosEntrada['documento'] ?? '');
$celular = trim($dadosEntrada['celular'] ?? '');

$erros = [];
if ($nome === '') {
    $erros[] = 'Nome completo é obrigatório.';
}
if (!in_array($tipoPessoa, ['F', 'J'], true)) {
    $erros[] = 'Tipo de pessoa inválido. Escolha Física ou Jurídica.';
}
if ($documento === '') {
    $erros[] = 'CPF/CNPJ é obrigatório.';
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
    'tipo' => $tipoPessoa,
    'numeroDocumento' => $documento,
    'celular' => $celular,
    'tiposContato' => [
        ['id' => $tipoClienteId],
    ],
];

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

// Atualiza o cache local de clientes quando possível.
if (is_array($clienteAtualizado)) {
    $cacheClientes = __DIR__ . '/../cache/clientes-cache.json';
    $clientes = ['data' => []];

    if (file_exists($cacheClientes)) {
        $conteudoCache = file_get_contents($cacheClientes);
        if ($conteudoCache !== false) {
            $jsonCache = json_decode($conteudoCache, true);
            if (is_array($jsonCache) && isset($jsonCache['data']) && is_array($jsonCache['data'])) {
                $clientes = $jsonCache;
            }
        }
    }

    $atualizado = false;
    foreach ($clientes['data'] as &$cliente) {
        if (isset($cliente['id']) && isset($clienteAtualizado['id']) && (string)$cliente['id'] === (string)$clienteAtualizado['id']) {
            $cliente = $clienteAtualizado;
            $atualizado = true;
            break;
        }
    }
    unset($cliente);

    if (!$atualizado) {
        $clientes['data'][] = $clienteAtualizado;
    }

    $jsonFinal = json_encode($clientes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonFinal !== false) {
        file_put_contents($cacheClientes, $jsonFinal, LOCK_EX);
    }
}

$mensagem = $contatoId ? 'Cliente atualizado com sucesso!' : 'Cliente cadastrado com sucesso!';

echo json_encode([
    'sucesso' => true,
    'mensagem' => $mensagem,
    'cliente' => $clienteAtualizado,
]);
