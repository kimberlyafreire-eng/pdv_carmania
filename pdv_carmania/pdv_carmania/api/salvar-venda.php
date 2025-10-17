<?php
require_once __DIR__ . '/../session.php';
header('Content-Type: application/json; charset=utf-8');
$usuarioSessao = isset($_SESSION['usuario']) ? trim((string)$_SESSION['usuario']) : '';

// ✅ Inclui o helper centralizado do token
require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/caixa-helper.php';
require_once __DIR__ . '/lib/vendas-helper.php';

// ⚙️ Caminhos e configs
$logDir  = __DIR__ . '/../logs';
$logFile = "$logDir/salvar-venda.log";
$blingBase = 'https://api.bling.com.br/Api/v3';

// Garante pasta de logs
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

// ⚠️ Handlers globais
set_exception_handler(function ($e) {
    logMsg("Exceção: {$e->getMessage()} em {$e->getFile()}:{$e->getLine()}");
    echo json_encode(['ok'=>false,'erro'=>'Exceção','detalhe'=>$e->getMessage()]);
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    logMsg("Erro PHP [$errno] $errstr em $errfile:$errline");
    echo json_encode(['ok'=>false,'erro'=>'Erro interno','detalhe'=>$errstr]);
    return true;
});

// 📥 Input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) {
    logMsg("JSON inválido: " . substr($raw ?? '', 0, 500));
    echo json_encode(['ok'=>false,'erro'=>'JSON inválido']);
    exit;
}

// 🧩 Dados principais
$carrinho        = $input['carrinho'] ?? [];
$clienteId       = $input['clienteId'] ?? null;
$clienteNome     = $input['clienteNome'] ?? '';
$pagamentos      = $input['pagamentos'] ?? [];
$descontoValor   = (float)($input['descontoValor'] ?? 0);
$descontoPercent = (float)($input['descontoPercent'] ?? 0);
$deposito        = $input['deposito'] ?? null; // 🧱 Depósito selecionado no carrinho
$vendedorId      = $input['vendedorId'] ?? null;
$usuarioPayload  = isset($input['usuarioLogado']) ? trim((string)$input['usuarioLogado']) : '';
$previewOnly     = !empty($input['preview']);
$usuarioRecibo   = $usuarioPayload !== '' ? $usuarioPayload : $usuarioSessao;
$usuarioRecibo   = trim($usuarioRecibo);
if ($usuarioRecibo !== '') {
    $usuarioRecibo = mb_substr($usuarioRecibo, 0, 80);
} else {
    $usuarioRecibo = null;
}

$depositoNome = '';
if (is_array($deposito)) {
    $depositoNome = (string)($deposito['nome'] ?? '');
}

if (is_string($vendedorId)) {
    $vendedorId = trim($vendedorId);
}
if ($vendedorId === '' || $vendedorId === null) {
    $vendedorId = null;
} else {
    $vendedorId = (int)$vendedorId;
    if ($vendedorId <= 0) {
        $vendedorId = null;
    }
}

logMsg('🧑‍💼 Vendedor associado: ' . ($vendedorId ?? 'nenhum'));
logMsg('👤 Atendente: ' . ($usuarioRecibo ?? 'não informado'));

if (empty($carrinho) || !$clienteId) {
    logMsg("Carrinho ou cliente ausente: " . json_encode($input));
    echo json_encode(['ok'=>false,'erro'=>'Carrinho ou cliente ausente']);
    exit;
}

// 🔐 Token (não necessário para preview)
if (!$previewOnly) {
    $access_token = getAccessToken();
    if (!$access_token) die(json_encode(['erro'=>'Access token inválido ou não pôde ser atualizado']));
}

// 🚀 Função de requisições ao Bling com renovação automática
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
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers
    ]);
    if ($body !== null)
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logMsg("[$method] $url (HTTP $http)");
    if ($body) logMsg("Payload: " . json_encode($body, JSON_UNESCAPED_UNICODE));
    if ($resp) logMsg("Resp: $resp");

    if (in_array($http, [401, 403])) {
        logMsg("⚠ Token expirado — renovando automaticamente");
        refreshAccessToken();
        return bling_request($method, $path, $body);
    }

    return ['http' => $http, 'body' => $resp];
}

function pagamentoEhDinheiro(array $pagamento): bool
{
    $idValor = $pagamento['id'] ?? null;
    if ($idValor !== null) {
        $idString = trim((string) $idValor);
        if ($idString !== '' && (int) $idString === 2009802) {
            return true;
        }
    }

    $nome = strtolower(trim((string) ($pagamento['forma'] ?? $pagamento['nome'] ?? '')));
    if ($nome !== '' && mb_stripos($nome, 'dinheiro') !== false) {
        return true;
    }

    return false;
}

// 💰 Cálculo dos totais
$totalBruto = 0;
$totalItensLista = 0;
$totalQuantidadeLista = 0;
foreach ($carrinho as $it) {
    $quantidadeItem = (int) $it['quantidade'];
    $totalBruto += (float) $it['preco'] * $quantidadeItem;
    $totalItensLista++;
    $totalQuantidadeLista += $quantidadeItem;
}
$totalBruto = round($totalBruto, 2);

if ($descontoValor > 0) {
    $totalFinal = max(0, $totalBruto - $descontoValor);
} elseif ($descontoPercent > 0) {
    $totalFinal = $totalBruto * (1 - $descontoPercent/100);
} else {
    $totalFinal = $totalBruto;
}
$totalFinal = round($totalFinal, 2);
$descontoAplicado = max(0, round($totalBruto - $totalFinal, 2));

// 🧾 Itens
$itensPayload = [];
foreach ($carrinho as $it) {
    $itensPayload[] = [
        'produto'    => ['id' => (int)$it['id']],
        'quantidade' => (int)$it['quantidade'],
        'valor'      => round((float)$it['preco'], 2)
    ];
}

// 💳 Parcelas
$parcelas = [];
$nomesFormas = [];
$trocoTotalInformado = round((float)($input['trocoTotal'] ?? 0), 2);
$trocoTotalCalculado = 0.0;

if (!is_array($pagamentos)) {
    $pagamentos = [];
}

foreach ($pagamentos as &$pagamentoNormalizado) {
    $valorAplicado = round((float)($pagamentoNormalizado['valor'] ?? 0), 2);
    $valorInformado = isset($pagamentoNormalizado['valorInformado'])
        ? round((float)$pagamentoNormalizado['valorInformado'], 2)
        : $valorAplicado;
    if ($valorAplicado < 0) {
        $valorAplicado = 0.0;
    }
    if ($valorInformado < $valorAplicado) {
        $valorInformado = $valorAplicado;
    }
    $trocoPagamento = isset($pagamentoNormalizado['troco'])
        ? max(0.0, round((float)$pagamentoNormalizado['troco'], 2))
        : 0.0;
    if ($trocoPagamento > $valorInformado) {
        $trocoPagamento = $valorInformado;
    }

    $pagamentoNormalizado['valor'] = $valorAplicado;
    $pagamentoNormalizado['valorInformado'] = $valorInformado;
    $pagamentoNormalizado['troco'] = $trocoPagamento;

    $trocoTotalCalculado += $trocoPagamento;
}
unset($pagamentoNormalizado);

$trocoTotalCalculado = round($trocoTotalCalculado, 2);
if (abs($trocoTotalCalculado - $trocoTotalInformado) > 0.01) {
    logMsg("ℹ️ Divergência entre troco informado ({$trocoTotalInformado}) e calculado ({$trocoTotalCalculado}).");
}

$valorTotalDinheiroInformado = 0.0;
foreach ($pagamentos as $p) {
    if (!is_array($p) || !pagamentoEhDinheiro($p)) {
        continue;
    }
    $valorInformadoDinheiro = isset($p['valorInformado'])
        ? round((float) $p['valorInformado'], 2)
        : round((float) ($p['valor'] ?? 0), 2);
    if ($valorInformadoDinheiro > 0) {
        $valorTotalDinheiroInformado += $valorInformadoDinheiro;
    }
}
$valorTotalDinheiroInformado = round($valorTotalDinheiroInformado, 2);

if (!empty($pagamentos)) {
    foreach ($pagamentos as $p) {
        $id    = (int)($p['id'] ?? 0);
        $nome  = $p['forma'] ?? $p['nome'] ?? "Forma $id";
        $valor = round((float)($p['valor'] ?? 0), 2);
        $valorInformado = round((float)($p['valorInformado'] ?? $valor), 2);
        $trocoPagamento = round((float)($p['troco'] ?? 0), 2);

        $nomesFormas[] = [
            'nome' => $nome,
            'valor' => $valorInformado,
            'valorAplicado' => $valor,
            'troco' => $trocoPagamento
        ];

        if (!$previewOnly && $id > 0 && $valor > 0) {
            $parcelas[] = [
                'dataVencimento' => date('Y-m-d'),
                'valor'          => $valor,
                'formaPagamento' => ['id' => $id],
                'observacoes'    => 'Pagamento via PDV Carmania'
            ];
        }
    }
} elseif (!$previewOnly) {
    echo json_encode(['ok'=>false,'erro'=>'Nenhuma forma de pagamento']);
    exit;
}

if (!$previewOnly && empty($parcelas)) {
    echo json_encode(['ok'=>false,'erro'=>'Nenhuma forma de pagamento válida']);
    exit;
}

$isCrediario = false;
foreach ($pagamentos as $p) {
    $nomeForma = strtolower(trim($p['forma'] ?? $p['nome'] ?? ''));
    if (strpos($nomeForma, 'crediario') !== false || strpos($nomeForma, 'crediário') !== false) {
        $isCrediario = true;
        logMsg("💳 Venda identificada como CREDIÁRIO");
        break;
    }
}

// 🧱 Fluxo completo só ocorre quando não é preview
$pedidoId = null;
$rj = null;

if (!$previewOnly) {
    // 🧱 Payload principal do pedido
    $pedidoPayload = [
        'data'        => date('Y-m-d'),
        'contato'     => ['id' => (int)$clienteId],
        'itens'       => $itensPayload,
        'parcelas'    => $parcelas,
        'observacoes' => 'Venda via PDV Carmania ' . time(),
        'totalvenda'  => $totalFinal,
        'situacao'    => ['id' => 9] // ✅ "Atendido"
    ];

    if ($vendedorId) {
        $pedidoPayload['vendedor'] = ['id' => $vendedorId];
    }

    // Adiciona desconto se houver
    if ($descontoAplicado > 0) {
        $pedidoPayload['desconto'] = [
            'valor'   => $descontoAplicado,
            'unidade' => 'REAL'
        ];
    }

    // 🚀 1️⃣ Envia pedido
    $res = bling_request('POST', 'pedidos/vendas', $pedidoPayload);
    if ($res['http'] < 200 || $res['http'] >= 300) {
        $body = json_decode($res['body'], true);
        $erro = $body['error']['fields'][0]['msg'] ?? $res['body'];
        echo json_encode(['ok'=>false,'erro'=>'Falha ao criar pedido','detalhe'=>$erro]);
        exit;
    }

    $rj = json_decode($res['body'], true);
    $pedidoId = $rj['data']['id'] ?? null;
    logMsg("✅ Pedido criado com ID $pedidoId");

    logMsg("🔍 Formas recebidas: " . json_encode($pagamentos));

    /* =====================================================
       🚀 1. LANÇAR ESTOQUE AUTOMATICAMENTE
       ===================================================== */
    $depositoId = $input['deposito']['id'] ?? $input['depositoId'] ?? null;
    if ($pedidoId && $depositoId) {
        logMsg("📦 Lançando estoque do pedido $pedidoId para depósito $depositoId...");
        $resEstoque = bling_request('POST', "pedidos/vendas/{$pedidoId}/lancar-estoque/{$depositoId}");
        $jsonEstoque = json_decode($resEstoque['body'], true);

        if ($resEstoque['http'] >= 200 && $resEstoque['http'] < 300) {
            logMsg("✅ Estoque lançado com sucesso no depósito $depositoId");
        } else {
            logMsg("❌ Falha ao lançar estoque (HTTP {$resEstoque['http']}) -> " . json_encode($jsonEstoque));
        }
    }

    /* =====================================================
       💰 2. LANÇAR CONTAS A RECEBER E BAIXAR AUTOMÁTICO
       ===================================================== */
    if ($pedidoId) {
        logMsg("💰 Lançando contas a receber para o pedido $pedidoId...");

        // 1️⃣ Lança o contas a receber
        $resContas = bling_request('POST', "pedidos/vendas/{$pedidoId}/lancar-contas");
        logMsg("Resp contas: HTTP {$resContas['http']}");

        if ($resContas['http'] >= 200 && $resContas['http'] < 300) {
            logMsg("✅ Contas a receber lançado com sucesso (pedido $pedidoId)");

            // 2️⃣ IDs das formas com baixa automática (Pix, Dinheiro, Crédito, Débito)
            $formasBaixaAutoIds = [7697682, 2009802, 2941151, 2941150];
            $baixar = false;

            foreach ($pagamentos as $p) {
                $idForma = (int)($p['id'] ?? 0);
                if (in_array($idForma, $formasBaixaAutoIds)) {
                    $baixar = true;
                    break;
                }
            }

            // 3️⃣ Baixa automática se aplicável
            if ($baixar) {
                logMsg("⚙️ Formas de pagamento com baixa automática detectadas. Aguardando geração das contas...");
                sleep(2); // Aguarda o Bling criar as contas vinculadas

                $dataInicial = date('Y-m-d', strtotime('-1 day'));
                $dataFinal   = date('Y-m-d');
                $resBusca = bling_request('GET', "contas/receber?dataEmissaoInicial={$dataInicial}&dataEmissaoFinal={$dataFinal}");
                logMsg("Resp contas/receber: HTTP {$resBusca['http']}");

                $jsonBusca = json_decode($resBusca['body'], true);
                if (isset($jsonBusca['data']) && is_array($jsonBusca['data'])) {
                    $contaEncontrada = null;
                    foreach ($jsonBusca['data'] as $conta) {
                        $tipoOrigem = strtolower($conta['origem']['tipoOrigem'] ?? $conta['origem']['tipo'] ?? '');
                        $origemId   = (string)($conta['origem']['id'] ?? '');
                        if ($tipoOrigem === 'venda' && $origemId === (string)$pedidoId) {
                            $contaEncontrada = $conta;
                            break;
                        }
                    }

                    if ($contaEncontrada) {
                        $contaId = $contaEncontrada['id'];
                        $valorBaixa = $contaEncontrada['saldo'] ?? $contaEncontrada['valor'] ?? 0;
                        logMsg("💾 Conta vinculada ao pedido encontrada: ID {$contaId}, valor R$ {$valorBaixa}");

                        // 4️⃣ Faz a baixa da conta
                        $resBaixa = bling_request('POST', "contas/receber/{$contaId}/baixar", [
                            'data' => date('Y-m-d'),
                            'valor' => $valorBaixa,
                            'observacoes' => 'Baixa automática via PDV Carmania'
                        ]);

                        if ($resBaixa['http'] >= 200 && $resBaixa['http'] < 300) {
                            logMsg("💸 Conta $contaId baixada com sucesso (R$ {$valorBaixa}).");
                        } else {
                            logMsg("⚠️ Falha ao baixar conta $contaId (HTTP {$resBaixa['http']}) -> {$resBaixa['body']}");
                        }
                    } else {
                        logMsg("⚠️ Nenhuma conta vinculada ao pedido $pedidoId encontrada nas contas recentes.");
                    }
                } else {
                    logMsg("⚠️ Nenhuma conta retornada pelo endpoint /contas/receber. Body -> {$resBusca['body']}");
                }
            } else {
                logMsg("ℹ️ Nenhuma forma de pagamento com baixa automática detectada.");
            }
        } else {
            logMsg("❌ Falha ao lançar contas (HTTP {$resContas['http']}) -> {$resContas['body']}");
        }
    }

    /* =====================================================
       💵 3. REGISTRAR VENDA EM DINHEIRO NO CAIXA
       ===================================================== */
    $depositoIdCaixa = $depositoId ?? ($input['depositoId'] ?? null);
    if ($valorTotalDinheiroInformado > 0.01) {
        if ($depositoIdCaixa) {
            $usuarioParaCaixa = $usuarioSessao !== '' ? $usuarioSessao : $usuarioPayload;
            $observacaoVenda = 'Venda em dinheiro';
            if ($pedidoId) {
                $observacaoVenda .= ' ' . $pedidoId;
            }
            try {
                registrarMovimentacaoCaixa(
                    (string)$depositoIdCaixa,
                    $depositoNome,
                    'venda',
                    $valorTotalDinheiroInformado,
                    $observacaoVenda,
                    $usuarioParaCaixa !== '' ? $usuarioParaCaixa : null
                );
                logMsg("🧾 Venda em dinheiro registrada no caixa do depósito {$depositoIdCaixa}: R$ {$valorTotalDinheiroInformado}");
            } catch (Throwable $e) {
                logMsg('⚠️ Falha ao registrar venda em dinheiro no caixa: ' . $e->getMessage());
            }
        } else {
            logMsg('⚠️ Venda em dinheiro identificada, porém sem depósito válido para registrar no caixa.');
        }
    }

    /* =====================================================
       💸 4. REGISTRAR TROCO NO CAIXA (QUANDO EXISTIR)
       ===================================================== */
    if ($trocoTotalCalculado > 0.01) {
        if ($depositoIdCaixa) {
            $usuarioParaCaixa = $usuarioSessao !== '' ? $usuarioSessao : $usuarioPayload;
            $observacaoTroco = 'Troco devolvido na venda';
            if ($pedidoId) {
                $observacaoTroco .= ' ' . $pedidoId;
            }
            try {
                registrarMovimentacaoCaixa(
                    (string)$depositoIdCaixa,
                    $depositoNome,
                    'troco',
                    $trocoTotalCalculado,
                    $observacaoTroco,
                    $usuarioParaCaixa !== '' ? $usuarioParaCaixa : null
                );
                logMsg("💸 Troco registrado no caixa do depósito {$depositoIdCaixa}: R$ {$trocoTotalCalculado}");
            } catch (Throwable $e) {
                logMsg('⚠️ Falha ao registrar troco no caixa: ' . $e->getMessage());
            }
        } else {
            logMsg('⚠️ Troco identificado, porém sem depósito válido para registrar no caixa.');
        }
    }

    // 🗃️ Persiste a venda no banco local
    if ($pedidoId) {
        $situacaoRegistrada = (int) ($rj['data']['situacao']['id'] ?? 9);
        $usuarioResponsavel = $usuarioSessao !== '' ? $usuarioSessao : $usuarioPayload;

        try {
            registrarVendaLocal([
                'id' => (int) $pedidoId,
                'data_hora' => date('Y-m-d H:i:s'),
                'contato_id' => $clienteId ? (int) $clienteId : null,
                'contato_nome' => $clienteNome,
                'usuario_login' => $usuarioResponsavel,
                'usuario_nome' => $usuarioRecibo,
                'deposito_id' => $depositoIdCaixa ? (int) $depositoIdCaixa : null,
                'deposito_nome' => $depositoNome,
                'situacao_id' => $situacaoRegistrada > 0 ? $situacaoRegistrada : 9,
                'valor_total' => $totalFinal,
                'valor_desconto' => $descontoAplicado,
            ], $pagamentos, $carrinho);
            logMsg('💾 Venda registrada no banco local.');
        } catch (Throwable $e) {
            logMsg('⚠️ Falha ao registrar venda localmente: ' . $e->getMessage());
        }
    }
}

// 🧾 Recibo HTML
$itensHtml = '';
$atendenteHtml = '';
if ($usuarioRecibo) {
    $atendenteHtml = "  <p style='margin:2px 0;'>Atendente: <b>" . htmlspecialchars($usuarioRecibo, ENT_QUOTES, 'UTF-8') . "</b></p>";
}
foreach ($carrinho as $it) {
    $nome = htmlspecialchars($it['nome'] ?? '', ENT_QUOTES, 'UTF-8');
    $qtd  = (int) $it['quantidade'];
    $sub  = number_format((float) $it['preco'] * $qtd, 2, ',', '.');
    $descricao = trim($qtd . 'x ' . $nome);
    $itensHtml .= "
      <tr class='recibo-item'>
        <td class='recibo-item-desc'>$descricao</td>
        <td class='recibo-item-total'>R$ {$sub}</td>
      </tr>";
}
$resumoItensHtml = '';
if ($totalItensLista > 0) {
    $rotuloItens = $totalItensLista === 1 ? 'item' : 'itens';
    $resumoItens = $totalItensLista . ' ' . $rotuloItens . ' (Qtd ' . $totalQuantidadeLista . ')';
    $resumoItensHtml = "    <p class='recibo-total-itens'>$resumoItens</p>";
}

$formasHtml = '';
foreach ($nomesFormas as $f) {
    $valorPrincipal = number_format($f['valor'],2,',','.');
    $detalheLinha = '';
    if (!empty($f['troco'])) {
        $valorAplicadoFmt = number_format($f['valorAplicado'] ?? $f['valor'], 2, ',', '.');
        $trocoFmt = number_format($f['troco'], 2, ',', '.');
        $detalheLinha = "<br><small style='color:#6c757d;'>Aplicado: R$ {$valorAplicadoFmt} &middot; Troco: R$ {$trocoFmt}</small>";
    }
    $formasHtml .= "
      <tr>
        <td style='text-align:left;'>{$f['nome']}</td>
        <td style='text-align:right;'><b>R$ {$valorPrincipal}</b>{$detalheLinha}</td>
      </tr>";
}

$resumoCrediarioHtml = '';
if ($isCrediario && $clienteId) {
    // Helper p/ chamar saldo.php por POST JSON no mesmo host
    $callSaldo = function(string $path, array $payload) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url    = $scheme . '://' . $host . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        logMsg("↪️ saldo.php: POST $url (HTTP $http)" . ($err ? " ERR=$err" : ""));
        return [$http, $resp];
    };

    $saldoConsulta = 0.0;
    $payloadSaldo = ['clienteId' => (string)$clienteId];

    // Tenta primeiro /api/crediario/saldo.php, depois /api/saldo.php
    $tentativas = [
        '/pdv_carmania/api/crediario/saldo.php',
        '/pdv_carmania/api/saldo.php',
    ];

    foreach ($tentativas as $path) {
        [$http, $resp] = $callSaldo($path, $payloadSaldo);
        if ($http === 200 && $resp) {
            $jsonSaldo = json_decode($resp, true);
            if (!empty($jsonSaldo['ok']) && isset($jsonSaldo['saldoAtual'])) {
                $saldoConsulta = (float)$jsonSaldo['saldoAtual'];
                logMsg("✅ Saldo obtido via $path -> R$ " . number_format($saldoConsulta, 2, ',', '.'));
                break;
            } else {
                logMsg("⚠️ Resposta inesperada de $path -> $resp");
            }
        } else {
            logMsg("⚠️ Falha ao consultar $path (HTTP $http) -> $resp");
        }
    }

    $saldoAnteriorEstimado = round($saldoConsulta - $totalFinal, 2);
    if (abs($saldoAnteriorEstimado) < 0.01) {
        $saldoAnteriorEstimado = 0.0;
    }

    $novoSaldo = $saldoConsulta;

    // Bloco visual do resumo do crediário (centralizado)
    $resumoCrediarioHtml = "
    <div style='
      width:260px;
      margin:10px auto 6px;
      font-family:monospace;
      font-size:13px;
      text-align:center;
      background:#f9f9f9;
      padding:8px;
      border-radius:6px;
      border:1px dashed #aaa;
    '>
      <p style='margin:3px 0;font-weight:bold;'>💳 Resumo do Crediário</p>
      <table style='width:100%;font-size:12px;'>
        <tr><td style='text-align:left;'>Saldo Anterior:</td><td style='text-align:right;'>R$ ".number_format($saldoAnteriorEstimado,2,',','.')."</td></tr>
        <tr><td style='text-align:left;'>Compra Atual:</td><td style='text-align:right;'>R$ ".number_format($totalFinal,2,',','.')."</td></tr>
        <tr><td colspan='2'><hr style='border:0;border-top:1px dashed #ccc;'></td></tr>
        <tr><td style='text-align:left;'><b>Novo Saldo:</b></td><td style='text-align:right;color:#dc3545;'><b>R$ ".number_format($novoSaldo,2,',','.')."</b></td></tr>
      </table>
    </div>";

    logMsg("💳 Saldo estimado anterior: {$saldoAnteriorEstimado} | Compra: {$totalFinal} | Saldo consultado: {$novoSaldo}");
}

$reciboHtml = <<<HTML
<style>
  #recibo-preview-stage {
    width:100vw;
    min-height:100vh;
    height:auto;
    margin:0;
    padding:clamp(8px,4vh,24px) clamp(8px,3vw,24px);
    display:flex;
    justify-content:center;
    align-items:flex-start;
    box-sizing:border-box;
    overflow:auto;
    -webkit-overflow-scrolling:touch;
    background:transparent;
  }
  #recibo-preview {
    width:min(94vw, 70ch);
    max-width:100%;
    font-family:monospace;
    font-size:clamp(12px, 1.9vh, 16px);
    line-height:1.35;
    text-align:left;
    background:#fff;
    color:#111;
    padding:14px 12px 20px;
    box-sizing:border-box;
    margin:0 auto;
  }
  #recibo-preview h4 {
    text-align:center;
  }
  #recibo-preview table {
    width:100%;
    border-collapse:collapse;
    font-size:0.88em;
  }
  #recibo-preview td {
    padding:3px 4px;
    vertical-align:top;
  }
  #recibo-preview .recibo-item-desc {
    text-align:left;
    word-break:break-word;
  }
  #recibo-preview .recibo-item-total {
    text-align:right;
    white-space:nowrap;
  }
  #recibo-preview td:last-child {
    text-align:right;
  }
  #recibo-preview .recibo-total-itens {
    margin:0 0 6px;
    font-weight:bold;
  }
  #recibo-preview .recibo-divider {
    border:0;
    border-top:1px solid #e0e0e0;
    margin:10px 0;
  }
  @media (max-width: 768px) {
    #recibo-preview-stage {
      padding:clamp(12px,6vh,32px) clamp(10px,6vw,28px);
    }
    #recibo-preview {
      font-size:clamp(13px, 4.2vw, 18px);
    }
  }
</style>
<div id='recibo-preview-stage'>
  <div id='recibo-preview'>
    <h4 style='margin:6px 0;color:#dc3545;'>Carmania Produtos Automotivos</h4>
HTML;

$reciboHtml .= $atendenteHtml;

$reciboHtml .= "    <p style='margin:2px 0;'>Pedido: <b>" . ($pedidoId ?? '-') . "</b></p>";
$reciboHtml .= "    <p style='margin:2px 0;'>Cliente: <b>" . htmlspecialchars($clienteNome, ENT_QUOTES, 'UTF-8') . "</b></p>";
$reciboHtml .= "    <hr class='recibo-divider'>";
$reciboHtml .= $resumoItensHtml;
$reciboHtml .= "    <table style='margin-bottom:6px;'>$itensHtml</table>";
$reciboHtml .= "    <hr class='recibo-divider'>";
$reciboHtml .= "    <table>";
$reciboHtml .= "      <tr><td>Total Bruto</td><td>R$ " . number_format($totalBruto, 2, ',', '.') . "</td></tr>";
if ($descontoAplicado>0) {
    $reciboHtml .= "      <tr><td>Desconto</td><td>R$ " . number_format($descontoAplicado, 2, ',', '.') . "</td></tr>";
}
$reciboHtml .= "      <tr><td colspan='2'><hr class='recibo-divider'></td></tr>";
$reciboHtml .= "      <tr><td><b>Total Final</b></td><td><b>R$ " . number_format($totalFinal, 2, ',', '.') . "</b></td></tr>";
$reciboHtml .= "    </table>";
$reciboHtml .= "    <hr class='recibo-divider'>";
$reciboHtml .= "    <p style='margin:3px 0; font-weight:bold;'>Pagamentos</p>";
$reciboHtml .= "    <table>$formasHtml</table>";
$reciboHtml .= $resumoCrediarioHtml;
$reciboHtml .= "    <hr class='recibo-divider'>";
$reciboHtml .= "    <p style='margin:2px 0;'>Estoque: <b>" . htmlspecialchars($deposito['nome'] ?? 'Não informado', ENT_QUOTES, 'UTF-8') . "</b></p>";
$reciboHtml .= "    <hr class='recibo-divider'>";
$reciboHtml .= "    <p style='margin:5px 0; font-size:0.9em; color:#222;'>Obrigado pela preferência!</p>";
$reciboHtml .= "  </div>";
$reciboHtml .= "</div>";

echo json_encode([
    'ok'=>true,
    'pedidoId'=>$pedidoId,
    'reciboHtml'=>$reciboHtml,
    'preview'=>$previewOnly
], JSON_UNESCAPED_UNICODE);
