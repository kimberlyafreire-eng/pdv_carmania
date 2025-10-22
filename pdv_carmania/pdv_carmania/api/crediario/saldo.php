<?php
header('Content-Type: application/json; charset=utf-8');

// ✅ Usa o novo helper que renova o token automaticamente
require_once __DIR__ . '/../lib/token-helper.php';
require_once __DIR__ . '/../lib/vendas-helper.php';

$access_token = getAccessToken();
if (!$access_token) {
    echo json_encode(['ok' => false, 'erro' => 'Falha ao obter token válido do Bling']);
    exit;
}

$dbVendas = null;
try {
    $dbVendas = getVendasDb();
} catch (Throwable $e) {
    $dbVendas = null;
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
            $documento = $t['documento'] ?? null;
            $dataEmissao = $t['dataEmissao'] ?? null;
            $origemDados = $t['origem'] ?? [];
            $origemId = $origemDados['id'] ?? null;
            $origemTipo = $origemDados['tipoOrigem'] ?? ($origemDados['tipo'] ?? null);
            $origemNumero = $origemDados['numero'] ?? null;
            $origemUrl = $origemDados['url'] ?? null;

            // 🔍 Consulta o detalhe do título para pegar o saldo real
            $urlDetalhe = "$blingBase/contas/receber/$idConta";
            list($http2, $resp2) = callBling($urlDetalhe, $access_token);

            $saldo = $valorTitulo;
            $detalhe = [];
            if ($http2 == 200) {
                $detalhe = json_decode($resp2, true)['data'] ?? [];
                if (isset($detalhe['saldo'])) {
                    $saldo = floatval($detalhe['saldo']);
                }
                if (!$documento && isset($detalhe['documento'])) {
                    $documento = $detalhe['documento'];
                }
                if (!$dataEmissao && isset($detalhe['dataEmissao'])) {
                    $dataEmissao = $detalhe['dataEmissao'];
                }
                if (empty($origemDados) && isset($detalhe['origem']) && is_array($detalhe['origem'])) {
                    $origemDados = $detalhe['origem'];
                }
                if (isset($detalhe['origem']) && is_array($detalhe['origem'])) {
                    $origemDetalhe = $detalhe['origem'];
                    if ($origemId === null && isset($origemDetalhe['id'])) {
                        $origemId = $origemDetalhe['id'];
                    }
                    if ($origemTipo === null && isset($origemDetalhe['tipoOrigem'])) {
                        $origemTipo = $origemDetalhe['tipoOrigem'];
                    } elseif ($origemTipo === null && isset($origemDetalhe['tipo'])) {
                        $origemTipo = $origemDetalhe['tipo'];
                    }
                    if ($origemNumero === null && isset($origemDetalhe['numero'])) {
                        $origemNumero = $origemDetalhe['numero'];
                    }
                    if ($origemUrl === null && isset($origemDetalhe['url'])) {
                        $origemUrl = $origemDetalhe['url'];
                    }
                }
            } else {
                logMsg("⚠ Falha ao obter detalhes do título $idConta: HTTP $http2");
            }

            if ($saldo <= 0 && $situacao == 1) {
                $saldo = $valorTitulo;
            }

            if ($saldo > 0.009) {
                $vendaId = null;
                if ($origemTipo !== null) {
                    $tipoNormalizado = strtolower((string) $origemTipo);
                    if ($tipoNormalizado === 'venda' && $origemId !== null) {
                        $vendaId = (int) $origemId;
                    }
                }

                $saldoAnteriorVenda = null;
                $saldoNovoVenda = null;
                $valorCrediarioVenda = null;
                if ($vendaId && $dbVendas instanceof SQLite3) {
                    try {
                        $stmtVenda = $dbVendas->prepare('SELECT saldo_crediario_anterior, saldo_crediario_novo, valor_crediario_venda FROM vendas WHERE id = :id LIMIT 1');
                        $stmtVenda->bindValue(':id', $vendaId, SQLITE3_INTEGER);
                        $resVenda = $stmtVenda->execute();
                        if ($resVenda) {
                            $rowVenda = $resVenda->fetchArray(SQLITE3_ASSOC);
                            if ($rowVenda) {
                                if (isset($rowVenda['saldo_crediario_anterior']) && $rowVenda['saldo_crediario_anterior'] !== null) {
                                    $saldoAnteriorVenda = round((float) $rowVenda['saldo_crediario_anterior'], 2);
                                }
                                if (isset($rowVenda['saldo_crediario_novo']) && $rowVenda['saldo_crediario_novo'] !== null) {
                                    $saldoNovoVenda = round((float) $rowVenda['saldo_crediario_novo'], 2);
                                }
                                if (isset($rowVenda['valor_crediario_venda']) && $rowVenda['valor_crediario_venda'] !== null) {
                                    $valorCrediarioVenda = round((float) $rowVenda['valor_crediario_venda'], 2);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        logMsg('⚠ Falha ao consultar saldo armazenado da venda ' . $vendaId . ': ' . $e->getMessage());
                    }
                }

                $titulos[] = [
                    'id' => $idConta,
                    'situacao' => $situacao,
                    'vencimento' => $t['vencimento'] ?? null,
                    'valor' => $valorTitulo,
                    'restante' => $saldo,
                    'documento' => $documento,
                    'dataEmissao' => $dataEmissao,
                    'origemId' => $origemId !== null ? (string) $origemId : null,
                    'origemTipo' => $origemTipo,
                    'origemNumero' => $origemNumero,
                    'origemUrl' => $origemUrl,
                    'vendaId' => $vendaId,
                    'saldoVendaAnterior' => $saldoAnteriorVenda,
                    'saldoVendaNovo' => $saldoNovoVenda,
                    'valorCrediarioVenda' => $valorCrediarioVenda,
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
