<?php
/**
 * token-helper.php
 * Utilitário central para gerenciar e renovar tokens do Bling (OAuth2)
 */

$tokenFile = __DIR__ . '/../token.json';
$logDir    = __DIR__ . '/../../logs';
$logFile   = "$logDir/token-helper.log";
$blingAuth = 'https://bling.com.br/Api/v3/oauth/token';

// 🔐 Credenciais fixas (suas do app Bling)
$client_id     = 'c04c4a60229a850f4c932da08d3f0a7e5e32b976';
$client_secret = '104ac382735af76c4b1c380b481bc3ff3c9146d88dcd5d7553bb84b24b49';

// Garante logs
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

// 🔹 Lê o token atual
function getAccessToken() {
    global $tokenFile;

    if (!file_exists($tokenFile)) {
        logMsg("❌ Token.json não encontrado.");
        return null;
    }

    $data = json_decode(file_get_contents($tokenFile), true);
    if (!$data || empty($data['access_token'])) {
        logMsg("❌ Token inválido ou malformado em token.json");
        return null;
    }

    // Checa expiração (salvamos o 'expires_at' quando atualizamos)
    if (!empty($data['expires_at']) && time() >= $data['expires_at']) {
        logMsg("⚠ Token expirado detectado — tentando renovar automaticamente...");
        $ok = refreshAccessToken();
        if ($ok) {
            $data = json_decode(file_get_contents($tokenFile), true);
            return $data['access_token'] ?? null;
        }
        return null;
    }

    return $data['access_token'];
}

// 🔁 Renova o token
function refreshAccessToken() {
    global $tokenFile, $blingAuth, $client_id, $client_secret;

    if (!file_exists($tokenFile)) {
        logMsg("❌ Não há token.json para renovar.");
        return false;
    }

    $data = json_decode(file_get_contents($tokenFile), true);
    $refresh_token = $data['refresh_token'] ?? null;
    if (!$refresh_token) {
        logMsg("❌ refresh_token ausente no token.json");
        return false;
    }

    $ch = curl_init($blingAuth);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Basic " . base64_encode("$client_id:$client_secret"),
            "Content-Type: application/x-www-form-urlencoded"
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        ])
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMsg("🔄 Tentando renovar token (HTTP $httpCode)");
    logMsg("Resposta: " . $response);

    if ($httpCode != 200) {
        logMsg("❌ Falha ao renovar token");
        return false;
    }

    $json = json_decode($response, true);
    if (empty($json['access_token'])) {
        logMsg("❌ Resposta inválida ao renovar token");
        return false;
    }

    // 🔒 Atualiza o arquivo token.json com os novos dados
    $json['expires_at'] = time() + ((int)$json['expires_in'] ?? 21600); // 6h por padrão
    file_put_contents($tokenFile, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    logMsg("✅ Token atualizado com sucesso em token.json");
    return true;
}
