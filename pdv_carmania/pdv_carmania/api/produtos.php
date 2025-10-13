<?php
// Caminho para o token salvo
$tokenFile = __DIR__ . '/token.json';

// ✅ Atualiza o token automaticamente, se necessário
require_once __DIR__ . '/refresh-token.php';

if (!file_exists($tokenFile)) {
    die('<h3>❌ Token não encontrado. Faça a autenticação primeiro.</h3>');
}

$tokenData = json_decode(file_get_contents($tokenFile), true);
$access_token = $tokenData['access_token'] ?? '';

if (!$access_token) {
    die('<h3>❌ Token inválido ou expirado.</h3>');
}

// Requisição para listar produtos
$url = "https://bling.com.br/Api/v3/produtos";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$access_token}",
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// Exibir resultado
if ($httpcode === 200) {
    $produtos = json_decode($response, true);
    echo "<h3>✅ Produtos recebidos com sucesso!</h3>";
    echo "<pre>" . print_r($produtos, true) . "</pre>";
} else {
    echo "<h3>❌ Erro ao consultar produtos:</h3>";
    echo "<pre>Código HTTP: {$httpcode}\nErro: {$curl_error}\nResposta: {$response}</pre>";
}
?>
