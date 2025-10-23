<?php
require_once __DIR__ . '/../session.php';
header('Content-Type: application/json; charset=utf-8');
$usuarioSessao = isset($_SESSION['usuario']) ? trim((string)$_SESSION['usuario']) : '';

// ✅ Inclui o helper centralizado do token
require_once __DIR__ . '/lib/token-helper.php';
require_once __DIR__ . '/lib/caixa-helper.php';
require_once __DIR__ . '/lib/vendas-helper.php';
require_once __DIR__ . '/lib/crediario-helper.php';
require_once __DIR__ . '/lib/recibo-helper.php';

$nfeConfigPath = __DIR__ . '/config/nfe-config.php';
$nfeConfig = [];
if (file_exists($nfeConfigPath)) {
    $loadedNfeConfig = include $nfeConfigPath;
    if (is_array($loadedNfeConfig)) {
        $nfeConfig = $loadedNfeConfig;
    }
}

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

// 🔐 Token
$access_token = getAccessToken();
if (!$access_token) die(json_encode(['erro'=>'Access token inválido ou não pôde ser atualizado']));

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

function extrairMensagemErroEstrutura($dados)
{
    if (is_string($dados)) {
        $texto = trim($dados);
        return $texto !== '' ? $texto : null;
    }

    if (!is_array($dados)) {
        return null;
    }

    $chavesPrioritarias = ['mensagem', 'message', 'descricao', 'description', 'detail', 'erro', 'error'];
    foreach ($chavesPrioritarias as $chave) {
        if (!array_key_exists($chave, $dados)) {
            continue;
        }

        $valor = $dados[$chave];
        $mensagem = extrairMensagemErroEstrutura($valor);
        if ($mensagem) {
            return $mensagem;
        }
    }

    foreach ($dados as $valor) {
        $mensagem = extrairMensagemErroEstrutura($valor);
        if ($mensagem) {
            return $mensagem;
        }
    }

    return null;
}

function extrairMensagemErroResposta(?string $body): ?string
{
    if ($body === null) {
        return null;
    }

    $texto = trim($body);
    if ($texto === '') {
        return null;
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
        $mensagem = extrairMensagemErroEstrutura($json);
        if ($mensagem) {
            return $mensagem;
        }
    }

    return $texto;
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

    return (int) $idString === 2009802;
}

function obterIdsFormasBoleto(): array
{
    return [7697681, 2941141, 7749678];
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

    $idInt = (int) $idString;
    if ($idInt <= 0) {
        return false;
    }

    return in_array($idInt, obterIdsFormasBoleto(), true);
}

function pagamentoEhDinheiro(array $pagamento): bool
{
    $idValor = $pagamento['id'] ?? null;
    if (formaPagamentoEhDinheiroPorId($idValor)) {
        return true;
    }

    $nome = strtolower(trim((string) ($pagamento['forma'] ?? $pagamento['nome'] ?? '')));
    if ($nome !== '' && mb_stripos($nome, 'dinheiro') !== false) {
        return true;
    }

    return false;
}

function pagamentoEhBoleto(array $pagamento): bool
{
    $idValor = $pagamento['id'] ?? null;
    if (formaPagamentoEhBoletoPorId($idValor)) {
        return true;
    }

    $nome = strtolower(trim((string) ($pagamento['forma'] ?? $pagamento['nome'] ?? '')));
    if ($nome !== '' && mb_stripos($nome, 'boleto') !== false) {
        return true;
    }

    return false;
}

function ajustarNomeComSobrenome(string $nome): array
{
    $nomeLimpo = trim($nome);
    if ($nomeLimpo === '') {
        return ['', false];
    }

    $partes = preg_split('/\s+/u', $nomeLimpo, -1, PREG_SPLIT_NO_EMPTY);
    if (!$partes) {
        return [$nomeLimpo, false];
    }

    $partesValidas = array_filter($partes, function ($parte) {
        return $parte !== '';
    });

    if (count($partesValidas) >= 2) {
        return [$nomeLimpo, false];
    }

    return [$nomeLimpo . ' Cliente', true];
}

function limparCamposNulos(array $dados): array
{
    $resultado = [];
    foreach ($dados as $chave => $valor) {
        if ($valor === null) {
            continue;
        }

        if (is_string($valor)) {
            $valor = trim($valor);
            if ($valor === '') {
                continue;
            }
        }

        if (is_array($valor)) {
            $valor = limparCamposNulos($valor);
            if (empty($valor)) {
                continue;
            }
        }

        $resultado[$chave] = $valor;
    }

    return $resultado;
}

function obterContatoParaNfe(int $clienteId, string $clienteNome): array
{
    [$nomeFallback, $fallbackAjustado] = ajustarNomeComSobrenome($clienteNome);
    if ($fallbackAjustado) {
        logMsg('ℹ️ Nome do cliente informado sem sobrenome. Ajuste automático aplicado para emissão da NF.');
    }

    $fallback = ['id' => $clienteId, 'nome' => $nomeFallback];
    if ($fallbackAjustado) {
        $fallback['__nomeIncompleto'] = true;
    }
    $resContato = bling_request('GET', "contatos/{$clienteId}");
    if ($resContato['http'] < 200 || $resContato['http'] >= 300) {
        logMsg("⚠️ Falha ao obter contato {$clienteId} para NF (HTTP {$resContato['http']}) -> {$resContato['body']}");
        return $fallback;
    }

    $json = json_decode($resContato['body'], true);
    $dadosContato = $json['data'] ?? null;
    if (!is_array($dadosContato)) {
        logMsg("⚠️ Resposta inesperada ao carregar contato {$clienteId} para NF: {$resContato['body']}");
        return $fallback;
    }

    $nomeContato = (string) ($dadosContato['nome'] ?? $clienteNome);
    [$nomeContatoAjustado, $foiAjustado] = ajustarNomeComSobrenome($nomeContato);
    if ($foiAjustado) {
        logMsg("ℹ️ Nome do contato {$clienteId} estava sem sobrenome. Ajustado automaticamente para '{$nomeContatoAjustado}'.");
    }

    $contato = [
        'id' => (int) ($dadosContato['id'] ?? $clienteId),
        'nome' => $nomeContatoAjustado,
        'tipoPessoa' => $dadosContato['tipoPessoa'] ?? ($dadosContato['tipo'] ?? null),
        'numeroDocumento' => $dadosContato['numeroDocumento'] ?? ($dadosContato['numeroDocumentoPrincipal'] ?? ($dadosContato['cpfCnpj'] ?? null)),
        'ie' => $dadosContato['ie'] ?? null,
        'rg' => $dadosContato['rg'] ?? null,
        'contribuinte' => isset($dadosContato['contribuinte']) ? (int) $dadosContato['contribuinte'] : null,
        'telefone' => $dadosContato['telefone'] ?? ($dadosContato['fone'] ?? null),
        'email' => $dadosContato['email'] ?? null,
    ];

    if ($foiAjustado) {
        $contato['__nomeIncompleto'] = true;
    }

    $origensEndereco = [];
    if (isset($dadosContato['endereco']) && is_array($dadosContato['endereco'])) {
        $origensEndereco[] = $dadosContato['endereco'];
    }
    if (isset($dadosContato['enderecoPrincipal']) && is_array($dadosContato['enderecoPrincipal'])) {
        $origensEndereco[] = $dadosContato['enderecoPrincipal'];
    }
    if (!empty($dadosContato['enderecos']) && is_array($dadosContato['enderecos'])) {
        foreach ($dadosContato['enderecos'] as $end) {
            if (is_array($end)) {
                $origensEndereco[] = $end;
            }
        }
    }

    foreach ($origensEndereco as $enderecoFonte) {
        $endereco = [
            'endereco' => $enderecoFonte['endereco'] ?? ($enderecoFonte['logradouro'] ?? null),
            'numero' => $enderecoFonte['numero'] ?? null,
            'complemento' => $enderecoFonte['complemento'] ?? null,
            'bairro' => $enderecoFonte['bairro'] ?? null,
            'cep' => $enderecoFonte['cep'] ?? null,
            'municipio' => $enderecoFonte['municipio'] ?? ($enderecoFonte['cidade'] ?? null),
            'uf' => $enderecoFonte['uf'] ?? ($enderecoFonte['estado'] ?? null),
            'pais' => $enderecoFonte['pais'] ?? null,
        ];

        $enderecoLimpo = limparCamposNulos($endereco);
        if (!empty($enderecoLimpo)) {
            $contato['endereco'] = $enderecoLimpo;
            break;
        }
    }

    return limparCamposNulos($contato);
}

function obterDetalhesPedidoParaNfe(int $pedidoId): ?array
{
    $resPedido = bling_request('GET', "pedidos/vendas/{$pedidoId}");
    logMsg("🔍 Consulta detalhes do pedido {$pedidoId} para NF -> HTTP {$resPedido['http']}");
    if ($resPedido['http'] < 200 || $resPedido['http'] >= 300) {
        logMsg("⚠️ Falha ao obter detalhes do pedido {$pedidoId} para NF: {$resPedido['body']}");
        return null;
    }

    $json = json_decode($resPedido['body'], true);
    $dados = $json['data'] ?? null;
    if (!is_array($dados)) {
        logMsg("⚠️ Resposta inesperada ao consultar pedido {$pedidoId} para NF: {$resPedido['body']}");
        return null;
    }

    return $dados;
}

function criarNotaFiscalAutomatica(
    int $pedidoId,
    int $clienteId,
    string $clienteNome,
    array $pagamentos,
    array $carrinho,
    float $totalFinal,
    float $descontoAplicado,
    array $config
): array {
    $resultado = ['sucesso' => false];

    if (!($config['habilitado'] ?? true)) {
        logMsg('ℹ️ Emissão automática de NF está desabilitada na configuração.');
        $resultado['mensagem'] = 'Emissão automática desabilitada.';
        return $resultado;
    }

    $pedidoDados = obterDetalhesPedidoParaNfe($pedidoId);
    if (!$pedidoDados) {
        $resultado['mensagem'] = 'Não foi possível obter os dados do pedido no Bling.';
        return $resultado;
    }

    if (!empty($pedidoDados['notaFiscal']['id'])) {
        $notaFiscalId = (int) $pedidoDados['notaFiscal']['id'];
        $notaFiscalNumero = $pedidoDados['notaFiscal']['numero'] ?? null;
        logMsg("ℹ️ Pedido {$pedidoId} já possui NF vinculada (ID {$notaFiscalId}).");
        $resultado['sucesso'] = true;
        $resultado['id'] = $notaFiscalId;
        if ($notaFiscalNumero) {
            $resultado['numero'] = $notaFiscalNumero;
        }
        $resultado['mensagem'] = 'NF já estava vinculada ao pedido.';
        return $resultado;
    }

    $contato = obterContatoParaNfe($clienteId, $clienteNome);
    $nomeIncompleto = !empty($contato['__nomeIncompleto']);
    unset($contato['__nomeIncompleto']);
    if ($nomeIncompleto) {
        $resultado['mensagem'] = 'Nome do cliente incompleto. Informe nome e sobrenome para emitir a NF.';
        logMsg('⚠️ NF não gerada: nome do cliente está incompleto.');
        return $resultado;
    }

    $documento = isset($contato['numeroDocumento']) ? preg_replace('/\D+/', '', (string) $contato['numeroDocumento']) : '';
    if ($documento === '') {
        $resultado['mensagem'] = 'CPF/CNPJ do cliente não informado. Atualize o cadastro para emitir a NF.';
        logMsg('⚠️ NF não gerada: cliente sem CPF/CNPJ cadastrado.');
        return $resultado;
    }

    $endereco = isset($contato['endereco']) && is_array($contato['endereco']) ? $contato['endereco'] : [];
    $camposEnderecoObrigatorios = ['endereco', 'municipio', 'uf', 'cep'];
    $faltantes = [];
    foreach ($camposEnderecoObrigatorios as $campo) {
        $valorCampo = isset($endereco[$campo]) ? trim((string) $endereco[$campo]) : '';
        if ($valorCampo === '') {
            $faltantes[] = $campo;
        }
    }
    if ($faltantes) {
        $resultado['mensagem'] = 'Endereço do cliente incompleto. Informe rua, cidade, UF e CEP para emitir a NF.';
        logMsg('⚠️ NF não gerada: endereço do cliente incompleto (' . implode(', ', $faltantes) . ').');
        return $resultado;
    }

    $transmitirAutomaticamente = !empty($config['transmitir_automaticamente']);
    if ($transmitirAutomaticamente) {
        logMsg('📤 NF será transmitida automaticamente após a geração pelo pedido.');
    } else {
        logMsg('📝 NF será criada como rascunho a partir do pedido de venda.');
    }

    $payload = ['transmitir' => $transmitirAutomaticamente];
    $payloadLimpo = limparCamposNulos($payload);

    $resNfe = bling_request('POST', "pedidos/vendas/{$pedidoId}/gerar-nfe", $payloadLimpo ?: null);
    logMsg("🧾 Resultado geração NF via pedido HTTP {$resNfe['http']} -> {$resNfe['body']}");

    if ($resNfe['http'] < 200 || $resNfe['http'] >= 300) {
        $resultado['mensagem'] = extrairMensagemErroResposta($resNfe['body']) ?? 'Falha ao gerar NF para o pedido.';
        logMsg('⚠️ Falha ao gerar NF vinculada ao pedido: ' . $resultado['mensagem']);
        return $resultado;
    }

    $nfeId = null;
    $nfeNumero = null;
    $body = json_decode($resNfe['body'], true);
    if (is_array($body)) {
        $data = $body['data'] ?? $body;
        $estruturas = [];
        if (isset($data['notaFiscal']) && is_array($data['notaFiscal'])) {
            $estruturas[] = $data['notaFiscal'];
        }
        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $estruturas[] = $item;
                if (isset($item['notaFiscal']) && is_array($item['notaFiscal'])) {
                    $estruturas[] = $item['notaFiscal'];
                }
            }
        }
        if (empty($estruturas) && is_array($data)) {
            $estruturas[] = $data;
        }

        foreach ($estruturas as $estrutura) {
            if (!is_array($estrutura)) {
                continue;
            }
            if ($nfeId === null && isset($estrutura['id'])) {
                $nfeId = $estrutura['id'];
            }
            if ($nfeNumero === null && isset($estrutura['numero'])) {
                $nfeNumero = $estrutura['numero'];
            }
            if ($nfeId !== null && $nfeNumero !== null) {
                break;
            }
        }
    }

    $resultado['sucesso'] = true;
    if ($nfeId !== null) {
        $resultado['id'] = $nfeId;
    }
    if ($nfeNumero !== null) {
        $resultado['numero'] = $nfeNumero;
    }
    $resultado['mensagem'] = 'NF gerada a partir do pedido.';

    logMsg('✅ NF gerada e vinculada ao pedido ' . $pedidoId . ' (ID: ' . ($nfeId ?? 'n/d') . ', Número: ' . ($nfeNumero ?? 'n/d') . ').');

    return $resultado;
}

// 💰 Cálculo dos totais
$totalBruto = 0;
foreach ($carrinho as $it) {
    $totalBruto += (float)$it['preco'] * (int)$it['quantidade'];
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
        if ($id > 0 && $valor > 0) {
            $parcelas[] = [
                'dataVencimento' => date('Y-m-d'),
                'valor'          => $valor,
                'formaPagamento' => ['id' => $id],
                'observacoes'    => 'Pagamento via PDV Carmania'
            ];
            $nomesFormas[] = [
                'nome' => $nome,
                'valor' => $valorInformado,
                'valorAplicado' => $valor,
                'troco' => $trocoPagamento
            ];
        }
    }
} else {
    echo json_encode(['ok'=>false,'erro'=>'Nenhuma forma de pagamento']);
    exit;
}

$temPagamentoBoleto = false;
foreach ($pagamentos as $p) {
    if (pagamentoEhBoleto($p)) {
        $temPagamentoBoleto = true;
        break;
    }
}

if (!$temPagamentoBoleto && !empty($parcelas)) {
    foreach ($parcelas as $parcelaDetectar) {
        $formaParcelaId = $parcelaDetectar['formaPagamento']['id'] ?? null;
        if (formaPagamentoEhBoletoPorId($formaParcelaId)) {
            $temPagamentoBoleto = true;
            break;
        }
    }
}

[$isCrediario, $valorCrediarioGerado] = analisarPagamentosCrediario($pagamentos);
$saldoCrediarioAnterior = null;
$saldoCrediarioPosterior = null;
$saldoCrediarioAnteriorExibido = null;
$saldoCrediarioNovoExibido = null;
if ($isCrediario && $clienteId) {
    logMsg('💳 Venda identificada como CREDIÁRIO. Valor crediário informado: R$ ' . number_format($valorCrediarioGerado, 2, ',', '.'));
    $saldoCrediarioAnterior = consultarSaldoCrediarioCliente($clienteId, 'logMsg');
    if ($saldoCrediarioAnterior !== null) {
        logMsg('💳 Saldo crediário anterior consultado: R$ ' . number_format($saldoCrediarioAnterior, 2, ',', '.'));
    } else {
        logMsg('⚠️ Não foi possível obter o saldo anterior do crediário antes da venda.');
    }
}

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
    if ($temPagamentoBoleto) {
        logMsg("⏭️ Pagamento com boleto detectado. Lançamento do contas a receber será realizado posteriormente pela emissão da NF.");
    } else {
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
            $bodyResposta = $resContas['body'];
            if (strpos($bodyResposta, 'Nome do cliente está incompleto') !== false) {
                logMsg('⚠️ Contas a receber não lançadas porque o nome do cliente está sem sobrenome no Bling. Ajuste o cadastro para evitar novas falhas.');
            }
            logMsg("❌ Falha ao lançar contas (HTTP {$resContas['http']}) -> {$resContas['body']}");
        }
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

$nfeResultado = ['sucesso' => false];
if ($pedidoId && $clienteId) {
    try {
        $nfeResultado = criarNotaFiscalAutomatica(
            (int) $pedidoId,
            (int) $clienteId,
            (string) $clienteNome,
            $pagamentos,
            $carrinho,
            $totalFinal,
            $descontoAplicado,
            $nfeConfig
        );
    } catch (Throwable $e) {
        $nfeResultado['sucesso'] = false;
        $nfeResultado['mensagem'] = 'Erro ao criar NF automaticamente.';
        logMsg('⚠️ Exceção ao criar NF automaticamente: ' . $e->getMessage());
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

// 🧾 Recibo HTML
$resumoCrediarioHtml = '';
if ($isCrediario && $clienteId && $valorCrediarioGerado > 0) {
    $saldoCrediarioPosterior = consultarSaldoCrediarioCliente($clienteId, 'logMsg');
    if ($saldoCrediarioPosterior !== null) {
        logMsg('💳 Saldo crediário após venda (consulta): R$ ' . number_format($saldoCrediarioPosterior, 2, ',', '.'));
    } else {
        logMsg('⚠️ Não foi possível obter o saldo do crediário após a venda.');
    }

    if ($saldoCrediarioAnterior !== null) {
        $saldoCrediarioAnteriorExibido = round($saldoCrediarioAnterior, 2);
    } elseif ($saldoCrediarioPosterior !== null) {
        $saldoCrediarioAnteriorExibido = round($saldoCrediarioPosterior - $valorCrediarioGerado, 2);
    } else {
        $saldoCrediarioAnteriorExibido = 0.0;
    }

    if ($saldoCrediarioAnteriorExibido < 0 && abs($saldoCrediarioAnteriorExibido) > 0.01) {
        $saldoCrediarioAnteriorExibido = 0.0;
    }
    if (abs($saldoCrediarioAnteriorExibido) < 0.01) {
        $saldoCrediarioAnteriorExibido = 0.0;
    }

    $saldoEstimadoPosVenda = round($saldoCrediarioAnteriorExibido + $valorCrediarioGerado, 2);
    if ($saldoCrediarioPosterior === null) {
        $saldoCrediarioNovoExibido = $saldoEstimadoPosVenda;
    } else {
        $saldoCrediarioNovoExibido = $saldoCrediarioPosterior;
        if ($saldoCrediarioNovoExibido < $saldoEstimadoPosVenda - 0.01) {
            $saldoCrediarioNovoExibido = $saldoEstimadoPosVenda;
        }
    }

    if (abs($saldoCrediarioNovoExibido) < 0.01) {
        $saldoCrediarioNovoExibido = 0.0;
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
        <tr><td style='text-align:left;'>Saldo Anterior:</td><td style='text-align:right;'>R$ ".number_format($saldoCrediarioAnteriorExibido,2,',','.')."</td></tr>
        <tr><td style='text-align:left;'>Compra Atual:</td><td style='text-align:right;'>R$ ".number_format($valorCrediarioGerado,2,',','.')."</td></tr>
        <tr><td colspan='2'><hr style='border:0;border-top:1px dashed #ccc;'></td></tr>
        <tr><td style='text-align:left;'><b>Novo Saldo:</b></td><td style='text-align:right;color:#dc3545;'><b>R$ ".number_format($saldoCrediarioNovoExibido,2,',','.')."</b></td></tr>
      </table>
    </div>";

    logMsg("💳 Resumo crediário -> anterior exibido: {$saldoCrediarioAnteriorExibido} | valor venda: {$valorCrediarioGerado} | saldo consultado pós: " . ($saldoCrediarioPosterior !== null ? (string)$saldoCrediarioPosterior : 'n/d') . " | novo exibido: {$saldoCrediarioNovoExibido}");
}

$saldoCrediarioAnteriorPersistir = $saldoCrediarioAnteriorExibido;
$saldoCrediarioNovoPersistir = $saldoCrediarioNovoExibido;
$valorCrediarioPersistir = $valorCrediarioGerado;
if (!$isCrediario || $valorCrediarioGerado <= 0) {
    $saldoCrediarioAnteriorPersistir = null;
    $saldoCrediarioNovoPersistir = null;
    $valorCrediarioPersistir = null;
}

// 🗃️ Persiste a venda no banco local
$dataHoraVenda = date('Y-m-d H:i:s');
if ($pedidoId) {
    $situacaoRegistrada = (int) ($rj['data']['situacao']['id'] ?? 9);
    $usuarioResponsavel = $usuarioSessao !== '' ? $usuarioSessao : $usuarioPayload;

    try {
        registrarVendaLocal([
            'id' => (int) $pedidoId,
            'data_hora' => $dataHoraVenda,
            'contato_id' => $clienteId ? (int) $clienteId : null,
            'contato_nome' => $clienteNome,
            'usuario_login' => $usuarioResponsavel,
            'usuario_nome' => $usuarioRecibo,
            'deposito_id' => $depositoIdCaixa ? (int) $depositoIdCaixa : null,
            'deposito_nome' => $depositoNome,
            'situacao_id' => $situacaoRegistrada > 0 ? $situacaoRegistrada : 9,
            'valor_total' => $totalFinal,
            'valor_desconto' => $descontoAplicado,
            'saldo_crediario_anterior' => $saldoCrediarioAnteriorPersistir,
            'saldo_crediario_novo' => $saldoCrediarioNovoPersistir,
            'valor_crediario_venda' => $valorCrediarioPersistir,
        ], $pagamentos, $carrinho);
        logMsg('💾 Venda registrada no banco local.');
    } catch (Throwable $e) {
        logMsg('⚠️ Falha ao registrar venda localmente: ' . $e->getMessage());
    }
}

$itensRecibo = [];
foreach ($carrinho as $item) {
    $quantidade = (int) ($item['quantidade'] ?? 0);
    if ($quantidade <= 0) {
        continue;
    }
    $itensRecibo[] = [
        'nome' => (string) ($item['nome'] ?? ''),
        'quantidade' => $quantidade,
        'valorUnitario' => (float) ($item['preco'] ?? 0),
        'subtotal' => (float) ($item['preco'] ?? 0) * $quantidade,
    ];
}

$reciboHtml = gerarReciboHtml([
    'pedidoId' => $pedidoId ?? '-',
    'clienteNome' => $clienteNome,
    'atendente' => $usuarioRecibo,
    'depositoNome' => $deposito['nome'] ?? $depositoNome ?? '',
    'totalBruto' => $totalBruto,
    'descontoAplicado' => $descontoAplicado,
    'totalFinal' => $totalFinal,
    'itens' => $itensRecibo,
    'formas' => $nomesFormas,
    'resumoCrediarioHtml' => $resumoCrediarioHtml,
    'dataHoraVenda' => $dataHoraVenda,
]);

$responsePayload = [
    'ok' => true,
    'pedidoId' => $pedidoId,
    'reciboHtml' => $reciboHtml,
];

if (!empty($nfeResultado['sucesso'])) {
    if (!empty($nfeResultado['id'])) {
        $responsePayload['nfeId'] = $nfeResultado['id'];
    }
    if (!empty($nfeResultado['numero'])) {
        $responsePayload['nfeNumero'] = $nfeResultado['numero'];
    }
} elseif (!empty($nfeResultado['mensagem'])) {
    $responsePayload['nfeErro'] = $nfeResultado['mensagem'];
}

echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
