<?php
header('Content-Type: application/json; charset=utf-8');

// ✅ Usa o novo helper que renova o token automaticamente
require_once __DIR__ . '/../lib/token-helper.php';
$access_token = getAccessToken();
if (!$access_token) {
    echo json_encode(['ok' => false, 'erro' => 'Falha ao obter token válido do Bling']);
    exit;
}

$logDir    = __DIR__ . '/../../logs';
$logFile   = $logDir . '/saldo-crediario.log';
$blingBase = 'https://bling.com.br/Api/v3';
$formaCrediarioId = 8126949;

if (!is_dir($logDir)) mkdir($logDir, 0777, true);

// 🔹 Permite chamada tanto por POST quanto por GET
$input = json_decode(file_get_contents('php://input'), true);
$clienteId = $input['clienteId'] ?? ($_GET['clienteId'] ?? null);

if (!$clienteId) {
    echo json_encode(['ok' => false, 'erro' => 'Informe o clienteId']);
    exit;
}
$clienteId = trim($clienteId);

logMsg("🧮 Iniciando cálculo de saldo crediário para clienteId=$clienteId");

function callBling($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Accept: application/json"
        ]
    ]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, $response];
}

$titulos = [];
$page = 1;
$limit = 100;
$totalSaldo = 0.0;

do {
    $url = "$blingBase/contas/receber?pagina=$page&limite=$limit";
    list($http, $response) = callBling($url, $access_token);
    if ($http != 200) {
        logMsg("❌ Erro HTTP $http ao buscar página $page -> $response");
        break;
    }

    $json = json_decode($response, true);
    $data = $json['data'] ?? [];
    if (!$data) break;

    foreach ($data as $t) {
        $contatoId = $t['contato']['id'] ?? null;
        $formaId   = $t['formaPagamento']['id'] ?? null;
        $situacao  = intval($t['situacao'] ?? 0);

        // 🔹 Considera apenas títulos abertos ou parcialmente pagos do cliente
        if ($contatoId == $clienteId && $formaId == $formaCrediarioId && in_array($situacao, [1, 3])) {
            $idConta = $t['id'];
            $valorTitulo = floatval($t['valor'] ?? 0);

            // 🔍 Consulta o detalhe do título para pegar o saldo real
            $urlDetalhe = "$blingBase/contas/receber/$idConta";
            list($http2, $resp2) = callBling($urlDetalhe, $access_token);

            $saldo = $valorTitulo;
            if ($http2 == 200) {
                $detalhe = json_decode($resp2, true)['data'] ?? [];
                if (isset($detalhe['saldo'])) {
                    $saldo = floatval($detalhe['saldo']);
                }
            } else {
                logMsg("⚠ Falha ao obter detalhes do título $idConta: HTTP $http2");
            }

            if ($saldo <= 0 && $situacao == 1) {
                $saldo = $valorTitulo;
            }

            if ($saldo > 0.009) {
                $titulos[] = [
                    'id' => $idConta,
                    'situacao' => $situacao,
                    'vencimento' => $t['vencimento'] ?? null,
                    'valor' => $valorTitulo,
                    'restante' => $saldo
                ];
                $totalSaldo += $saldo;
                logMsg("→ Título $idConta (sit=$situacao) restante=R$" . number_format($saldo,2,",","."));
            }
        }
    }

    $page++;
    $temMais = count($data) >= $limit;
} while ($temMais);

logMsg("✅ Saldo final clienteId=$clienteId -> R$ " . number_format($totalSaldo,2,",",".") . " | títulos=".count($titulos));

echo json_encode([
    'ok' => true,
    'clienteId' => $clienteId,
    'saldoAtual' => round($totalSaldo, 2),
    'titulos' => $titulos
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
