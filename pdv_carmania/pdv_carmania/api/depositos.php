<?php
header('Content-Type: application/json; charset=utf-8');

// ✅ Usa o helper centralizado de token
require_once __DIR__ . '/lib/token-helper.php';

// ⚙️ Caminhos e configs
$tokenFile = __DIR__ . '/token.json';
$cacheFile = __DIR__ . '/../cache/depositos-cache.json';
$logFile   = __DIR__ . '/../logs/depositos.log';
$blingBase = 'https://bling.com.br/Api/v3';

logMsg("🚀 Iniciando consulta de depósitos...");

// 🔐 Token válido (é atualizado automaticamente se estiver expirando)
$access_token = getAccessToken();
if (!$access_token) {
    logMsg("❌ Falha ao obter token válido.");
    echo json_encode(['ok' => false, 'erro' => 'Falha ao obter token válido']);
    exit;
}

// 🧠 Verifica cache (1h)
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    logMsg("✅ Retornando cache de depósitos");
    echo file_get_contents($cacheFile);
    exit;
}

// 🔗 Consulta API do Bling
$url = "$blingBase/depositos?limite=100";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $access_token",
        "Accept: application/json"
    ]
]);
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http === 200) {
    file_put_contents($cacheFile, $res);
    logMsg("✅ Depósitos atualizados no cache e enviados.");
    echo $res;
} else {
    logMsg("❌ Erro HTTP $http ao buscar depósitos: $res");
    echo json_encode(['ok' => false, 'erro' => 'Erro ao buscar depósitos', 'detalhe' => $res]);
}
