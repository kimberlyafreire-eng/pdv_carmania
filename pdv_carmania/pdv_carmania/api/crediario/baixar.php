<?php
header('Content-Type: application/json; charset=utf-8');

// ✅ Inclui o helper do token centralizado
require_once __DIR__ . '/../lib/token-helper.php';

$logDir  = __DIR__ . '/../../../logs';
$logFile = "$logDir/baixar-crediario.log";
if (!is_dir($logDir)) mkdir($logDir, 0777, true);


// --- Bling Base URL
$blingBase = 'https://bling.com.br/Api/v3';
$cacheCat  = __DIR__ . '/categorias-rd-cache.json';
$cacheContasFin = __DIR__ . '/contas-financeiras-cache.json';

// --- Entrada JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); die(json_encode(['erro'=>'JSON inválido'])); }

$clienteId   = $input['clienteId'] ?? null;
$clienteNome = $input['clienteNome'] ?? '';
$titulos     = $input['titulos'] ?? [];
$pagamentos  = $input['pagamentos'] ?? [];

if (!$clienteId || empty($titulos) || empty($pagamentos)) {
    http_response_code(400);
    die(json_encode(['erro'=>'Dados incompletos']));
}

// --- Função de requisição com token helper
function bling_request($method, $path, $body = null) {
    global $blingBase;
    $url = rtrim($blingBase, '/') . '/' . ltrim($path, '/');
    $headers = [
        "Authorization: Bearer " . getAccessToken(),
        "Content-Type: application/json",
        "Accept: application/json"
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 🔁 Se deu erro de autenticação, tenta renovar token automaticamente
    if (in_array($http, [401, 403])) {
        logMsg("⚠ Token expirado — renovando automaticamente");
        refreshAccessToken();
        return bling_request($method, $path, $body);
    }

    return ['http' => $http, 'body' => $resp];
}

// --- Cache de Categorias RD
function getCategoriasRD() {
    global $cacheCat;
    if (file_exists($cacheCat) && time() - filemtime($cacheCat) < 3600)
        return json_decode(file_get_contents($cacheCat), true);

    $cats = [];
    for ($p = 1; ; $p++) {
        $res = bling_request('GET', "categorias/receitas-despesas?pagina=$p&limite=100");
        $json = json_decode($res['body'], true);
        if (empty($json['data'])) break;
        foreach ($json['data'] as $c) {
            $cats[strtoupper(trim($c['descricao']))] = $c['id'];
        }
        if (count($json['data']) < 100) break;
    }
    file_put_contents($cacheCat, json_encode($cats, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $cats;
}

// --- Cache de Contas Financeiras
function getContasFinanceiras() {
    global $cacheContasFin;
    if (file_exists($cacheContasFin) && time() - filemtime($cacheContasFin) < 3600)
        return json_decode(file_get_contents($cacheContasFin), true);

    $contas = [];
    for ($p = 1; ; $p++) {
        $res = bling_request('GET', "contas-contabeis?pagina=$p&limite=100");
        $json = json_decode($res['body'], true);
        if (empty($json['data'])) break;
        foreach ($json['data'] as $c) {
            $contas[strtoupper(trim($c['descricao']))] = $c['id'];
        }
        if (count($json['data']) < 100) break;
    }
    file_put_contents($cacheContasFin, json_encode($contas, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $contas;
}

$categorias = getCategoriasRD();
$contasFin = getContasFinanceiras();

// --- Normaliza texto
function normalize($str){
    $str = mb_strtoupper($str,'UTF-8');
    $str = iconv('UTF-8','ASCII//TRANSLIT',$str);
    return trim(preg_replace('/[^A-Z0-9 ]/','',$str));
}

// --- Calcula valores recebidos
$totalRecebido = 0; $formas = [];
foreach ($pagamentos as $p) {
    $valor = floatval($p['valor'] ?? 0);
    $nome  = $p['nome'] ?? $p['forma'] ?? '';
    if ($valor <= 0 || !$nome) continue;
    $totalRecebido += $valor;
    $formas[] = ['nome' => $nome, 'valor' => $valor];
}
if ($totalRecebido <= 0) die(json_encode(['erro'=>'Nenhum valor recebido']));

$totalAberto = array_sum(array_map(fn($t)=>floatval($t['restante']??$t['valor']??0), $titulos));
if ($totalRecebido > $totalAberto) {
    logMsg("⚠ Valor recebido excede o saldo (R$ $totalRecebido > R$ $totalAberto). Ajustado automaticamente.");
    $totalRecebido = $totalAberto;
}

// --- Seleciona conta financeira "CREDIÁRIO"
$contaId = null; $contaNome = '';
foreach ($contasFin as $nome => $id) {
    if (str_contains($nome, 'CREDIÁRIO')) {
        $contaId = $id; $contaNome = $nome;
        break;
    }
}
if (!$contaId) logMsg("⚠ Conta financeira 'CREDIÁRIO' não encontrada");

usort($titulos, fn($a,$b)=>strcmp($a['vencimento'],$b['vencimento']));
$totalAntes = $totalAberto;
$valorRestante = $totalRecebido;

foreach ($titulos as &$t) {
    $idTitulo = $t['id'];
    $valorTitulo = floatval($t['restante'] ?? $t['valor'] ?? 0);
    if ($valorTitulo <= 0) continue;

    foreach ($formas as &$pf) {
        if ($valorTitulo <= 0 || $pf['valor'] <= 0) continue;
        $valorUsar = min($pf['valor'], $valorTitulo);
        $pf['valor'] -= $valorUsar;
        $valorTitulo -= $valorUsar;
        $valorRestante -= $valorUsar;

        $catId = null; $nomeForma = normalize($pf['nome']);
        foreach ($categorias as $desc => $id) {
            $norm = normalize($desc);
            if (str_contains($norm, 'CREDIARIO') && str_contains($norm, $nomeForma)) {
                $catId = $id; break;
            }
        }

        $payload = [
            "data" => date('Y-m-d'),
            "usarDataVencimento" => false,
            "portador" => ["id" => $contaId],
            "categoria" => $catId ? ["id" => $catId] : null,
            "historico" => "Baixa via PDV Carmania - {$pf['nome']}",
            "valorRecebido" => round($valorUsar, 2)
        ];

        $res = bling_request('POST', "contas/receber/{$idTitulo}/baixar", $payload);
        $ok = $res['http'] >= 200 && $res['http'] < 300;
        logMsg(($ok?'✅':'❌')." Baixa título {$idTitulo} valor={$valorUsar} forma={$pf['nome']} catId={$catId} contaId={$contaId}");

        if ($valorRestante <= 0) break;
    }
    if ($valorRestante <= 0) break;
}

$totalDepois = max(0, $totalAntes - $totalRecebido);

// --- Gera Recibo
$reciboHtml = "<div style='max-width:420px;width:90vw;height:calc(90vh - 80px);margin:0 auto;
flex-direction:column;padding:20px;font-family:monospace;font-size:15px;text-align:center;
background:white;box-shadow:0 0 10px rgba(0,0,0,0.15);border-radius:14px;'>
<h4 style='margin:5px 0;color:#dc3545;'>Carmania Produtos Automotivos</h4>
<p style='margin:0 0 8px;font-weight:bold;'>Recibo de Pagamento Crediário</p>
<p style='margin:0 0 10px;'>Cliente: <b>".htmlspecialchars($clienteNome)."</b></p>
<div style='background:#f5f5f5;border-radius:10px;padding:12px 10px;margin:10px 0;'>
<table style='width:100%;font-size:15px;text-align:left;'>
<tr><td>💳 <b>Saldo Anterior</b></td><td style='text-align:right'><b>R$ ".number_format($totalAntes,2,',','.')."</b></td></tr>
<tr><td>💵 <b>Valor Pago</b></td><td style='text-align:right;color:#28a745;'><b>- R$ ".number_format($totalRecebido,2,',','.')."</b></td></tr>
<tr><td>📊 <b>Saldo Atual</b></td><td style='text-align:right;color:#dc3545;'><b>R$ ".number_format($totalDepois,2,',','.')."</b></td></tr>
</table></div><hr><p style='font-weight:bold;margin-bottom:6px;'>Formas de Pagamento</p>
<table style='width:100%;font-size:15px;text-align:left;margin-bottom:10px'>";
foreach ($pagamentos as $p) {
    $reciboHtml .= "<tr><td>{$p['nome']}</td><td style='text-align:right'>R$ ".number_format($p['valor'],2,',','.')."</td></tr>";
}
$reciboHtml .= "</table><hr>
<table style='width:100%;font-size:13px;text-align:left;color:#555;'>
<tr><td>🧾 Conta financeira:</td><td style='text-align:right'><b>".htmlspecialchars($contaNome)."</b></td></tr>
<tr><td>📅 Data:</td><td style='text-align:right'>".date('d/m/Y H:i')."</td></tr></table>
<p style='margin:14px 0 0;font-size:13px;color:#444;'>Obrigado pela preferência!</p></div>";

echo json_encode(['ok'=>true,'reciboHtml'=>$reciboHtml], JSON_UNESCAPED_UNICODE);
