<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
$usuarioLogado = $_SESSION['usuario'] ?? null;

$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
$vendedorId = null;

if ($usuarioLogado) {
    try {
        if (file_exists($dbFile)) {
            $db = new SQLite3($dbFile);
            $stmt = $db->prepare("SELECT vendedor_id FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->bindValue(1, $usuarioLogado, SQLITE3_TEXT);
            $res = $stmt->execute();
            $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

            if ($row && isset($row['vendedor_id'])) {
                $valor = trim((string)$row['vendedor_id']);
                if ($valor !== '') {
                    $vendedorId = $valor;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Erro ao buscar vendedor_id do usuário: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Formas de Pagamento - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
    }

    html, body {
      height: 100%;
    }

    body {
      background: #f8f9fa;
      display: flex;
      flex-direction: column;
      min-height: 100%;
    }

    .navbar-custom {
      background-color: #dc3545;
    }

    .cliente-destaque {
      background: #fff5f5;
      border-bottom: 1px solid rgba(220, 53, 69, 0.15);
      box-shadow: 0 6px 18px rgba(220, 53, 69, 0.08);
    }

    .cliente-destaque .cliente-wrapper {
      padding: 0.75rem 1rem;
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    @media (min-width: 576px) {
      .cliente-destaque .cliente-wrapper {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
      }
    }

    .cliente-destaque .cliente-label {
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #6c757d;
      font-weight: 600;
    }

    .cliente-destaque .cliente-nome {
      font-size: clamp(1rem, 2vw, 1.25rem);
      font-weight: 700;
      color: #b02a37;
      word-break: break-word;
    }

    #telaPagamento {
      flex: 1;
      display: flex;
      padding: 1.5rem clamp(0.75rem, 2vw, 2rem);
    }

    .pagamento-conteudo {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 1.8rem;
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
    }

    .pagamento-conteudo.container {
      max-width: 100%;
      padding-inline: clamp(1rem, 3vw, 2.5rem);
    }

    .resumo-card {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      padding: 2.1rem;
    }

    .resumo-valores {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.2rem;
    }

    .valor-destaque {
      border: 2px solid rgba(220, 53, 69, 0.2);
      border-radius: 14px;
      padding: 1.2rem 1.5rem;
      background: rgba(220, 53, 69, 0.06);
    }

    .valor-destaque.total {
      background: rgba(33, 37, 41, 0.05);
      border-color: rgba(33, 37, 41, 0.15);
    }

    .valor-destaque .label {
      display: block;
      font-size: 0.9rem;
      color: #6c757d;
      margin-bottom: 0.35rem;
    }

    .valor-destaque .valor {
      font-size: clamp(1.9rem, 5vw, 2.6rem);
      font-weight: 700;
      color: #212529;
      display: block;
    }

    .valor-destaque.faltando .valor {
      color: #dc3545;
    }

    .valor-destaque.troco .valor {
      color: #0d6efd;
    }

    .status-pagamento {
      margin-top: 0.35rem;
      font-size: 0.85rem;
      font-weight: 500;
      color: #495057;
    }

    .status-pagamento.completo {
      color: #198754;
    }

    .lista-pagamentos-container h5 {
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .lista-pagamentos-container .list-group-item {
      border-radius: 12px;
      border: 1px solid rgba(0, 0, 0, 0.06);
      margin-bottom: 0.5rem;
    }

    .lista-pagamentos-container .list-group-item:last-child {
      margin-bottom: 0;
    }

    .lista-vazia {
      font-size: 0.9rem;
      color: #6c757d;
      margin-top: 0.5rem;
    }

    .formas-wrapper {
      margin-top: auto;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .formas-wrapper h5 {
      font-weight: 600;
    }

    .formas-grid {
      display: grid;
      gap: 1.1rem;
      grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
    }

    .forma-card {
      background: #ffffff;
      border: 2px solid #dc3545;
      border-radius: 14px;
      width: 100%;
      min-height: 108px;
      padding: 1rem 0.75rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      font-weight: 600;
      color: #dc3545;
      transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
      cursor: pointer;
      user-select: none;
      font-size: 1.15rem;
      line-height: 1.35;
      padding-inline: 0.75rem;
    }

    .forma-card:hover,
    .forma-card:focus {
      background: #dc3545;
      color: #ffffff;
      transform: translateY(-2px);
      outline: none;
      box-shadow: 0 8px 18px rgba(220, 53, 69, 0.25);
    }

    .forma-card:active {
      transform: scale(0.97);
    }

    #reciboContainer {
      display: none;
      width: 100%;
      padding: 2.5rem 1rem 3rem;
      justify-content: center;
    }

    #reciboContainer.ativo {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1.5rem;
    }

    .recibo-area {
      background: #ffffff;
      border-radius: 18px;
      box-shadow: 0 16px 35px rgba(0, 0, 0, 0.12);
      padding: 2.25rem;
      display: flex;
      justify-content: center;
      width: min(100%, 570px);
    }

    #reciboImg {
      width: 100%;
      height: auto;
      border-radius: 12px;
      border: none;
      background: transparent;
    }

    .recibo-acoes {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      justify-content: center;
    }

    #modalValor .modal-dialog {
      max-width: 420px;
      width: 100%;
      margin: 1.5rem auto;
    }

    #modalValor .modal-content {
      border-radius: 18px;
      display: flex;
      flex-direction: column;
    }

    #modalValor .form-control {
      font-size: 1.1rem;
      padding: 0.85rem 1rem;
    }

    #modalValor .modal-body {
      flex: 1;
    }

    @media (max-width: 576px) {
      #telaPagamento {
        padding: 1rem 0.75rem 2rem;
      }

      .pagamento-conteudo.container {
        padding-inline: 0.5rem;
      }

      .resumo-card {
        padding: 1.75rem;
      }

      .formas-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .forma-card {
        min-height: 120px;
      }

      .recibo-area {
        width: min(100%, 480px);
        padding: 1.5rem;
      }

      #modalValor .modal-dialog {
        margin: 0;
        max-width: none;
        height: 100%;
      }

      #modalValor .modal-content {
        height: 100%;
        border-radius: 0;
      }

      #modalValor .modal-body {
        display: flex;
        flex-direction: column;
        justify-content: center;
      }
    }
  </style>
</head>
<body>

  <!-- Barra superior -->
  <nav class="navbar navbar-dark navbar-custom px-3">
    <button class="btn btn-light" onclick="window.location.href='index.php'">⬅ VENDER</button>
    <span class="navbar-text text-white fw-bold">Pagamento</span>
    <div></div>
  </nav>

  <div class="cliente-destaque">
    <div class="container cliente-wrapper">
      <span class="cliente-label">Cliente selecionado</span>
      <span class="cliente-nome" id="clienteSelecionadoNome">Nenhum cliente selecionado</span>
    </div>
  </div>

  <main class="container-fluid" id="telaPagamento">
    <div class="container pagamento-conteudo">
      <section class="resumo-card">
        <div class="resumo-valores">
          <div class="valor-destaque total">
            <span class="label">Total da venda</span>
            <span class="valor" id="valorTotal">R$ 0,00</span>
          </div>
          <div class="valor-destaque faltando">
            <span class="label">Valor pendente</span>
            <span class="valor" id="valorFaltante">R$ 0,00</span>
            <div class="status-pagamento" id="statusPagamento">Faltando calcular…</div>
          </div>
          <div class="valor-destaque troco d-none" id="valorTrocoWrapper">
            <span class="label">Troco</span>
            <span class="valor" id="valorTroco">R$ 0,00</span>
            <div class="status-pagamento">Troco a devolver</div>
          </div>
        </div>
        <div class="lista-pagamentos-container mt-4">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Pagamentos adicionados</h5>
            <span class="badge text-bg-light" id="qtdPagamentos">0</span>
          </div>
          <ul class="list-group mt-3" id="listaPagamentos"></ul>
          <p class="lista-vazia" id="listaVazia">Nenhuma forma de pagamento adicionada até o momento.</p>
        </div>
      </section>

      <section class="formas-wrapper">
        <div>
          <h5>Selecione a forma de pagamento</h5>
          <div class="formas-grid">
            <button class="forma-card" type="button" onclick="selecionarForma('Pix', 7697682)">Pix</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Boleto', 7697681)">Boleto</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Crédito', 2941151)">Crédito</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Débito', 2941150)">Débito</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Crediário', 8126949)">Crediário</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Dinheiro', 2009802)">💵 Dinheiro</button>
          </div>
        </div>
        <button class="btn btn-success w-100 btn-lg" id="btnConcluir" disabled onclick="concluirVenda()">
          ✅ Concluir Venda
        </button>
      </section>
    </div>
  </main>

  <div id="reciboContainer" class="container"></div>

  <!-- Modal valor -->
  <div class="modal fade" id="modalValor" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Inserir valor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Valor (R$)</label>
          <input type="number" id="valorPagamento" class="form-control" min="0" step="0.01" inputmode="decimal">
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" onclick="adicionarPagamento()">Adicionar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script>
    window.USUARIO_LOGADO = <?= json_encode($usuarioLogado) ?>;
    window.VENDEDOR_ID = <?= json_encode($vendedorId) ?>;
  </script>
  <script>
    const fmt = (n) => "R$ " + (Number(n)||0).toFixed(2);
    const toNum = (v) => parseFloat((v ?? 0)) || 0;

    const telaPagamentoEl = document.getElementById("telaPagamento");
    const reciboContainerEl = document.getElementById("reciboContainer");
    const btnConcluirEl = document.getElementById("btnConcluir");

    const vendedorId = window.VENDEDOR_ID || null;
    const dadosVenda = JSON.parse(localStorage.getItem("dadosVenda") || "null");
    const clienteNomeEl = document.getElementById("clienteSelecionadoNome");
    let carrinho = [], clienteSelecionado = null, descontoValor = 0, descontoPercentual = 0, totalVenda = 0;

    if (dadosVenda) {
      carrinho = dadosVenda.carrinho || [];
      clienteSelecionado = dadosVenda.cliente || null;
      descontoValor = toNum(dadosVenda.descontoValor);
      descontoPercentual = toNum(dadosVenda.descontoPercentual);
      totalVenda = toNum(dadosVenda.total);
    }

    if (!clienteSelecionado) {
      try {
        clienteSelecionado = JSON.parse(localStorage.getItem("clienteSelecionado") || "null");
      } catch (err) {
        console.warn("Falha ao ler cliente selecionado do armazenamento local.", err);
      }
    }

    function atualizarClienteDestaque(cliente) {
      if (!clienteNomeEl) return;
      const nome = typeof cliente?.nome === "string" ? cliente.nome.trim() : "";
      if (nome) {
        clienteNomeEl.textContent = nome;
        clienteNomeEl.classList.remove("text-muted");
      } else {
        clienteNomeEl.textContent = "Nenhum cliente selecionado";
        clienteNomeEl.classList.add("text-muted");
      }
    }

    atualizarClienteDestaque(clienteSelecionado);

    if (!totalVenda || totalVenda <= 0) {
      let bruto = carrinho.reduce((s, it) => s + (toNum(it.preco) * toNum(it.quantidade)), 0);
      totalVenda = descontoValor > 0 ? Math.max(0, bruto - descontoValor) :
                   descontoPercentual > 0 ? bruto * (1 - descontoPercentual / 100) : bruto;
    }

    document.getElementById("valorTotal").textContent = fmt(totalVenda);

    let pagamentos = [], formaSelecionada = null, totalTrocoAtual = 0;

    function isDinheiro(forma){
      if(!forma) return false;
      const nome = String(forma.nome || '').toLowerCase();
      const id = String(forma.id || '');
      return nome.includes('dinheiro') || id === '2009802';
    }

    function isBoleto(forma){
      if(!forma) return false;
      const nome = String(forma.nome || forma.forma || '').toLowerCase();
      const id = String(forma.id ?? '');
      const boletoIds = ['7697681','2941141','7749678'];
      return boletoIds.includes(id) || nome.includes('boleto');
    }

    function voltar(){ window.location.href = "index.php"; }

    function selecionarForma(nome, id){
      formaSelecionada = { nome, id };
      const falta = calcularFaltando();
      if (falta <= 0) { alert("O total já foi quitado."); return; }
      document.getElementById("valorPagamento").value = falta.toFixed(2);
      new bootstrap.Modal(document.getElementById("modalValor")).show();
    }

    function adicionarPagamento(){
      if (!formaSelecionada) return;
      let valorInformado = toNum(document.getElementById("valorPagamento").value);
      const falta = calcularFaltando();
      if (valorInformado <= 0) return alert("Valor inválido.");

      valorInformado = Math.round(valorInformado * 100) / 100;
      let valorAplicado = valorInformado;
      let troco = 0;

      if (isDinheiro(formaSelecionada)) {
        const faltaAtual = falta;
        if (valorAplicado > faltaAtual) {
          troco = Math.max(0, valorAplicado - faltaAtual);
          valorAplicado = faltaAtual;
        }
      } else if (valorAplicado > falta + 0.0001) {
        alert(`Valor informado (${fmt(valorAplicado)}) é maior que o valor faltante (${fmt(falta)}). Ajuste o valor ou selecione a opção em dinheiro.`);
        return;
      }

      valorAplicado = Math.round(valorAplicado * 100) / 100;
      troco = Math.round(troco * 100) / 100;

      if (valorAplicado <= 0) return alert("Valor inválido para esta forma de pagamento.");

      pagamentos.push({
        forma: formaSelecionada.nome,
        id: formaSelecionada.id,
        valor: valorAplicado,
        valorInformado,
        troco
      });
      atualizarLista();
      bootstrap.Modal.getInstance(document.getElementById("modalValor")).hide();
    }

    function removerPagamento(i){ pagamentos.splice(i,1); atualizarLista(); }

    function recalcularDistribuicaoDinheiro(){
      if (!pagamentos.some(p => isDinheiro(p))) return;

      let totalNaoDinheiro = pagamentos
        .filter(p => !isDinheiro(p))
        .reduce((s, p) => s + toNum(p.valor), 0);
      totalNaoDinheiro = Math.round(totalNaoDinheiro * 100) / 100;

      let restante = Math.max(0, totalVenda - totalNaoDinheiro);
      restante = Math.round(restante * 100) / 100;

      pagamentos.forEach(p => {
        if (!isDinheiro(p)) return;

        const valorInformado = toNum('valorInformado' in p ? p.valorInformado : p.valor);
        const valorAplicado = Math.min(restante, valorInformado);
        const trocoCalculado = Math.max(0, valorInformado - valorAplicado);

        p.valor = Math.round(valorAplicado * 100) / 100;
        p.troco = Math.round(trocoCalculado * 100) / 100;

        restante = Math.max(0, restante - p.valor);
        restante = Math.round(restante * 100) / 100;
      });
    }

    function atualizarLista(){
      recalcularDistribuicaoDinheiro();

      const lista = document.getElementById("listaPagamentos");
      const badgeQtd = document.getElementById("qtdPagamentos");
      const avisoLista = document.getElementById("listaVazia");
      const statusPagamento = document.getElementById("statusPagamento");
      const valorFaltante = document.getElementById("valorFaltante");
      const trocoWrapper = document.getElementById("valorTrocoWrapper");
      const trocoValor = document.getElementById("valorTroco");

      lista.innerHTML="";
      let totalPago=0;
      totalTrocoAtual = 0;
      pagamentos.forEach((p,i)=>{
        totalPago+=toNum(p.valor);
        totalTrocoAtual+=toNum(p.troco);
        const valorMostrado = 'valorInformado' in p ? p.valorInformado : p.valor;
        const trocoInfo = p.troco > 0 ? `<small class="text-muted ms-2">(Troco ${fmt(p.troco)})</small>` : '';
        lista.innerHTML += `<li class="list-group-item d-flex justify-content-between align-items-center">
          <span class="fw-semibold">${p.forma}</span>
          <span>${fmt(valorMostrado)} ${trocoInfo} <button class="btn btn-sm btn-outline-danger ms-2" onclick="removerPagamento(${i})">&times;</button></span>
        </li>`;
      });

      const falta = Math.max(0, totalVenda-totalPago);
      valorFaltante.textContent = fmt(falta);

      if (totalTrocoAtual > 0.009) {
        trocoValor.textContent = fmt(totalTrocoAtual);
        trocoWrapper.classList.remove("d-none");
      } else {
        trocoWrapper.classList.add("d-none");
      }

      if(pagamentos.length){
        avisoLista.classList.add("d-none");
      } else {
        avisoLista.classList.remove("d-none");
      }

      badgeQtd.textContent = pagamentos.length;

      if (falta > 0.01) {
        statusPagamento.textContent = `Falta pagar ${fmt(falta)}`;
        statusPagamento.classList.remove("completo");
      } else {
        statusPagamento.textContent = "Pagamento completo!";
        statusPagamento.classList.add("completo");
      }

      document.getElementById("btnConcluir").disabled = falta>0.001;
    }

    function calcularFaltando(){ return Math.max(0,totalVenda-pagamentos.reduce((s,p)=>s+toNum(p.valor),0)); }

    function mostrarProcessandoRecibo(){
      telaPagamentoEl.style.display = "none";
      reciboContainerEl.classList.add("ativo");
      reciboContainerEl.innerHTML = `
        <div class="recibo-area flex-column text-center align-items-center">
          <div class="spinner-border text-danger mb-3" role="status"></div>
          <h4 class="fw-bold text-danger mb-0">Aguarde Gerando Recibo</h4>
        </div>`;
    }

    function restaurarTelaPagamento(){
      reciboContainerEl.classList.remove("ativo");
      reciboContainerEl.innerHTML = "";
      telaPagamentoEl.style.display = "";
    }

    function validarDadosClienteParaBoleto(cliente){
      const problemas = [];
      if (!cliente || !cliente.id) {
        problemas.push('Selecione um cliente válido.');
        return problemas;
      }

      const nome = String(cliente.nome || '').trim();
      const partesNome = nome.split(/\s+/).filter(Boolean);
      if (partesNome.length < 2) {
        problemas.push('Cadastre o nome completo do cliente (nome e sobrenome).');
      }

      const documentoBruto = String(cliente.numeroDocumento || cliente.documento || '').replace(/\D+/g, '');
      if (!(documentoBruto.length === 11 || documentoBruto.length === 14)) {
        problemas.push('Informe o CPF ou CNPJ completo do cliente.');
      }

      const enderecoBase = cliente?.endereco?.geral || cliente?.endereco || {};
      const rua = String(enderecoBase.endereco || '').trim();
      const municipio = String(enderecoBase.municipio || '').trim();
      const uf = String(enderecoBase.uf || '').trim();
      const cepDigitos = String(enderecoBase.cep || '').replace(/\D+/g, '');

      if (!rua) problemas.push('Informe o logradouro (rua/avenida) do cliente.');
      if (!municipio) problemas.push('Informe a cidade do cliente.');
      if (!uf || uf.length !== 2) problemas.push('Informe a UF do cliente.');
      if (cepDigitos.length !== 8) problemas.push('Informe o CEP completo (8 dígitos) do cliente.');

      return problemas;
    }

    async function concluirVenda(){
      if (!clienteSelecionado?.id) return alert("Cliente não selecionado.");
      if (!carrinho.length) return alert("Carrinho vazio.");

      const possuiBoleto = pagamentos.some((p) => isBoleto(p));
      if (possuiBoleto) {
        const pendencias = validarDadosClienteParaBoleto(clienteSelecionado);
        if (pendencias.length) {
          alert('Não é possível concluir a venda com boleto. Ajuste o cadastro do cliente:\n' + pendencias.map((p) => `- ${p}`).join('\n'));
          return;
        }
      }

      btnConcluirEl.disabled = true;
      mostrarProcessandoRecibo();

        // 🔹 Recupera também o depósito selecionado salvo no localStorage
        const usuarioLogado = window.USUARIO_LOGADO || null;
        const LEGACY_DEPOSITO_KEY = "depositoSelecionado";
        const depositoStorageKey = usuarioLogado ? `${LEGACY_DEPOSITO_KEY}:${usuarioLogado}` : LEGACY_DEPOSITO_KEY;

        function recuperarDeposito(chave) {
          const bruto = localStorage.getItem(chave);
          if (!bruto) return null;
          try {
            const parsed = JSON.parse(bruto);
            if (parsed && parsed.id) return parsed;
          } catch (err) {
            console.warn("Cache de depósito inválido ao concluir venda, ignorando.", err);
          }
          localStorage.removeItem(chave);
          return null;
        }

        let depositoSelecionado = recuperarDeposito(depositoStorageKey);
        if (!depositoSelecionado && depositoStorageKey !== LEGACY_DEPOSITO_KEY) {
          depositoSelecionado = recuperarDeposito(LEGACY_DEPOSITO_KEY);
          localStorage.removeItem(LEGACY_DEPOSITO_KEY);
        }

        const payload = {
          clienteId: clienteSelecionado.id,
          clienteNome: clienteSelecionado.nome || "",
          descontoValor,
          descontoPercentual,
          carrinho,
          pagamentos,
          trocoTotal: totalTrocoAtual,
          vendedorId: vendedorId || null,
          usuarioLogado: usuarioLogado || null,
          deposito: depositoSelecionado // ⚠️ essencial para lançar estoque
        };


      try{
        const res=await fetch('../api/salvar-venda.php',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify(payload)
        });
        const data=await res.json();
        if(!res.ok||!data.ok) throw new Error(data?.erro || (Array.isArray(data) ? JSON.stringify(data) : 'Falha ao salvar venda.'));

        if(data?.transmitido === false || data?.transmitido === 0){
          localStorage.clear();
          const mensagem = data?.mensagem ? String(data.mensagem) : 'Venda salva localmente. O recibo ficará disponível após a transmissão.';
          const detalhe = data?.detalhe ? String(data.detalhe) : '';
          alert(`${mensagem}${detalhe ? `\n\nDetalhes: ${detalhe}` : ''}`);
          window.location.href='vendas.php';
          return;
        }

        const numeroNfe = data?.nfeNumero ?? null;
        const mensagemErroNfe = typeof data?.nfeErro === 'string' ? data.nfeErro.trim() : '';

        localStorage.clear();
        reciboContainerEl.innerHTML = `<div class="recibo-area"><div id="recibo">${data.reciboHtml}</div></div>`;

        gerarImagemRecibo().then(()=>{
          reciboContainerEl.insertAdjacentHTML("beforeend", `
            <div class="recibo-acoes">
              <button class="btn btn-primary" onclick="imprimirRecibo()">🖨 Imprimir</button>
              <button class="btn btn-secondary" onclick="copiarRecibo()">📋 Copiar</button>
              <button class="btn btn-success" onclick="compartilharRecibo()">📤 Compartilhar</button>
              <button class="btn btn-dark" onclick="window.location.href='index.php'">⬅ Nova Venda</button>
            </div>
            <div class="alert alert-success mt-3 text-center fw-bold text-uppercase">VENDA CONCLUÍDA COM SUCESSO</div>`);

          if (numeroNfe) {
            const alertaNfeOk = document.createElement('div');
            alertaNfeOk.className = 'alert alert-info mt-2 text-center';
            alertaNfeOk.textContent = `NF gerada e vinculada ao pedido. Número: ${numeroNfe}.`;
            reciboContainerEl.appendChild(alertaNfeOk);
          } else if (mensagemErroNfe) {
            const alertaNfeErro = document.createElement('div');
            alertaNfeErro.className = 'alert alert-warning mt-2 text-center';
            alertaNfeErro.textContent = `Não foi possível gerar a NF automaticamente: ${mensagemErroNfe}`;
            reciboContainerEl.appendChild(alertaNfeErro);
          }
        });
      }catch(err){
        console.error(err);
        alert('Erro: '+(err?.message || 'Erro inesperado.'));
        restaurarTelaPagamento();
        atualizarLista();
      } finally {
        btnConcluirEl.disabled = calcularFaltando() > 0.001;
      }
    }

    async function gerarImagemRecibo(){
      const recibo=document.querySelector("#recibo");
      const canvas=await html2canvas(recibo);
      const img=document.createElement("img");
      img.id="reciboImg"; img.src=canvas.toDataURL("image/png");
      img.dataset.htmlRecibo=recibo.outerHTML;
      recibo.replaceWith(img);
    }

    function imprimirRecibo(){ window.print(); }

    async function copiarRecibo(){
      try{
        const img=document.getElementById("reciboImg");
        if(!img) throw new Error("Recibo não disponível no momento.");

        if(navigator.clipboard?.write && window.ClipboardItem){
          const blob=await fetch(img.src).then(r=>r.blob());
          await navigator.clipboard.write([new ClipboardItem({'image/png':blob})]);
          alert("✅ Recibo copiado!");
          return;
        }

        const html=img.dataset.htmlRecibo || '';
        if(html && navigator.clipboard?.writeText){
          await navigator.clipboard.writeText(html);
          alert("✅ Recibo copiado como texto!");
          return;
        }

        throw new Error("Este navegador não suporta cópia automática.");
      }catch(err){
        console.error(err);
        alert("❌ Não foi possível copiar o recibo. " + (err?.message || ''));
      }
    }

    function compartilharRecibo(){
      const img=document.getElementById("reciboImg");
      fetch(img.src).then(r=>r.blob()).then(blob=>{
        const file=new File([blob],"recibo.png",{type:"image/png"});
        if(navigator.share){ navigator.share({files:[file],title:"Recibo PDV"}); }
        else alert("❌ Compartilhar não suportado neste dispositivo");
      });
    }

    atualizarLista();
  </script>
</body>
</html>
