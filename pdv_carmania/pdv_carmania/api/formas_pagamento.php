<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

$caminhoCache = __DIR__ . '/../cache/formas_pagamento-cache.json';

if (file_exists($caminhoCache) && filemtime($caminhoCache) > time() - 3600) {
    echo file_get_contents($caminhoCache);
    exit();
}

$tokenPath = __DIR__ . '/token.json';
if (!file_exists($tokenPath)) {
    echo json_encode([]);
    exit();
}
$tokenData = json_decode(file_get_contents($tokenPath), true);
$accessToken = $tokenData['access_token'];

$url = "https://www.bling.com.br/Api/v3/formas-pagamentos";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $accessToken"
]);
$resposta = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    file_put_contents($caminhoCache, $resposta);
    echo $resposta;
} else {
    echo json_encode([]);
}
?>