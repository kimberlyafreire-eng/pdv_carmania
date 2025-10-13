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

$pagina = 1;
$limite = 100;
$todosClientes = [];

do {
    $url = "https://www.bling.com.br/Api/v3/contatos?pagina=$pagina&limite=$limite";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken"
    ]);

    $resposta = curl_exec($ch);
    if ($resposta === false) {
        $erroCurl = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(["erro" => "Falha na comunicação com o Bling", "detalhes" => $erroCurl]);
        exit();
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        echo json_encode(["erro" => "Erro ao consultar Bling", "http" => $httpCode]);
        exit();
    }

    $dados = json_decode($resposta, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(["erro" => "Resposta inválida do Bling"]);
        exit();
    }

    $clientes = isset($dados['data']) && is_array($dados['data']) ? $dados['data'] : [];
    $todosClientes = array_merge($todosClientes, $clientes);
    $pagina++;
} while (count($clientes) === $limite);

$payload = json_encode(['data' => $todosClientes], JSON_UNESCAPED_UNICODE);
if ($payload === false) {
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao preparar cache de clientes"]);
    exit();
}

file_put_contents($caminhoCache, $payload);
echo $payload;
?>
