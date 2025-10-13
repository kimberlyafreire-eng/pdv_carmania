<?php
session_start();

$client_id = 'c04c4a60229a850f4c932da08d3f0a7e5e32b976';
$redirect_uri = 'https://pdv.carmaniaprodutosauto.com.br/pdv_carmania/api/auth.php';

if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(8));
    $_SESSION['state'] = $state;
    $auth_url = "https://bling.com.br/Api/v3/oauth/authorize?response_type=code&client_id=$client_id&state=$state";
    header("Location: $auth_url");
    exit();
} else {
    if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['state']) {
        echo "<h3>Erro de validação: state inválido.</h3>";
        exit();
    }

    $code = $_GET['code'];

    $postFields = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect_uri
    ]);

    $ch = curl_init('https://bling.com.br/Api/v3/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic YzA0YzRhNjAyMjlhODUwZjRjOTMyZGEwOGQzZjBhN2U1ZTMyYjk3NjoxMDRhYzM4MjczNWFmNzZjNGIxYzM4MGI0ODFiYzNmZjNjOTE0NmQ4OGRjZDVkNzU1M2JiODRiMjRiNDk='
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($httpcode === 200 && $response) {
        $decoded = json_decode($response, true);
        if (isset($decoded['access_token'])) {
            file_put_contents(__DIR__ . '/token.json', json_encode($decoded, JSON_PRETTY_PRINT));
            echo "<h3>✅ Token salvo com sucesso!</h3>";
        } else {
            echo "<h3>❌ Erro ao obter token:</h3><pre>" . print_r($decoded, true) . "</pre>";
        }
    } else {
        echo "<h3>❌ Erro de conexão:</h3>";
        echo "<pre>Código HTTP: $httpcode\nErro cURL: $curl_error\nResposta: $response</pre>";
    }
}
?>
