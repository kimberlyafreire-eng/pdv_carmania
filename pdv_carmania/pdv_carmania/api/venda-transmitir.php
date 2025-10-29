<?php
require_once __DIR__ . '/../session.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Não autorizado']);
    exit();
}

require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/vendas-helper.php';
require_once __DIR__ . '/lib/caixa-helper.php';
require_once __DIR__ . '/lib/crediario-helper.php';

$logDir = __DIR__ . '/../logs';
$logFile = $logDir . '/venda-transmitir.log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function logTransmitir(string $mensagem): void
{
    global $logFile;
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    file_put_contents($logFile, $linha, FILE_APPEND);
}

function bling_request(string $method, string $path, $body = null): array
{
    $blingBase = 'https://api.bling.com.br/Api/v3';
    $url = rtrim($blingBase, '/') . '/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . getAccessToken(),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40,
    ]);

    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        logTransmitir('Payload: ' . $payload);
    }

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    logTransmitir(sprintf('[%s] %s -> HTTP %d%s', strtoupper($method), $url, $http, $err ? " ERR=$err" : ''));
    if ($resp) {
        logTransmitir('Resp: ' . $resp);
    }

    if (in_array($http, [401, 403], true)) {
        logTransmitir('⚠️ Token expirado. Renovando e tentando novamente.');
        refreshAccessToken();
        return bling_request($method, $path, $body);
    }

    return ['http' => $http, 'body' => $resp];
}

function pagamentoEhBoleto(array $pagamento): bool
{
    $id = isset($pagamento['id']) ? (int) $pagamento['id'] : null;
    return formaPagamentoEhBoletoPorId($id);
}

function formaPagamentoEhBoletoPorId($idValor): bool
{
    if ($idValor === null) {
        return false;
    }
    $idString = trim((string) $idValor);
    if ($idString === '') {
        return false;
    }
    $idsBoleto = ['7697681', '2009804'];
    return in_array($idString, $idsBoleto, true);
}

function formaPagamentoEhDinheiroPorId($idValor): bool
{
    if ($idValor === null) {
        return false;
    }
    $idString = trim((string) $idValor);
    if ($idString === '') {
        return false;
    }
    $idsDinheiro = ['2941151', '2941150'];
    return in_array($idString, $idsDinheiro, true);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'erro' => 'JSON inválido']);
    exit();
}

$vendaId = isset($input['id']) ? (int) $input['id'] : 0;
if ($vendaId === 0) {
    echo json_encode(['ok' => false, 'erro' => 'ID de venda inválido']);
    exit();
}

try {
    $db = getVendasDb();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao acessar banco local', 'detalhe' => $e->getMessage()]);
    exit();
}

try {
    $stmt = $db->prepare('SELECT * FROM vendas WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $vendaId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $venda = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

    if (!$venda) {
        echo json_encode(['ok' => false, 'erro' => 'Venda não encontrada no banco local.']);
        exit();
    }

    if (!empty($venda['transmitido'])) {
        echo json_encode(['ok' => false, 'erro' => 'Esta venda já foi transmitida ao Bling.']);
        exit();
    }

    $clienteId = isset($venda['contato_id']) ? (int) $venda['contato_id'] : 0;
    if ($clienteId <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Venda local não possui cliente vinculado.']);
        exit();
    }

    $stmtPag = $db->prepare('SELECT forma_pagamento_id, forma_pagamento_nome, valor FROM venda_pagamentos WHERE venda_id = :id ORDER BY id');
    $stmtPag->bindValue(':id', $vendaId, SQLITE3_INTEGER);
    $resPag = $stmtPag->execute();
    $pagamentos = [];
    while ($resPag && ($row = $resPag->fetchArray(SQLITE3_ASSOC))) {
        $pagamentos[] = [
            'id' => isset($row['forma_pagamento_id']) ? (int) $row['forma_pagamento_id'] : null,
            'nome' => $row['forma_pagamento_nome'],
            'valor' => (float) ($row['valor'] ?? 0),
        ];
    }

    if (empty($pagamentos)) {
        echo json_encode(['ok' => false, 'erro' => 'Venda sem formas de pagamento salvas.']);
        exit();
    }

    $stmtItens = $db->prepare('SELECT produto_id, produto_nome, valor_unitario, quantidade FROM venda_itens WHERE venda_id = :id ORDER BY id');
    $stmtItens->bindValue(':id', $vendaId, SQLITE3_INTEGER);
    $resItens = $stmtItens->execute();
    $itensParaPayload = [];
    $itensParaPersistir = [];
    while ($resItens && ($row = $resItens->fetchArray(SQLITE3_ASSOC))) {
        $quantidade = (int) ($row['quantidade'] ?? 0);
        if ($quantidade <= 0) {
            continue;
        }
        $produtoId = isset($row['produto_id']) ? (int) $row['produto_id'] : null;
        $valorUnitario = (float) ($row['valor_unitario'] ?? 0);
        $itensParaPayload[] = [
            'id' => $produtoId,
            'nome' => $row['produto_nome'],
            'valor_unitario' => $valorUnitario,
            'quantidade' => $quantidade,
        ];
        $itensParaPersistir[] = [
            'id' => $produtoId,
            'nome' => $row['produto_nome'],
            'preco' => $valorUnitario,
            'quantidade' => $quantidade,
        ];
    }

    if (empty($itensParaPayload)) {
        echo json_encode(['ok' => false, 'erro' => 'Nenhum item encontrado para a venda local.']);
        exit();
    }

    $itensPayload = [];
    $totalBruto = 0.0;
    foreach ($itensParaPayload as $item) {
        if (empty($item['id'])) {
            throw new RuntimeException('Item sem identificação de produto. Não é possível transmitir.');
        }
        $valorUnitario = round((float) $item['valor_unitario'], 2);
        $quantidade = (int) $item['quantidade'];
        $totalBruto += $valorUnitario * $quantidade;
        $itensPayload[] = [
            'produto' => ['id' => (int) $item['id']],
            'quantidade' => $quantidade,
            'valor' => $valorUnitario,
        ];
    }

    $totalFinal = isset($venda['valor_total']) ? (float) $venda['valor_total'] : $totalBruto;
    $descontoAplicado = isset($venda['valor_desconto']) ? (float) $venda['valor_desconto'] : 0.0;
    if ($descontoAplicado <= 0 && $totalBruto > 0 && $totalFinal > 0) {
        $descontoCalculado = round($totalBruto - $totalFinal, 2);
        if ($descontoCalculado > 0.01) {
            $descontoAplicado = $descontoCalculado;
        }
    }

    $parcelas = [];
    $temPagamentoBoleto = false;
    foreach ($pagamentos as $p) {
        $idForma = isset($p['id']) ? (int) $p['id'] : 0;
        $valorParcela = round((float) ($p['valor'] ?? 0), 2);
        if ($idForma > 0 && $valorParcela > 0) {
            $parcelas[] = [
                'dataVencimento' => date('Y-m-d'),
                'valor' => $valorParcela,
                'formaPagamento' => ['id' => $idForma],
                'observacoes' => 'Pagamento via PDV Carmania',
            ];
        }
        if (!$temPagamentoBoleto && $idForma > 0) {
            if (pagamentoEhBoleto(['id' => $idForma])) {
                $temPagamentoBoleto = true;
            }
        }
    }

    if (!$temPagamentoBoleto && !empty($parcelas)) {
        foreach ($parcelas as $parcela) {
            $formaParcelaId = $parcela['formaPagamento']['id'] ?? null;
            if (formaPagamentoEhBoletoPorId($formaParcelaId)) {
                $temPagamentoBoleto = true;
                break;
            }
        }
    }

    $dataVenda = !empty($venda['data_hora']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $venda['data_hora'])
        ? substr((string) $venda['data_hora'], 0, 10)
        : date('Y-m-d');

    $pedidoPayload = [
        'data' => $dataVenda,
        'contato' => ['id' => $clienteId],
        'itens' => $itensPayload,
        'parcelas' => $parcelas,
        'observacoes' => 'Venda via PDV Carmania (retransmitida)',
        'totalvenda' => round($totalFinal, 2),
        'situacao' => ['id' => 9],
    ];

    $vendedorId = isset($venda['usuario_id']) ? (int) $venda['usuario_id'] : 0;
    if ($vendedorId > 0) {
        $pedidoPayload['vendedor'] = ['id' => $vendedorId];
    }

    if ($descontoAplicado > 0.0) {
        $pedidoPayload['desconto'] = [
            'valor' => round($descontoAplicado, 2),
            'unidade' => 'REAL',
        ];
    }

    if (!getAccessToken()) {
        throw new RuntimeException('Access token inválido ou não pôde ser atualizado');
    }

    $resBling = bling_request('POST', 'pedidos/vendas', $pedidoPayload);
    if ($resBling['http'] === 429) {
        echo json_encode(['ok' => false, 'erro' => 'Limite de requisições atingido. Aguarde alguns instantes e tente novamente.']);
        exit();
    }
    if ($resBling['http'] < 200 || $resBling['http'] >= 300) {
        $bodyErro = json_decode($resBling['body'], true);
        $mensagem = $bodyErro['error']['message'] ?? $resBling['body'] ?? 'Falha ao criar pedido.';
        http_response_code($resBling['http'] >= 400 ? $resBling['http'] : 502);
        echo json_encode(['ok' => false, 'erro' => 'Falha ao criar pedido', 'detalhe' => $mensagem]);
        exit();
    }

    $jsonPedido = json_decode($resBling['body'], true);
    $pedidoId = $jsonPedido['data']['id'] ?? null;
    if (!$pedidoId) {
        throw new RuntimeException('Resposta do Bling não retornou o ID do pedido.');
    }

    logTransmitir("✅ Pedido criado com ID {$pedidoId} na retransmissão.");

    $depositoId = isset($venda['deposito_id']) ? (int) $venda['deposito_id'] : 0;
    if ($pedidoId && $depositoId > 0) {
        logTransmitir("📦 Lançando estoque do pedido {$pedidoId} no depósito {$depositoId}");
        $resEstoque = bling_request('POST', "pedidos/vendas/{$pedidoId}/lancar-estoque/{$depositoId}");
        if ($resEstoque['http'] >= 200 && $resEstoque['http'] < 300) {
            logTransmitir('✅ Estoque lançado com sucesso.');
        } else {
            logTransmitir('❌ Falha ao lançar estoque: ' . ($resEstoque['body'] ?? '')); 
        }
    }

    if ($pedidoId && !$temPagamentoBoleto) {
        logTransmitir("💰 Lançando contas a receber para o pedido {$pedidoId}");
        $resContas = bling_request('POST', "pedidos/vendas/{$pedidoId}/lancar-contas");
        if ($resContas['http'] >= 200 && $resContas['http'] < 300) {
            logTransmitir('✅ Contas a receber lançadas com sucesso.');

            $formasBaixaAutoIds = [7697682, 2009802, 2941151, 2941150];
            $baixarAutomatico = false;
            foreach ($pagamentos as $p) {
                $idForma = isset($p['id']) ? (int) $p['id'] : 0;
                if (in_array($idForma, $formasBaixaAutoIds, true)) {
                    $baixarAutomatico = true;
                    break;
                }
            }

            if ($baixarAutomatico) {
                sleep(2);
                $dataInicial = date('Y-m-d', strtotime('-1 day'));
                $dataFinal = date('Y-m-d');
                $resBusca = bling_request('GET', "contas/receber?dataEmissaoInicial={$dataInicial}&dataEmissaoFinal={$dataFinal}");
                if ($resBusca['http'] >= 200 && $resBusca['http'] < 300) {
                    $jsonBusca = json_decode($resBusca['body'], true);
                    if (isset($jsonBusca['data']) && is_array($jsonBusca['data'])) {
                        foreach ($jsonBusca['data'] as $conta) {
                            $tipoOrigem = strtolower($conta['origem']['tipoOrigem'] ?? $conta['origem']['tipo'] ?? '');
                            $origemId = (string) ($conta['origem']['id'] ?? '');
                            if ($tipoOrigem === 'venda' && $origemId === (string) $pedidoId) {
                                $contaId = $conta['id'];
                                $valorBaixa = $conta['saldo'] ?? $conta['valor'] ?? 0;
                                $resBaixa = bling_request('POST', "contas/receber/{$contaId}/baixar", [
                                    'data' => date('Y-m-d'),
                                    'valor' => $valorBaixa,
                                    'observacoes' => 'Baixa automática via retransmissão PDV Carmania',
                                ]);
                                if ($resBaixa['http'] >= 200 && $resBaixa['http'] < 300) {
                                    logTransmitir("💸 Conta {$contaId} baixada automaticamente.");
                                } else {
                                    logTransmitir('⚠️ Falha ao baixar conta: ' . ($resBaixa['body'] ?? ''));
                                }
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            logTransmitir('❌ Falha ao lançar contas a receber: ' . ($resContas['body'] ?? ''));
        }
    }

    $dadosPersistir = [
        'id' => (int) $pedidoId,
        'data_hora' => $venda['data_hora'] ?? date('Y-m-d H:i:s'),
        'contato_id' => $clienteId,
        'contato_nome' => $venda['contato_nome'] ?? '',
        'usuario_login' => $venda['usuario_login'] ?? null,
        'usuario_nome' => $venda['usuario_nome'] ?? null,
        'usuario_id' => $vendedorId > 0 ? $vendedorId : null,
        'deposito_id' => $depositoId > 0 ? $depositoId : null,
        'deposito_nome' => $venda['deposito_nome'] ?? null,
        'situacao_id' => 9,
        'valor_total' => $totalFinal,
        'valor_desconto' => $descontoAplicado,
        'saldo_crediario_anterior' => $venda['saldo_crediario_anterior'] ?? null,
        'saldo_crediario_novo' => $venda['saldo_crediario_novo'] ?? null,
        'valor_crediario_venda' => $venda['valor_crediario_venda'] ?? null,
        'transmitido' => 1,
    ];

    $db->exec('BEGIN');
    try {
        registrarVendaLocal($dadosPersistir, $pagamentos, $itensParaPersistir);
        $stmtDelete = $db->prepare('DELETE FROM vendas WHERE id = :id');
        $stmtDelete->bindValue(':id', $vendaId, SQLITE3_INTEGER);
        $stmtDelete->execute();
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    echo json_encode([
        'ok' => true,
        'pedidoId' => $pedidoId,
        'mensagem' => "Venda transmitida com sucesso. Pedido #{$pedidoId} criado no Bling.",
        'reciboDisponivel' => true,
    ]);
} catch (Throwable $e) {
    logTransmitir('❌ Erro na retransmissão: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao retransmitir venda', 'detalhe' => $e->getMessage()]);
}
