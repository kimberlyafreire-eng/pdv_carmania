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
    $db = null;

    try {
        $db = getVendasDb();
    } catch (Throwable $e) {
        logRecibo('⚠️ Falha ao abrir banco local: ' . $e->getMessage());
    }

    if ($db instanceof SQLite3) {
        try {
            $stmtVenda = $db->prepare('SELECT usuario_nome, usuario_login, deposito_nome FROM vendas WHERE id = :id LIMIT 1');
            $stmtVenda->bindValue(':id', $pedidoId, SQLITE3_INTEGER);
            $resVenda = $stmtVenda->execute();
            if ($resVenda) {
                $row = $resVenda->fetchArray(SQLITE3_ASSOC);
                if ($row) {
                    $atendente = trim((string) ($row['usuario_nome'] ?? ''));
                    if ($atendente === '') {
                        $atendente = trim((string) ($row['usuario_login'] ?? ''));
                    }
                    $depositoNome = trim((string) ($row['deposito_nome'] ?? ''));
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

    $isCrediario = false;
    foreach ($formasRecibo as $forma) {
        $nomeLower = strtolower((string) ($forma['nome'] ?? ''));
        if (strpos($nomeLower, 'crediario') !== false || strpos($nomeLower, 'crediário') !== false) {
            $isCrediario = true;
            break;
        }
        $idForma = isset($forma['id']) ? (int) $forma['id'] : 0;
        if ($idForma === 8126949) {
            $isCrediario = true;
            break;
        }
    }

    if (!$isCrediario && !empty($pedido['parcelas'])) {
        foreach ($pedido['parcelas'] as $parcela) {
            $idForma = isset($parcela['formaPagamento']['id']) ? (int) $parcela['formaPagamento']['id'] : 0;
            if ($idForma === 8126949) {
                $isCrediario = true;
                break;
            }
        }
    }

    $resumoCrediarioHtml = '';
    if ($isCrediario && $clienteId) {
        $callSaldo = function (string $path, array $payload) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url = $scheme . '://' . $host . $path;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 15,
            ]);

            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            logRecibo("↪️ saldo.php: POST {$url} (HTTP {$http})" . ($err ? " ERR={$err}" : ''));
            return [$http, $resp];
        };

        $saldoConsulta = 0.0;
        $payloadSaldo = ['clienteId' => (string) $clienteId];
        $tentativas = [
            '/pdv_carmania/api/crediario/saldo.php',
            '/pdv_carmania/api/saldo.php',
        ];

        foreach ($tentativas as $path) {
            [$http, $resp] = $callSaldo($path, $payloadSaldo);
            if ($http === 200 && $resp) {
                $jsonSaldo = json_decode($resp, true);
                if (!empty($jsonSaldo['ok']) && isset($jsonSaldo['saldoAtual'])) {
                    $saldoConsulta = (float) $jsonSaldo['saldoAtual'];
                    logRecibo("✅ Saldo obtido via {$path} -> R$ " . number_format($saldoConsulta, 2, ',', '.'));
                    break;
                }
                logRecibo("⚠️ Resposta inesperada de {$path} -> {$resp}");
            } else {
                logRecibo("⚠️ Falha ao consultar {$path} (HTTP {$http}) -> {$resp}");
            }
        }

        $saldoAnteriorEstimado = round($saldoConsulta - $totalFinal, 2);
        if (abs($saldoAnteriorEstimado) < 0.01) {
            $saldoAnteriorEstimado = 0.0;
        }
        $novoSaldo = $saldoConsulta;

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
        <tr><td style='text-align:left;'>Saldo Anterior:</td><td style='text-align:right;'>R$ " . number_format($saldoAnteriorEstimado, 2, ',', '.') . "</td></tr>
        <tr><td style='text-align:left;'>Compra Atual:</td><td style='text-align:right;'>R$ " . number_format($totalFinal, 2, ',', '.') . "</td></tr>
        <tr><td colspan='2'><hr style='border:0;border-top:1px dashed #ccc;'></td></tr>
        <tr><td style='text-align:left;'><b>Novo Saldo:</b></td><td style='text-align:right;color:#dc3545;'><b>R$ " . number_format($novoSaldo, 2, ',', '.') . "</b></td></tr>
      </table>
    </div>";
    }

    $reciboHtml = gerarReciboHtml([
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
    ]);

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
