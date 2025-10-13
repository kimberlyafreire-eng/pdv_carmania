<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

// ✅ Carrega o token automaticamente e renova se necessário
require_once __DIR__ . '/lib/token-helper.php';
$accessToken = getAccessToken();
if(!$accessToken){
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao obter token do Bling"]);
    exit();
}

$caminhoCache = __DIR__ . '/../cache/clientes-cache.json';

// Usa cache de até 1 hora
if (file_exists($caminhoCache) && filemtime($caminhoCache) > time() - 3600) {
    echo file_get_contents($caminhoCache);
    exit();
}

$url = "https://www.bling.com.br/Api/v3/contatos?limite=100";

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
    http_response_code($httpCode);
    echo json_encode(["erro" => "Erro ao consultar Bling", "http" => $httpCode]);
}
?>
