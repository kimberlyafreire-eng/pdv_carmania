<?php
header('Content-Type: application/json; charset=utf-8');

$tokenFile = __DIR__ . '/../token.json';
$logDir    = __DIR__ . '/../../logs';
$blingBase = 'https://bling.com.br/Api/v3';

if (!file_exists($tokenFile)) {
    echo json_encode(['erro' => 'Token não encontrado']);
    exit;
}

$tokenData = json_decode(file_get_contents($tokenFile), true);
$access_token = $tokenData['access_token'] ?? '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['clienteId'])) {
    echo json_encode(['erro' => 'Informe o clienteId']);
    exit;
}
$clienteId = trim($input['clienteId']);

// === faz a chamada GET para /contas/receber ===
$url = "$blingBase/contas/receber?pagina=1&limite=10&contatoId=$clienteId"; // limit 10 só pra debug
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $access_token",
        "Accept: application/json"
    ]
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// === se tudo OK, mostra o JSON bruto ===
if ($httpCode === 200) {
    $data = json_decode($response, true);
    echo json_encode([
        'ok' => true,
        'url' => $url,
        'clienteId' => $clienteId,
        'exemploTitulo' => $data['data'][0] ?? null, // mostra o primeiro título completo
        'totalTitulos' => count($data['data'] ?? []),
        'data' => $data['data'] ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'ok' => false,
        'http' => $httpCode,
        'response' => $response
    ]);
}
