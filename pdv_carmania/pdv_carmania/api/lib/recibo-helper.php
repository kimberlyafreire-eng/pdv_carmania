<?php
declare(strict_types=1);

/**
 * Gera o HTML do recibo a partir dos dados informados.
 * Espera itens e formas normalizadas conforme estrutura do PDV.
 */
function gerarReciboHtml(array $dados): string
{
    $pedidoId = $dados['pedidoId'] ?? '-';
    $clienteNome = trim((string)($dados['clienteNome'] ?? '-'));
    $atendente = trim((string)($dados['atendente'] ?? ''));
    $depositoNome = trim((string)($dados['depositoNome'] ?? ''));
    $totalBruto = round((float)($dados['totalBruto'] ?? 0), 2);
    $descontoAplicado = round((float)($dados['descontoAplicado'] ?? 0), 2);
    $totalFinal = round((float)($dados['totalFinal'] ?? $totalBruto), 2);
    $resumoCrediarioHtml = (string)($dados['resumoCrediarioHtml'] ?? '');
    $dataHoraVendaFormatada = '';

    $normalizarDataHora = static function ($data, $hora = null) {
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
            return new DateTime($data);
        } catch (Throwable $e) {
            return null;
        }
    };

    $dataHoraVendaObj = $normalizarDataHora($dados['dataHoraVenda'] ?? null);
    if ($dataHoraVendaObj === null && array_key_exists('dataVenda', $dados)) {
        $dataHoraVendaObj = $normalizarDataHora($dados['dataVenda'] ?? null, $dados['horaVenda'] ?? null);
    }
    if ($dataHoraVendaObj === null && array_key_exists('dataHora', $dados)) {
        $dataHoraVendaObj = $normalizarDataHora($dados['dataHora'] ?? null);
    }

    if ($dataHoraVendaObj instanceof DateTime) {
        $dataHoraVendaFormatada = $dataHoraVendaObj->format('d/m/Y H:i');
    }

    $itens = [];
    if (!empty($dados['itens']) && is_array($dados['itens'])) {
        foreach ($dados['itens'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $nome = trim((string)($item['nome'] ?? $item['descricao'] ?? 'Produto'));
            $quantidade = (float)($item['quantidade'] ?? $item['qtd'] ?? 0);
            $valorUnitario = (float)($item['valorUnitario'] ?? $item['valor'] ?? $item['preco'] ?? 0);
            $subtotal = (float)($item['subtotal'] ?? ($valorUnitario * $quantidade));

            if ($quantidade <= 0) {
                continue;
            }

            $itens[] = [
                'nome' => $nome,
                'quantidade' => $quantidade,
                'subtotal' => round($subtotal, 2),
            ];
        }
    }

    $formas = [];
    if (!empty($dados['formas']) && is_array($dados['formas'])) {
        foreach ($dados['formas'] as $forma) {
            if (!is_array($forma)) {
                continue;
            }
            $nome = trim((string)($forma['nome'] ?? $forma['descricao'] ?? 'Forma'));
            $valor = (float)($forma['valor'] ?? $forma['valorInformado'] ?? $forma['valorAplicado'] ?? 0);
            $valorAplicado = (float)($forma['valorAplicado'] ?? $valor);
            $troco = max(0.0, (float)($forma['troco'] ?? 0));

            if ($valor <= 0 && $valorAplicado <= 0) {
                continue;
            }

            $formas[] = [
                'nome' => $nome !== '' ? $nome : 'Forma',
                'valor' => round($valor, 2),
                'valorAplicado' => round($valorAplicado, 2),
                'troco' => round($troco, 2),
            ];
        }
    }

    $totalItens = count($itens);
    $totalQuantidade = 0;
    foreach ($itens as $item) {
        $totalQuantidade += $item['quantidade'];
    }

    $atendenteHtml = '';
    if ($atendente !== '') {
        $atendenteHtml = "  <p style='margin:2px 0;'>Atendente: <b>" . htmlspecialchars($atendente, ENT_QUOTES, 'UTF-8') . "</b></p>";
    }

    $itensHtml = '';
    foreach ($itens as $item) {
        $quantidade = $item['quantidade'];
        $quantidadeTexto = (abs($quantidade - round($quantidade)) < 0.001)
            ? (string) number_format((int) round($quantidade), 0, '', '.')
            : number_format($quantidade, 2, ',', '.');
        $descricao = trim($quantidadeTexto . 'x ' . $item['nome']);
        $valor = number_format($item['subtotal'], 2, ',', '.');
        $itensHtml .= "      <tr><td style='text-align:left;'>" . htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') . "</td><td style='text-align:right;'>R$ {$valor}</td></tr>";
    }

    $formasHtml = '';
    foreach ($formas as $forma) {
        $valorPrincipal = number_format($forma['valor'], 2, ',', '.');
        $detalheLinha = '';
        if ($forma['troco'] > 0.009) {
            $valorAplicadoFmt = number_format($forma['valorAplicado'], 2, ',', '.');
            $trocoFmt = number_format($forma['troco'], 2, ',', '.');
            $detalheLinha = "<br><small style='color:#6c757d;'>Aplicado: R$ {$valorAplicadoFmt} &middot; Troco: R$ {$trocoFmt}</small>";
        }
        $formasHtml .= "      <tr><td style='text-align:left;'>" . htmlspecialchars($forma['nome'], ENT_QUOTES, 'UTF-8') . "</td><td style='text-align:right;'><b>R$ {$valorPrincipal}</b>{$detalheLinha}</td></tr>";
    }

    $reciboHtml = <<<HTML
<style>
  #recibo-preview-stage {
    width:100vw;
    min-height:100vh;
    margin:0;
    padding:clamp(8px,4vh,24px) 0;
    display:flex;
    justify-content:center;
    align-items:flex-start;
    box-sizing:border-box;
    overflow:auto;
    -webkit-overflow-scrolling:touch;
    background:transparent;
  }
  #recibo-preview {
    width:min(92vw, 60ch);
    max-width:100%;
    font-family:monospace;
    font-size:13px;
    line-height:1.35;
    text-align:left;
    background:#fff;
    color:#111;
    padding:14px 12px 20px;
    box-sizing:border-box;
    margin:0 auto;
    box-shadow:0 0 0 1px rgba(0,0,0,0.04);
  }
  #recibo-preview table {
    width:100%;
    border-collapse:collapse;
    font-size:0.92em;
  }
  #recibo-preview td {
    padding:2px 0;
    text-align:left;
    vertical-align:top;
  }
  #recibo-preview td:last-child {
    text-align:right;
  }
  #recibo-preview td:first-child {
    padding-right:8px;
    word-break:break-word;
    white-space:pre-wrap;
  }
  #recibo-preview .recibo-divider {
    border:0;
    border-top:1px solid #e0e0e0;
    margin:10px 0;
  }
  @media (max-width: 768px) {
    #recibo-preview-stage {
      padding:clamp(12px,6vh,32px) 0;
    }
    #recibo-preview {
      width:min(94vw, 58ch);
      font-size:13px;
    }
  }
</style>
<div id='recibo-preview-stage'>
  <div id='recibo-preview'>
    <h4 style='margin:6px 0;color:#dc3545;text-align:center;'>Carmania Produtos Automotivos</h4>
HTML;

    $reciboHtml .= $atendenteHtml;

    $reciboHtml .= "    <p style='margin:2px 0;'>Pedido: <b>" . htmlspecialchars((string)$pedidoId, ENT_QUOTES, 'UTF-8') . "</b></p>";
    $reciboHtml .= "    <p style='margin:2px 0;'>Cliente: <b style='color:#dc3545;'>" . htmlspecialchars($clienteNome, ENT_QUOTES, 'UTF-8') . "</b></p>";
    $reciboHtml .= "    <hr class='recibo-divider'>";

    if ($totalItens > 0) {
        $labelItens = $totalItens === 1 ? 'item' : 'itens';
        $reciboHtml .= "    <p style='margin:4px 0;font-weight:bold;'>" . number_format($totalItens, 0, '', '.') . " {$labelItens} (Qtd " . number_format($totalQuantidade, 0, '', '.') . ")</p>";
    }
    $reciboHtml .= "    <table style='margin-bottom:6px;'>$itensHtml</table>";
    $reciboHtml .= "    <hr class='recibo-divider'>";

    $reciboHtml .= "    <table>";
    $reciboHtml .= "      <tr><td>Total Bruto</td><td>R$ " . number_format($totalBruto, 2, ',', '.') . "</td></tr>";
    if ($descontoAplicado > 0.009) {
        $reciboHtml .= "      <tr><td>Desconto</td><td>R$ " . number_format($descontoAplicado, 2, ',', '.') . "</td></tr>";
    }
    $reciboHtml .= "      <tr><td colspan='2'><hr class='recibo-divider'></td></tr>";
    $reciboHtml .= "      <tr><td><b>Total Final</b></td><td><b>R$ " . number_format($totalFinal, 2, ',', '.') . "</b></td></tr>";
    $reciboHtml .= "    </table>";
    $reciboHtml .= "    <hr class='recibo-divider'>";

    if ($formasHtml !== '') {
        $reciboHtml .= "    <p style='margin:3px 0; font-weight:bold;'>Pagamentos</p>";
        $reciboHtml .= "    <table>$formasHtml</table>";
    }

    if ($resumoCrediarioHtml !== '') {
        $reciboHtml .= $resumoCrediarioHtml;
    }

    $depositoMostrar = $depositoNome !== '' ? $depositoNome : 'Não informado';
    $reciboHtml .= "    <hr class='recibo-divider'>";
    $reciboHtml .= "    <p style='margin:2px 0;'>Estoque: <b>" . htmlspecialchars($depositoMostrar, ENT_QUOTES, 'UTF-8') . "</b></p>";
    $reciboHtml .= "    <hr class='recibo-divider'>";

    if ($dataHoraVendaFormatada !== '') {
        $reciboHtml .= "    <table style='width:100%;font-size:0.85em;color:#555;margin:4px 0 10px;'>";
        $reciboHtml .= "      <tr><td style='text-align:left;'>📅 Data da venda:</td><td style='text-align:right;'><b>" . htmlspecialchars($dataHoraVendaFormatada, ENT_QUOTES, 'UTF-8') . "</b></td></tr>";
        $reciboHtml .= "    </table>";
        $reciboHtml .= "    <hr class='recibo-divider'>";
    }

    $reciboHtml .= "    <p style='margin:5px 0; font-size:0.9em; color:#222;'>Obrigado pela preferência!</p>";
    $reciboHtml .= "  </div>";
    $reciboHtml .= "</div>";

    return $reciboHtml;
}
