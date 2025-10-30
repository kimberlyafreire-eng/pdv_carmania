<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}

$usuarioLogado = $_SESSION['usuario'] ?? null;
$estoquePadraoId = null;

$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
if (is_string($usuarioLogado) && $usuarioLogado !== '' && file_exists($dbFile)) {
    try {
        $db = new SQLite3($dbFile);
        $stmt = $db->prepare('SELECT estoque_padrao FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->bindValue(1, $usuarioLogado, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        if ($row && isset($row['estoque_padrao'])) {
            $valor = trim((string) $row['estoque_padrao']);
            if ($valor !== '') {
                $estoquePadraoId = $valor;
            }
        }
    } catch (Throwable $e) {
        error_log('Erro ao buscar estoque_padrao: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title>Pagamento Crediário - PDV Carmania</title>
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

    .deposito-card {
      margin-top: 1.8rem;
      padding: 1.25rem 1.5rem;
      border-radius: 14px;
      border: 1px solid rgba(220, 53, 69, 0.18);
      background: rgba(220, 53, 69, 0.04);
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .deposito-infos .label {
      display: block;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #6c757d;
      font-weight: 600;
      margin-bottom: 0.35rem;
    }

    .deposito-infos .valor {
      font-size: 1.05rem;
      font-weight: 700;
      color: #b02a37;
      word-break: break-word;
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
    <button class="btn btn-light" onclick="voltar()">⬅ Receber</button>
    <span class="navbar-text text-white fw-bold">Pagamento Crediário</span>
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
            <span class="label">Saldo a receber</span>
            <span class="valor" id="valorTotal">R$ 0,00</span>
          </div>
          <div class="valor-destaque faltando">
            <span class="label">Valor pendente</span>
            <span class="valor" id="valorFaltante">R$ 0,00</span>
            <div class="status-pagamento" id="statusPagamento">Aguardando pagamentos…</div>
          </div>
        </div>
        <div class="lista-pagamentos-container mt-4">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Pagamentos adicionados</h5>
            <span class="badge text-bg-light" id="qtdPagamentos">0</span>
          </div>
          <ul class="list-group mt-3" id="listaPagamentos"></ul>
          <p class="lista-vazia" id="listaVazia">Nenhuma forma de pagamento adicionada até o momento.</p>
          <div class="status-pagamento mt-3" id="faltando">Faltando receber: R$ 0,00</div>
        </div>

        <div class="deposito-card">
          <div class="deposito-infos">
            <span class="label">Depósito / Caixa</span>
            <span class="valor" id="depositoSelecionadoLabel">Nenhum selecionado</span>
          </div>
          <button class="btn btn-outline-danger" type="button" onclick="abrirModalDeposito()">Selecionar depósito</button>
        </div>
      </section>

      <section class="formas-wrapper">
        <div>
          <h5>Selecione a forma de pagamento</h5>
          <div class="formas-grid">
            <button class="forma-card" type="button" onclick="selecionarForma('Pix', 7697682)">Pix</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Crédito', 2941151)">Crédito</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Débito', 2941150)">Débito</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Dinheiro', 8126950)">Dinheiro</button>
          </div>
        </div>
        <button class="btn btn-success w-100 btn-lg" id="btnConcluir" disabled onclick="concluirPagamento()">
          ✅ Concluir Pagamento
        </button>
      </section>
    </div>
  </main>

  <div id="reciboContainer" class="container"></div>

  <!-- Modal depósito -->
  <div class="modal fade" id="modalDeposito" tabindex="-1" aria-labelledby="modalDepositoLabel" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalDepositoLabel">Selecionar depósito do caixa</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div id="listaDepositosCaixa" class="list-group"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-danger" onclick="confirmarDepositoSelecionado()">Confirmar</button>
        </div>
      </div>
    </div>
  </div>

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
    window.ESTOQUE_PADRAO_ID = <?= json_encode($estoquePadraoId) ?>;
  </script>
  <script>
    const fmt = (n) => "R$ " + (Number(n)||0).toFixed(2);
    const toNum = (v) => parseFloat((v ?? 0)) || 0;
    const usuarioLogado = window.USUARIO_LOGADO || null;
    const estoquePadraoUsuario = window.ESTOQUE_PADRAO_ID || null;

    const cliente = JSON.parse(localStorage.getItem("clienteRecebimento") || "null");
    const saldoData = JSON.parse(localStorage.getItem("saldoCrediario") || "null");
    const clienteNomeEl = document.getElementById("clienteSelecionadoNome");
    const depositoLabelEl = document.getElementById("depositoSelecionadoLabel");
    const modalDepositoEl = document.getElementById("modalDeposito");
    const listaDepositosCaixaEl = document.getElementById("listaDepositosCaixa");
    let cacheDepositosCaixa = [];

    const LEGACY_DEPOSITO_KEY = "depositoSelecionado";
    const depositoStorageKey = usuarioLogado ? `${LEGACY_DEPOSITO_KEY}:${usuarioLogado}` : LEGACY_DEPOSITO_KEY;

    function atualizarClienteDestaque(infoCliente) {
      if (!clienteNomeEl) return;
      const nome = typeof infoCliente?.nome === "string" ? infoCliente.nome.trim() : "";
      if (nome) {
        clienteNomeEl.textContent = nome;
        clienteNomeEl.classList.remove("text-muted");
      } else {
        clienteNomeEl.textContent = "Nenhum cliente selecionado";
        clienteNomeEl.classList.add("text-muted");
      }
    }

    atualizarClienteDestaque(cliente);

    function recuperarDeposito(chave) {
      const bruto = localStorage.getItem(chave);
      if (!bruto) return null;
      try {
        const parsed = JSON.parse(bruto);
        if (parsed && parsed.id) return parsed;
      } catch (err) {
        console.warn("Cache de depósito inválido ao carregar pagamento do crediário, ignorando.", err);
      }
      localStorage.removeItem(chave);
      return null;
    }

    function atualizarDepositoUi() {
      if (!depositoLabelEl) return;
      if (depositoSelecionado && depositoSelecionado.nome) {
        depositoLabelEl.textContent = depositoSelecionado.nome;
        depositoLabelEl.classList.remove("text-muted");
      } else if (depositoSelecionado && depositoSelecionado.id) {
        depositoLabelEl.textContent = `ID ${depositoSelecionado.id}`;
        depositoLabelEl.classList.remove("text-muted");
      } else {
        depositoLabelEl.textContent = "Nenhum selecionado";
        depositoLabelEl.classList.add("text-muted");
      }
    }

    async function carregarDepositosCaixa() {
      if (Array.isArray(cacheDepositosCaixa) && cacheDepositosCaixa.length) {
        return cacheDepositosCaixa;
      }

      const resposta = await fetch("../api/depositos.php?nocache=" + Date.now());
      if (!resposta.ok) {
        throw new Error(`Erro ao consultar depósitos (HTTP ${resposta.status})`);
      }
      const json = await resposta.json();
      const lista = (json && Array.isArray(json.data)) ? json.data : [];
      cacheDepositosCaixa = lista;
      return lista;
    }

    async function abrirModalDeposito() {
      if (!modalDepositoEl || !listaDepositosCaixaEl) return;

      const modal = bootstrap.Modal.getOrCreateInstance(modalDepositoEl);
      modal.show();
      listaDepositosCaixaEl.innerHTML = "<div class='text-center text-muted py-3'>Carregando depósitos...</div>";

      try {
        const depositos = await carregarDepositosCaixa();
        listaDepositosCaixaEl.innerHTML = "";

        if (!depositos.length) {
          listaDepositosCaixaEl.innerHTML = "<div class='alert alert-warning mb-0'>Nenhum depósito encontrado.</div>";
          return;
        }

        depositos.forEach(dep => {
          if (!dep || typeof dep !== "object") return;
          const id = String(dep.id ?? '').trim();
          if (!id) return;
          const nome = typeof dep.descricao === "string" && dep.descricao.trim()
            ? dep.descricao.trim()
            : `Depósito ${id}`;

          const item = document.createElement("label");
          item.className = "list-group-item d-flex align-items-center";

          const input = document.createElement("input");
          input.type = "radio";
          input.name = "depositoSelecionado";
          input.value = id;
          input.className = "form-check-input me-2";
          input.dataset.nome = nome;
          if (depositoSelecionado && String(depositoSelecionado.id) === id) {
            input.checked = true;
          }

          const span = document.createElement("span");
          span.className = "w-100";
          span.textContent = nome;

          item.appendChild(input);
          item.appendChild(span);
          listaDepositosCaixaEl.appendChild(item);
        });

        if (!listaDepositosCaixaEl.children.length) {
          listaDepositosCaixaEl.innerHTML = "<div class='alert alert-warning mb-0'>Nenhum depósito encontrado.</div>";
        }
      } catch (err) {
        console.error("Erro ao carregar depósitos do caixa:", err);
        listaDepositosCaixaEl.innerHTML = "<div class='alert alert-danger mb-0'>Erro ao carregar depósitos.</div>";
      }
    }

    function confirmarDepositoSelecionado() {
      if (!modalDepositoEl) return;
      const selecionado = modalDepositoEl.querySelector("input[name='depositoSelecionado']:checked");
      if (!selecionado) {
        alert("Selecione um depósito antes de confirmar.");
        return;
      }

      const nome = selecionado.dataset.nome && selecionado.dataset.nome.trim()
        ? selecionado.dataset.nome.trim()
        : (selecionado.parentElement?.querySelector("span")?.textContent?.trim() || selecionado.value);

      depositoSelecionado = {
        id: String(selecionado.value),
        nome,
      };
      localStorage.setItem(depositoStorageKey, JSON.stringify(depositoSelecionado));
      atualizarDepositoUi();

      const modal = bootstrap.Modal.getInstance(modalDepositoEl);
      if (modal) modal.hide();
    }

    async function aplicarDepositoPadraoSeNecessario() {
      atualizarDepositoUi();

      const idSelecionado = depositoSelecionado?.id ? String(depositoSelecionado.id) : null;
      const precisaBuscarNomeSelecionado = Boolean(idSelecionado) && (!depositoSelecionado?.nome || !depositoSelecionado.nome.trim());
      const precisaAplicarPadrao = !idSelecionado && estoquePadraoUsuario;

      if (!precisaBuscarNomeSelecionado && !precisaAplicarPadrao) {
        return;
      }

      const idParaBuscar = precisaBuscarNomeSelecionado
        ? idSelecionado
        : String(estoquePadraoUsuario);

      try {
        const depositos = await carregarDepositosCaixa();
        const encontrado = depositos.find(dep => String(dep?.id ?? '') === String(idParaBuscar));
        if (encontrado) {
          const nome = typeof encontrado.descricao === "string" && encontrado.descricao.trim()
            ? encontrado.descricao.trim()
            : `Depósito ${encontrado.id}`;
          depositoSelecionado = { id: String(encontrado.id), nome };
          localStorage.setItem(depositoStorageKey, JSON.stringify(depositoSelecionado));
        }
      } catch (err) {
        console.warn("Não foi possível determinar automaticamente o depósito para o caixa.", err);
      } finally {
        atualizarDepositoUi();
      }
    }

    let depositoSelecionado = recuperarDeposito(depositoStorageKey);
    if (!depositoSelecionado && depositoStorageKey !== LEGACY_DEPOSITO_KEY) {
      depositoSelecionado = recuperarDeposito(LEGACY_DEPOSITO_KEY);
      if (depositoSelecionado) {
        localStorage.removeItem(LEGACY_DEPOSITO_KEY);
      }
    }

    atualizarDepositoUi();
    aplicarDepositoPadraoSeNecessario();

    if (!cliente || !saldoData) {
      alert("Informações do cliente não encontradas. Retornando...");
      window.location.href = "receber.php";
    }

    const totalReceber = toNum(saldoData.saldoAtual || 0);
    document.getElementById("valorTotal").textContent = fmt(totalReceber);

    let pagamentos = [];
    let formaSelecionada = null;

    const telaPagamentoEl = document.getElementById("telaPagamento");
    const reciboContainerEl = document.getElementById("reciboContainer");
    const btnConcluirEl = document.getElementById("btnConcluir");

    function mostrarProcessandoRecibo() {
      telaPagamentoEl.style.display = "none";
      reciboContainerEl.classList.add("ativo");
      reciboContainerEl.innerHTML = `
        <div class="recibo-area flex-column text-center align-items-center">
          <div class="spinner-border text-danger mb-3" role="status"></div>
          <h4 class="fw-bold text-danger mb-0">Aguarde Gerando Recibo</h4>
        </div>`;
    }

    function restaurarTelaPagamento() {
      reciboContainerEl.classList.remove("ativo");
      reciboContainerEl.innerHTML = "";
      telaPagamentoEl.style.display = "";
    }

    function voltar() { window.location.href = "receber.php"; }

    function selecionarForma(nome, id) {
      formaSelecionada = { nome, id };
      document.getElementById("valorPagamento").value = "";
      new bootstrap.Modal(document.getElementById("modalValor")).show();
    }

    function adicionarPagamento() {
      if (!formaSelecionada) return;
      let valor = toNum(document.getElementById("valorPagamento").value);
      if (valor <= 0) return alert("Informe um valor válido.");

      const totalPago = pagamentos.reduce((s, p) => s + toNum(p.valor), 0);
      const limite = totalReceber - totalPago;

      if (valor > limite + 0.001) {
        alert("⚠ O valor informado ultrapassa o saldo a receber (" + fmt(limite) + ").");
        valor = limite;
      }

      pagamentos.push({ forma: formaSelecionada.nome, id: formaSelecionada.id, valor });
      atualizarLista();
      bootstrap.Modal.getInstance(document.getElementById("modalValor")).hide();
    }

    function removerPagamento(i) {
      pagamentos.splice(i, 1);
      atualizarLista();
    }

    function atualizarLista() {
      const lista = document.getElementById("listaPagamentos");
      const badgeQtd = document.getElementById("qtdPagamentos");
      const listaVazia = document.getElementById("listaVazia");
      const statusPagamento = document.getElementById("statusPagamento");
      const valorFaltante = document.getElementById("valorFaltante");
      const faltandoInfo = document.getElementById("faltando");

      lista.innerHTML = "";
      let totalPago = 0;
      pagamentos.forEach((p, i) => {
        totalPago += toNum(p.valor);
        const li = document.createElement("li");
        li.className = "list-group-item d-flex justify-content-between align-items-center";
        li.innerHTML = `
          <span class="fw-semibold">${p.forma}</span>
          <span>${fmt(p.valor)} <button class="btn btn-sm btn-outline-danger ms-2" onclick="removerPagamento(${i})">&times;</button></span>
        `;
        lista.appendChild(li);
      });

      const falta = Math.max(0, totalReceber - totalPago);
      valorFaltante.textContent = fmt(falta);
      faltandoInfo.textContent = falta > 0.01
        ? "Faltando receber: " + fmt(falta)
        : "Pagamento parcial concluído.";

      if (pagamentos.length) {
        listaVazia.classList.add("d-none");
      } else {
        listaVazia.classList.remove("d-none");
      }

      badgeQtd.textContent = pagamentos.length;

      if (falta > 0.01) {
        statusPagamento.textContent = "Receba ainda " + fmt(falta);
        statusPagamento.classList.remove("completo");
      } else {
        statusPagamento.textContent = "Saldo quitado!";
        statusPagamento.classList.add("completo");
      }

      btnConcluirEl.disabled = pagamentos.length === 0;
    }

    async function concluirPagamento() {
      if (!cliente?.id) return alert("Cliente inválido.");
      if (!pagamentos.length) return alert("Adicione pelo menos uma forma de pagamento.");
      if (!depositoSelecionado || !depositoSelecionado.id) {
        alert("Selecione o depósito/caixa que receberá o valor em dinheiro antes de concluir.");
        return;
      }

      btnConcluirEl.disabled = true;
      mostrarProcessandoRecibo();

      const payload = {
        clienteId: cliente.id,
        clienteNome: cliente.nome,
        titulos: saldoData.titulos,
        pagamentos: pagamentos,
        usuarioLogado: usuarioLogado || null,
        deposito: depositoSelecionado || null
      };

      try {
        const res = await fetch("../api/crediario/baixar.php", {
          method: "POST",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          alert("Erro ao registrar baixa: " + (data.erro || JSON.stringify(data)));
          restaurarTelaPagamento();
          btnConcluirEl.disabled = false;
          return;
        }

        localStorage.removeItem("clienteRecebimento");
        localStorage.removeItem("saldoCrediario");

        telaPagamentoEl.style.display = "none";
        reciboContainerEl.classList.add("ativo");
        reciboContainerEl.innerHTML = `<div class="recibo-area"><div id="recibo">${data.reciboHtml}</div></div>`;

        await gerarImagemRecibo();

        reciboContainerEl.innerHTML += `
          <div class="recibo-acoes">
            <button class="btn btn-primary" onclick="imprimirRecibo()">🖨 Imprimir</button>
            <button class="btn btn-secondary" onclick="copiarRecibo()">📋 Copiar</button>
            <button class="btn btn-success" onclick="compartilharRecibo()">📤 Compartilhar</button>
            <button class="btn btn-danger" onclick="voltar()">⬅ Voltar</button>
          </div>
        `;

      } catch (err) {
        console.error(err);
        alert("Erro inesperado: " + err);
        restaurarTelaPagamento();
        btnConcluirEl.disabled = false;
      }
    }

    async function gerarImagemRecibo() {
      const recibo = document.querySelector("#recibo");
      if (!recibo) return;
      const canvas = await html2canvas(recibo, { scale: 2 });
      const img = document.createElement("img");
      img.id = "reciboImg";
      img.src = canvas.toDataURL("image/png");
      recibo.replaceWith(img);
    }

    function imprimirRecibo() { window.print(); }

    function copiarRecibo() {
      const img = document.getElementById("reciboImg");
      if (!img) return;
      fetch(img.src).then(r => r.blob()).then(blob => {
        navigator.clipboard.write([new ClipboardItem({'image/png': blob})]);
        alert("✅ Recibo copiado!");
      });
    }

    function compartilharRecibo() {
      const img = document.getElementById("reciboImg");
      if (!img) return;
      fetch(img.src).then(r => r.blob()).then(blob => {
        const file = new File([blob], "recibo.png", { type: "image/png" });
        if (navigator.share) {
          navigator.share({ files: [file], title: "Recibo PDV Carmania" });
        } else alert("❌ Compartilhar não suportado neste dispositivo");
      });
    }

    atualizarLista();
  </script>
</body>
</html>
