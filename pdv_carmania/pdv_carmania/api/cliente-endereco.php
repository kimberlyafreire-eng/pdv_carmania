<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

$contatoId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($contatoId === '') {
    http_response_code(400);
    echo json_encode(['erro' => 'ID do contato é obrigatório.']);
    exit();
}

require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/clientes-sync.php';
require_once __DIR__ . '/lib/clientes-db.php';

try {
    $accessToken = getAccessToken();
} catch (Throwable $e) {
    $accessToken = null;
}

if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['erro' => 'Não foi possível obter um token válido do Bling.']);
    exit();
}

try {
    $detalhes = consultarContatoBlingPorId($contatoId, $accessToken);
} catch (RuntimeException $e) {
    $codigo = $e->getCode();
    if ($codigo >= 400 && $codigo < 600) {
        http_response_code($codigo);
    } else {
        http_response_code(502);
    }
    echo json_encode(['erro' => $e->getMessage()]);
    exit();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha inesperada ao consultar contato no Bling.']);
    exit();
}

$dadosContato = [];
if (is_array($detalhes)) {
    if (isset($detalhes['data']) && is_array($detalhes['data'])) {
        $dadosContato = $detalhes['data'];
    } else {
        $dadosContato = $detalhes;
    }
}

if (!is_array($dadosContato) || empty($dadosContato)) {
    http_response_code(404);
    echo json_encode(['erro' => 'Contato não encontrado no Bling.']);
    exit();
}

$clienteNormalizado = normalizarClienteParaResposta($dadosContato);
$fonteEndereco = [];

if (is_array($clienteNormalizado)) {
    $fonteEndereco = $clienteNormalizado['endereco']['geral'] ?? ($clienteNormalizado['endereco'] ?? []);
}

if (!is_array($fonteEndereco) || empty($fonteEndereco)) {
    $fonteEndereco = $dadosContato['endereco']['geral'] ?? ($dadosContato['endereco'] ?? []);
    if (!is_array($fonteEndereco)) {
        $fonteEndereco = [];
    }
}

$rua = trim((string) ($fonteEndereco['endereco'] ?? ''));
$numero = trim((string) ($fonteEndereco['numero'] ?? ''));
$bairro = trim((string) ($fonteEndereco['bairro'] ?? ''));
$cidade = trim((string) ($fonteEndereco['municipio'] ?? ($fonteEndereco['cidade'] ?? '')));
$estado = strtoupper(trim((string) ($fonteEndereco['uf'] ?? ($fonteEndereco['estado'] ?? ''))));
$cep = trim((string) ($fonteEndereco['cep'] ?? ''));

if ($cep !== '') {
    $digitos = preg_replace('/\D+/', '', $cep);
    if (strlen($digitos) === 8) {
        $cep = substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    } elseif ($digitos !== '') {
        $cep = $digitos;
    }
}

$temEndereco = false;
foreach ([$rua, $numero, $bairro, $cidade, $estado, $cep] as $valor) {
    if ($valor !== '') {
        $temEndereco = true;
        break;
    }
}

$payloadEndereco = [
    'endereco' => $rua,
    'rua' => $rua,
    'numero' => $numero,
    'bairro' => $bairro,
    'municipio' => $cidade,
    'cidade' => $cidade,
    'uf' => $estado,
    'estado' => $estado,
    'cep' => $cep,
];

$mensagem = $temEndereco
    ? 'Endereço atualizado a partir do Bling.'
    : 'O contato não possui endereço cadastrado no Bling.';

echo json_encode([
    'sucesso' => true,
    'mensagem' => $mensagem,
    'temEndereco' => $temEndereco,
    'endereco' => $payloadEndereco,
    'contato' => $clienteNormalizado ?? $dadosContato,
]);

session_write_close();
exit();
