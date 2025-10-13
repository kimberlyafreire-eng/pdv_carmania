<?php
// EXIBIR ERROS PARA DEBUG
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(180);

// 🔧 CONFIGURAÇÕES
$client_id = 'c04c4a60229a850f4c932da08d3f0a7e5e32b976';
$client_secret = '104ac382735af76c4b1c380b481bc3ff3c9146d88dcd5d7553bb84b24b49';

$tokenFile = __DIR__ . '/token.json';
$produtosCache = __DIR__ . '/produtos-cache.json';

echo "🔁 Iniciando atualização de produtos...\n";

// 📦 VERIFICAR TOKEN EXISTENTE
if (!file_exists($tokenFile)) {
    die("❌ Token não encontrado. Execute auth.php manualmente para gerar o token.\n");
}

$tokenData = json_decode(file_get_contents($tokenFile), true);
$refresh_token = $tokenData['refresh_token'] ?? '';
$credentials = base64_encode("$client_id:$client_secret");

// 🔐 RENOVAR TOKEN
$ch = curl_init('https://bling.com.br/Api/v3/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refresh_token
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Basic $credentials",
    "Content-Type: application/x-www-form-urlencoded"
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode !== 200) {
    die("❌ Erro ao renovar token: HTTP $httpcode\nResposta: $response\n");
}

$newToken = json_decode($response, true);
file_put_contents($tokenFile, json_encode($newToken, JSON_PRETTY_PRINT));
echo "✅ Token renovado com sucesso!\n";

$access_token = $newToken['access_token'] ?? '';
if (!$access_token) {
    die("❌ Access token ausente na resposta.\n");
}

// 🔁 BAIXAR PRODUTOS COM PAGINAÇÃO
$pagina = 1;
$todosProdutos = [];

do {
    $url = "https://bling.com.br/Api/v3/produtos?page=$pagina&pageSize=100";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$access_token}",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        echo "❌ Erro ao buscar página $pagina: HTTP $httpcode\n";
        echo "⏳ Aguardando 5 segundos para tentar novamente...\n";
        sleep(5);
        continue;
    }

    $dados = json_decode($response, true);
    $produtos = $dados['data'] ?? [];

    echo "📦 Página $pagina - " . count($produtos) . " produtos encontrados.\n";

    if (count($produtos) === 0) {
        break;
    }

    $todosProdutos = array_merge($todosProdutos, $produtos);

    if (count($produtos) < 100) {
        echo "📌 Última página alcançada.\n";
        break;
    }

    $pagina++;
    usleep(400000); // Respeita limite de 2.5 req/s (400ms)

} while (true);

// 💾 SALVAR PRODUTOS EM CACHE
file_put_contents($produtosCache, json_encode($todosProdutos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "✅ Todos os produtos salvos em cache com sucesso! Total: " . count($todosProdutos) . "\n";
