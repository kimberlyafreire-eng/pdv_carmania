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
require_once __DIR__ . '/lib/crediario-helper.php';
require_once __DIR__ . '/lib/recibo-helper.php';

$pedidoId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($pedidoId <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'ID de venda inválido']);
    exit();
}

$logDir = __DIR__ . '/../logs';
$logFile = $logDir . '/venda-recibo.log';
$blingBase = 'https://api.bling.com.br/Api/v3';

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function logRecibo(string $mensagem): void
{
    global $logFile;
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    file_put_contents($logFile, $linha, FILE_APPEND);
}

function normalizarDataHoraVenda($data, $hora = null): ?string
{
    if ($data === null) {
        return null;
    }

    $data = trim((string) $data);
    if ($data === '') {
        return null;
    }

    if ($hora !== null) {
        $hora = trim((string) $hora);
        if ($hora !== '' && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $hora)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                $data .= ' ' . $hora;
            } elseif (!preg_match('/\d{2}:\d{2}/', $data)) {
                $data .= ' ' . $hora;
            }
        }
    }

    $data = str_replace('T', ' ', $data);

    try {
        $dt = new DateTime($data);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function bling_request(string $method, string $path, $body = null): array
{
    global $blingBase;

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
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        logRecibo('Payload: ' . $payload);
    }

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    logRecibo(sprintf('[%s] %s -> HTTP %d%s', strtoupper($method), $url, $http, $err ? " ERR=$err" : ''));
    if ($resp) {
        logRecibo('Resp: ' . $resp);
    }

    if (in_array($http, [401, 403], true)) {
        logRecibo('⚠️ Token expirado. Renovando e tentando novamente.');
        refreshAccessToken();
        return bling_request($method, $path, $body);
    }

    return ['http' => $http, 'body' => $resp];
}

try {
    if (!getAccessToken()) {
        throw new RuntimeException('Access token inválido ou não pôde ser atualizado');
    }

    $res = bling_request('GET', "pedidos/vendas/{$pedidoId}");
    if ($res['http'] !== 200) {
        http_response_code($res['http'] >= 400 ? $res['http'] : 502);
        echo json_encode([
            'ok' => false,
            'erro' => 'Falha ao consultar venda no Bling',
            'detalhe' => $res['body'] ?? '',
        ]);
        exit();
    }

    $json = json_decode($res['body'], true);
    if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
        throw new RuntimeException('Resposta inesperada do Bling para consulta de venda');
    }

    $pedido = $json['data'];
    $clienteNome = trim((string) ($pedido['contato']['nome'] ?? '-'));
    $clienteId = isset($pedido['contato']['id']) && (int) $pedido['contato']['id'] > 0
        ? (int) $pedido['contato']['id']
        : null;
    $totalBruto = (float) ($pedido['totalProdutos'] ?? 0);
    $totalFinal = (float) ($pedido['total'] ?? $totalBruto);

    $descontoAplicado = 0.0;
    if (isset($pedido['desconto']['valor'])) {
        $descontoValor = (float) $pedido['desconto']['valor'];
        $unidade = strtoupper((string) ($pedido['desconto']['unidade'] ?? ''));
        if ($unidade === 'PORCENTAGEM' || $unidade === 'PERCENTUAL') {
            $descontoAplicado = round($totalBruto * ($descontoValor / 100), 2);
        } else {
            $descontoAplicado = round($descontoValor, 2);
        }
    }

    if ($descontoAplicado <= 0.0 && $totalBruto > 0 && $totalFinal > 0) {
        $descontoCalculado = round($totalBruto - $totalFinal, 2);
        if ($descontoCalculado > 0.009) {
            $descontoAplicado = $descontoCalculado;
        }
    }

    $itensRecibo = [];
    if (!empty($pedido['itens']) && is_array($pedido['itens'])) {
        foreach ($pedido['itens'] as $item) {
            $quantidade = (float) ($item['quantidade'] ?? 0);
            if ($quantidade <= 0) {
                continue;
            }
            $valorUnitario = (float) ($item['valor'] ?? 0);
            $descricao = (string) ($item['descricao'] ?? $item['produto']['descricao'] ?? $item['codigo'] ?? 'Produto');
            $itensRecibo[] = [
                'nome' => $descricao,
                'quantidade' => $quantidade,
                'valorUnitario' => $valorUnitario,
                'subtotal' => $valorUnitario * $quantidade,
            ];
        }
    }

    $formasRecibo = [];
    $pagamentosLocal = [];
    $depositoNome = '';
    $atendente = '';
    $saldoCrediarioAnteriorDb = null;
    $saldoCrediarioNovoDb = null;
    $valorCrediarioVendaDb = null;
    $dataHoraVendaRecibo = null;
    $db = null;

    try {
        $db = getVendasDb();
    } catch (Throwable $e) {
        logRecibo('⚠️ Falha ao abrir banco local: ' . $e->getMessage());
    }

    if ($db instanceof SQLite3) {
        try {
            $stmtVenda = $db->prepare('SELECT data_hora, usuario_nome, usuario_login, deposito_nome, saldo_crediario_anterior, saldo_crediario_novo, valor_crediario_venda FROM vendas WHERE id = :id LIMIT 1');
            $stmtVenda->bindValue(':id', $pedidoId, SQLITE3_INTEGER);
            $resVenda = $stmtVenda->execute();
            if ($resVenda) {
                $row = $resVenda->fetchArray(SQLITE3_ASSOC);
                if ($row) {
                    if (!empty($row['data_hora'])) {
                        $dataHoraVendaRecibo = normalizarDataHoraVenda($row['data_hora']);
                    }
                    $atendente = trim((string) ($row['usuario_nome'] ?? ''));
                    if ($atendente === '') {
                        $atendente = trim((string) ($row['usuario_login'] ?? ''));
                    }
                    $depositoNome = trim((string) ($row['deposito_nome'] ?? ''));
                    if (array_key_exists('saldo_crediario_anterior', $row) && $row['saldo_crediario_anterior'] !== null) {
                        $saldoCrediarioAnteriorDb = round((float) $row['saldo_crediario_anterior'], 2);
                    }
                    if (array_key_exists('saldo_crediario_novo', $row) && $row['saldo_crediario_novo'] !== null) {
                        $saldoCrediarioNovoDb = round((float) $row['saldo_crediario_novo'], 2);
                    }
                    if (array_key_exists('valor_crediario_venda', $row) && $row['valor_crediario_venda'] !== null) {
                        $valorCrediarioVendaDb = round((float) $row['valor_crediario_venda'], 2);
                    }
                }
            }

            $stmtPag = $db->prepare('SELECT forma_pagamento_nome, forma_pagamento_id, valor FROM venda_pagamentos WHERE venda_id = :id ORDER BY id');
            $stmtPag->bindValue(':id', $pedidoId, SQLITE3_INTEGER);
            $resPag = $stmtPag->execute();
            while ($resPag && ($row = $resPag->fetchArray(SQLITE3_ASSOC))) {
                $pagamentosLocal[] = [
                    'nome' => (string) ($row['forma_pagamento_nome'] ?? ''),
                    'id' => isset($row['forma_pagamento_id']) ? (int) $row['forma_pagamento_id'] : null,
                    'valor' => (float) ($row['valor'] ?? 0),
                ];
            }

            if (empty($itensRecibo)) {
                $stmtItens = $db->prepare('SELECT produto_nome, quantidade, valor_unitario FROM venda_itens WHERE venda_id = :id ORDER BY id');
                $stmtItens->bindValue(':id', $pedidoId, SQLITE3_INTEGER);
                $resItens = $stmtItens->execute();
                while ($resItens && ($row = $resItens->fetchArray(SQLITE3_ASSOC))) {
                    $quantidade = (int) ($row['quantidade'] ?? 0);
                    if ($quantidade <= 0) {
                        continue;
                    }
                    $valorUnitario = (float) ($row['valor_unitario'] ?? 0);
                    $itensRecibo[] = [
                        'nome' => (string) ($row['produto_nome'] ?? 'Produto'),
                        'quantidade' => $quantidade,
                        'valorUnitario' => $valorUnitario,
                        'subtotal' => $valorUnitario * $quantidade,
                    ];
                }
            }
        } catch (Throwable $e) {
            logRecibo('⚠️ Falha ao consultar dados locais: ' . $e->getMessage());
        }
    }

    if (empty($formasRecibo) && !empty($pagamentosLocal)) {
        foreach ($pagamentosLocal as $p) {
            $nome = trim((string) ($p['nome'] ?? ''));
            $idForma = isset($p['id']) ? (int) $p['id'] : 0;
            if ($nome === '' && $idForma > 0) {
                $nome = 'Forma #' . $idForma;
            }
            $valor = (float) ($p['valor'] ?? 0);
            if ($valor <= 0) {
                continue;
            }
            $formasRecibo[] = [
                'nome' => $nome !== '' ? $nome : 'Forma',
                'valor' => $valor,
                'valorAplicado' => $valor,
                'troco' => 0,
                'id' => $idForma,
            ];
        }
    }

    if (empty($formasRecibo) && !empty($pedido['parcelas']) && is_array($pedido['parcelas'])) {
        foreach ($pedido['parcelas'] as $parcela) {
            $valor = (float) ($parcela['valor'] ?? 0);
            if ($valor <= 0) {
                continue;
            }
            $forma = $parcela['formaPagamento'] ?? [];
            $idForma = isset($forma['id']) ? (int) $forma['id'] : 0;
            $nomeForma = (string) ($forma['descricao'] ?? $forma['nome'] ?? '');
            if ($nomeForma === '' && $idForma > 0) {
                $nomeForma = 'Forma #' . $idForma;
            }
            $formasRecibo[] = [
                'nome' => $nomeForma !== '' ? $nomeForma : 'Forma',
                'valor' => $valor,
                'valorAplicado' => $valor,
                'troco' => 0,
                'id' => $idForma,
            ];
        }
    }

    if (empty($itensRecibo) && !empty($pedido['itens']) && is_array($pedido['itens'])) {
        // fallback já tratado, mas garante pelo menos um item
        foreach ($pedido['itens'] as $item) {
            $descricao = (string) ($item['descricao'] ?? 'Produto');
            $itensRecibo[] = [
                'nome' => $descricao,
                'quantidade' => 1,
                'valorUnitario' => (float) ($item['valor'] ?? 0),
                'subtotal' => (float) ($item['valor'] ?? 0),
            ];
        }
    }

    if ($totalBruto <= 0 && !empty($itensRecibo)) {
        $totalBruto = 0.0;
        foreach ($itensRecibo as $item) {
            $totalBruto += (float) ($item['subtotal'] ?? 0);
        }
        $totalBruto = round($totalBruto, 2);
    }

    if ($totalFinal <= 0) {
        $totalFinal = max(0.0, $totalBruto - $descontoAplicado);
    }

    if ($dataHoraVendaRecibo === null) {
        $horasPossiveis = [];
        foreach (['horaSaida', 'horaPrevista', 'hora'] as $campoHora) {
            if (!empty($pedido[$campoHora])) {
                $horasPossiveis[] = (string) $pedido[$campoHora];
            }
        }
        $horasPossiveis[] = null;

        $camposData = ['dataSaida', 'data', 'dataEmissao', 'dataPrevista', 'dataCriacao', 'dataOperacao', 'createdAt', 'updatedAt'];
        foreach ($camposData as $campoData) {
            if (!isset($pedido[$campoData])) {
                continue;
            }
            $valorData = $pedido[$campoData];
            foreach ($horasPossiveis as $horaPossivel) {
                $normalizado = normalizarDataHoraVenda($valorData, $horaPossivel);
                if ($normalizado !== null) {
                    $dataHoraVendaRecibo = $normalizado;
                    break 2;
                }
            }
        }

        if ($dataHoraVendaRecibo === null && isset($pedido['situacao']['data'])) {
            $dataHoraVendaRecibo = normalizarDataHoraVenda($pedido['situacao']['data']);
        }
    }

    [$temCrediarioFormas, $valorCrediarioDetectado] = analisarPagamentosCrediario($formasRecibo);
    $valorCrediarioVenda = $valorCrediarioVendaDb;
    if ($valorCrediarioVenda === null || $valorCrediarioVenda <= 0) {
        $valorCrediarioVenda = $valorCrediarioDetectado;
    }

    $temCrediarioParcelas = false;
    if ((!$temCrediarioFormas || ($valorCrediarioVenda === null || $valorCrediarioVenda <= 0)) && !empty($pedido['parcelas']) && is_array($pedido['parcelas'])) {
        [$temCrediarioParcelas, $valorCrediarioParcelas] = analisarPagamentosCrediario($pedido['parcelas']);
        if ($valorCrediarioVenda === null || $valorCrediarioVenda <= 0) {
            $valorCrediarioVenda = $valorCrediarioParcelas;
        }
        $temCrediarioFormas = $temCrediarioFormas || $temCrediarioParcelas;
    }

    $isCrediario = ($valorCrediarioVenda !== null && $valorCrediarioVenda > 0.009) || $temCrediarioFormas;

    $resumoCrediarioHtml = '';
    if ($isCrediario && $clienteId && $valorCrediarioVenda !== null && $valorCrediarioVenda > 0) {
        $saldoAnteriorExibido = $saldoCrediarioAnteriorDb;
        $saldoNovoExibido = $saldoCrediarioNovoDb;
        $saldoConsultaAtual = null;

        if ($saldoAnteriorExibido === null || $saldoNovoExibido === null) {
            $saldoConsultaAtual = consultarSaldoCrediarioCliente($clienteId, 'logRecibo');
        }

        if ($saldoAnteriorExibido === null) {
            if ($saldoConsultaAtual !== null) {
                $saldoAnteriorExibido = round($saldoConsultaAtual - $valorCrediarioVenda, 2);
            } else {
                $saldoAnteriorExibido = 0.0;
            }
        }

        if ($saldoAnteriorExibido < 0 && abs($saldoAnteriorExibido) > 0.01) {
            $saldoAnteriorExibido = 0.0;
        }
        if (abs($saldoAnteriorExibido) < 0.01) {
            $saldoAnteriorExibido = 0.0;
        }

        if ($saldoNovoExibido === null) {
            if ($saldoConsultaAtual !== null) {
                $saldoNovoExibido = round((float) $saldoConsultaAtual, 2);
            } else {
                $saldoNovoExibido = round($saldoAnteriorExibido + $valorCrediarioVenda, 2);
            }
        }

        $saldoEstimado = round($saldoAnteriorExibido + $valorCrediarioVenda, 2);
        if ($saldoNovoExibido < $saldoEstimado - 0.01) {
            $saldoNovoExibido = $saldoEstimado;
        }
        if (abs($saldoNovoExibido) < 0.01) {
            $saldoNovoExibido = 0.0;
        }

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
        <tr><td style='text-align:left;'>Saldo Anterior:</td><td style='text-align:right;'>R$ " . number_format($saldoAnteriorExibido, 2, ',', '.') . "</td></tr>
        <tr><td style='text-align:left;'>Compra Atual:</td><td style='text-align:right;'>R$ " . number_format($valorCrediarioVenda, 2, ',', '.') . "</td></tr>
        <tr><td colspan='2'><hr style='border:0;border-top:1px dashed #ccc;'></td></tr>
        <tr><td style='text-align:left;'><b>Novo Saldo:</b></td><td style='text-align:right;color:#dc3545;'><b>R$ " . number_format($saldoNovoExibido, 2, ',', '.') . "</b></td></tr>
      </table>
    </div>";

        logRecibo('💳 Resumo crediário histórico -> anterior exibido: ' . $saldoAnteriorExibido . ' | valor venda: ' . $valorCrediarioVenda . ' | saldo armazenado novo: ' . ($saldoCrediarioNovoDb !== null ? (string) $saldoCrediarioNovoDb : 'n/d') . ' | saldo consultado atual: ' . ($saldoConsultaAtual !== null ? (string) $saldoConsultaAtual : 'n/d') . ' | novo exibido: ' . $saldoNovoExibido);
    }

    $dadosRecibo = [
        'pedidoId' => $pedidoId,
        'clienteNome' => $clienteNome,
        'atendente' => $atendente,
        'depositoNome' => $depositoNome,
        'totalBruto' => $totalBruto,
        'descontoAplicado' => $descontoAplicado,
        'totalFinal' => $totalFinal,
        'itens' => $itensRecibo,
        'formas' => $formasRecibo,
        'resumoCrediarioHtml' => $resumoCrediarioHtml,
    ];

    if ($dataHoraVendaRecibo !== null) {
        $dadosRecibo['dataHoraVenda'] = $dataHoraVendaRecibo;
    }

    $reciboHtml = gerarReciboHtml($dadosRecibo);

    echo json_encode([
        'ok' => true,
        'reciboHtml' => $reciboHtml,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    logRecibo('❌ Erro inesperado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'Erro ao gerar recibo',
        'detalhe' => $e->getMessage(),
    ]);
}
