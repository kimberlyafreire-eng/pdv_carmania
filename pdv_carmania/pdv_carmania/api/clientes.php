<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(["erro" => "Não autorizado"]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

// ✅ Carrega o token automaticamente e renova se necessário
require_once __DIR__ . '/lib/token-helper.php';
$accessToken = getAccessToken();
if(!$accessToken){
    http_response_code(500);
    echo json_encode(["erro" => "Falha ao obter token do Bling"]);
    exit();
}

$caminhoCache = __DIR__ . '/../cache/clientes-cache.json';
$cacheExistente = file_exists($caminhoCache) ? file_get_contents($caminhoCache) : null;


$pagina = 1;
$limite = 100;
$todosClientes = [];

do {
    $query = http_build_query([
        'pagina' => $pagina,
        'limite' => $limite,
        'tipo'   => 'cliente'
    ], '', '&', PHP_QUERY_RFC3986);

    $url = "https://www.bling.com.br/Api/v3/contatos?$query";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken"
    ]);

    $resposta = curl_exec($ch);
    if ($resposta === false) {
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($cacheExistente !== null) {
            echo $cacheExistente;
            exit();
        }

        http_response_code(500);
        echo json_encode(["erro" => "Falha na comunicação com o Bling", "detalhes" => $erroCurl]);
        exit();
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        if ($cacheExistente !== null) {
            echo $cacheExistente;
            exit();
        }

        http_response_code($httpCode);
        echo json_encode(["erro" => "Erro ao consultar Bling", "http" => $httpCode]);
        exit();
    }

    $dados = json_decode($resposta, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if ($cacheExistente !== null) {
            echo $cacheExistente;
            exit();
        }

        http_response_code(500);
        echo json_encode(["erro" => "Resposta inválida do Bling"]);
        exit();
    }

    $clientes = isset($dados['data']) && is_array($dados['data']) ? $dados['data'] : [];
    $todosClientes = array_merge($todosClientes, $clientes);
    $pagina++;
} while (count($clientes) === $limite);

$payload = json_encode([
    'data' => $todosClientes,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($payload === false) {
    if ($cacheExistente !== null) {
        echo $cacheExistente;
        exit();
    }

    http_response_code(500);
    echo json_encode(["erro" => "Falha ao preparar cache de clientes"]);
    exit();
}

$gravado = file_put_contents($caminhoCache, $payload, LOCK_EX);
if ($gravado === false) {
    error_log('[clientes.php] Não foi possível gravar o clientes-cache.json.');
}

echo $payload;
?>

