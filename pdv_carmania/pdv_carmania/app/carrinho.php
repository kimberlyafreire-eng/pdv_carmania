<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
  header("Location: login.php");
  exit();
}

// Caminho do banco de usuários
$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
$estoquePadraoId = null;
$usuarioLogado = $_SESSION['usuario'] ?? null;

try {
  if (file_exists($dbFile)) {
    $db = new SQLite3($dbFile);
    // Ignora maiúsculas e minúsculas
    $stmt = $db->prepare("SELECT estoque_padrao FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->bindValue(1, $_SESSION['usuario'], SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if ($row && !empty($row['estoque_padrao'])) {
      $estoquePadraoId = trim($row['estoque_padrao']); // <- é o ID do depósito
    }
  }
} catch (Throwable $e) {
  error_log("Erro ao buscar estoque_padrao: " . $e->getMessage());
}

// Envia pro JS
echo "<script>
window.USUARIO_LOGADO = " . json_encode($usuarioLogado) . ";
window.ESTOQUE_PADRAO_ID = " . json_encode($estoquePadraoId) . ";
</script>";

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Carrinho de Compras - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
    }

    body {
      background-color: #f8f9fa;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .navbar-custom {
      background-color: #dc3545;
      padding-block: 0.75rem;
    }

    .navbar-inner {
      display: flex;
      flex-direction: column;
      align-items: stretch;
      gap: 0.75rem;
      padding-inline: min(4vw, 2.5rem);
    }

    .navbar-custom .navbar-title {
      font-size: clamp(1rem, 2.8vw, 1.25rem);
    }

    .navbar-actions {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem;
      justify-content: flex-end;
    }

    .estoque-btn {
      background: white;
      border: 2px solid #dc3545;
      color: #dc3545;
      font-weight: 600;
    }

    .estoque-btn:hover,
    .estoque-btn:focus {
      background: #dc3545;
      color: white;
    }

    main {
      flex: 1;
    }

    .cart-wrapper {
      padding-block: 1.5rem 2rem;
    }

    .page-header {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .page-header h2 {
      font-size: clamp(1.5rem, 4vw, 2rem);
    }

    @media (min-width: 768px) {
      .page-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
      }
    }

    .cart-wrapper .actions-top {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .summary-card .card-body {
      padding: 1.5rem;
      display: grid;
      gap: 0.75rem;
    }

    .summary-card .row-line {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }

    #produtos-carrinho {
      display: grid;
      gap: 0.75rem;
    }

    .produto-item {
      background: #fff;
      border-radius: 14px;
      padding: 16px;
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
      border: 1px solid rgba(220, 53, 69, 0.08);
      display: grid;
      gap: 0.5rem;
    }

    .produto-item strong {
      font-size: 1rem;
      line-height: 1.35;
    }

    .produto-controles {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem;
    }

    .produto-controles label {
      font-size: 0.9rem;
      color: #495057;
      margin-right: 0.25rem;
    }

    .quantidade-input,
    .preco-input {
      width: 92px;
    }

    .subtotal-label {
      margin-left: auto;
      font-weight: 600;
    }

    .btn-sm-full {
      min-height: 40px;
    }

    .modal-travado .modal-content {
      border-radius: 18px;
      box-shadow: 0 18px 40px rgba(0, 0, 0, 0.25);
    }

    .modal-travado .modal-body {
      max-height: min(65vh, 520px);
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      padding-block: 1rem;
    }

    .modal-travado .modal-header,
    .modal-travado .modal-footer {
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .modal-travado .modal-footer .btn {
      min-width: 120px;
    }

    @media (max-width: 992px) {
      .quantidade-input,
      .preco-input {
        width: 80px;
      }
    }

    @media (max-width: 768px) {
      body {
        font-size: 0.95rem;
      }

      .navbar-inner {
        padding-inline: 1rem;
      }

      .navbar-actions {
        justify-content: stretch;
      }

      .navbar-actions .btn {
        flex: 1 1 48%;
      }

      .cart-wrapper {
        padding-inline: 1rem;
      }

      .page-header {
        margin-bottom: 1.25rem;
      }

      .summary-card .card-body {
        padding: 1.25rem;
      }

      .produto-controles {
        gap: 0.75rem;
      }

      .subtotal-label {
        width: 100%;
        text-align: right;
        margin-left: 0;
      }

      .summary-card {
        padding: 16px;
      }
    }

    @media (max-width: 576px) {
      .navbar-inner {
        padding-inline: 0.75rem;
      }

      .navbar-actions {
        width: 100%;
        flex-direction: column;
      }

      .navbar-actions .btn {
        width: 100%;
        flex: 1 1 auto;
        min-height: 48px;
      }

      .cart-wrapper {
        padding-inline: 0.75rem;
        padding-bottom: 2.5rem;
      }

      .page-header h2 {
        font-size: 1.5rem;
      }

      .summary-card .card-body {
        padding: 1.15rem;
      }

      .produto-item {
        padding: 14px;
      }

      .produto-controles {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
      }

      .produto-controles label {
        margin-right: 0;
      }

      .quantidade-input,
      .preco-input {
        width: 100%;
      }

      .produto-controles .btn-sm-full {
        width: 100%;
      }

      .modal-travado {
        padding: 0;
      }

      .modal-travado .modal-content {
        border-radius: 0;
        min-height: 100vh;
      }

      .modal-travado .modal-header,
      .modal-travado .modal-footer {
        padding: 1rem;
      }

      .modal-travado .modal-body {
        max-height: unset;
        padding: 1rem;
      }

      .modal-travado .modal-footer .btn {
        flex: 1 1 48%;
        min-width: unset;
      }
    }
  </style>
</head>
<body>

  <!-- Barra superior -->
  <nav class="navbar navbar-dark navbar-custom">
    <div class="container-fluid navbar-inner">
      <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <button class="btn btn-light" onclick="voltar()">⬅ Voltar</button>
        <span class="navbar-text text-white fw-bold navbar-title flex-grow-1 text-center text-sm-start">Carrinho</span>
      </div>
      <div class="navbar-actions">
      <button id="btnEstoque" class="btn estoque-btn" onclick="abrirModalEstoque()">🏷 Estoque: <span id="estoqueSelecionadoLabel">Nenhum</span></button>
      <button id="btnAtualizarEstoque" class="btn btn-outline-warning" onclick="atualizarEstoqueLocal()">🔄 Atualizar Estoque</button>
      <button class="btn btn-outline-light" onclick="abrirModalCliente()">
        <span id="btnClienteLabel">Selecionar Cliente</span>
      </button>
      </div>
    </div>
  </nav>

  <main class="container cart-wrapper">
    <div class="page-header">
      <div>
        <h2 class="mb-1">Carrinho de Vendas</h2>
        <p class="text-muted mb-0">Confirme os itens, escolha o cliente e finalize a venda rapidamente.</p>
      </div>
      <button class="btn btn-success btn-lg align-self-start d-none d-md-inline-flex" onclick="irParaPagamento()">💰 Finalizar Venda</button>
    </div>

    <div id="produtos-carrinho" class="mb-3"></div>

    <div class="card shadow-sm summary-card mt-4">
      <div class="card-body">
        <div class="row-line">
          <span>Total:</span><span id="totalBruto">R$ 0,00</span>
        </div>
        <div class="row-line text-success fw-bold">
          <span>Total com Desconto:</span><span id="totalComDesconto">R$ 0,00</span>
        </div>
      </div>
    </div>

    <div class="actions-top mt-4">
      <button class="btn btn-outline-primary flex-fill btn-sm-full" onclick="abrirModalDesconto()">Aplicar Desconto</button>
      <button class="btn btn-success flex-fill btn-sm-full d-md-none" onclick="irParaPagamento()">💰 Finalizar Venda</button>
    </div>
    <button class="btn btn-danger w-100 mt-2" onclick="limparCarrinho()">🗑 Limpar Carrinho</button>
  </main>

  <!-- Modal Cliente -->
  <div class="modal fade modal-travado" id="modalCliente" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Selecionar Cliente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" id="clienteBusca" class="form-control" placeholder="Digite o nome do cliente">
          <div id="listaClientes" class="list-group mt-2"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-danger" onclick="limparCliente()">Limpar</button>
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" onclick="confirmarCliente()">OK</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Desconto -->
  <div class="modal fade" id="modalDesconto" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Aplicar Desconto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Desconto (R$)</label>
            <input type="number" id="descontoValor" class="form-control" step="0.01">
          </div>
          <div>
            <label class="form-label">Desconto (%)</label>
            <input type="number" id="descontoPercentual" class="form-control" step="0.01">
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-danger" onclick="limparDesconto()">Limpar</button>
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" onclick="confirmarDesconto()">Aplicar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Estoque -->
  <div class="modal fade modal-travado" id="modalEstoque" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Selecionar Estoque</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="formDepositos">
            <div id="listaDepositos" class="list-group"></div>
          </form>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" onclick="confirmarDeposito()">Confirmar Estoque Selecionado</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    let carrinho = JSON.parse(localStorage.getItem("carrinho") || "[]");
    let clientesLista = [];
    let clienteSelecionado = JSON.parse(localStorage.getItem("clienteSelecionado") || "null");

    function atualizarClienteSelecionadoSeIncompleto() {
      if (!clienteSelecionado || !clienteSelecionado.id) return;
      const possuiDocumento = typeof clienteSelecionado.numeroDocumento === 'string' && clienteSelecionado.numeroDocumento.trim();
      const enderecoGeral = clienteSelecionado?.endereco?.geral || clienteSelecionado?.endereco || null;
      const possuiEnderecoBasico = enderecoGeral && typeof enderecoGeral === 'object'
        && enderecoGeral.endereco && enderecoGeral.municipio && enderecoGeral.uf && enderecoGeral.cep;
      if (possuiDocumento && possuiEnderecoBasico) {
        return;
      }

      const encontrado = clientesLista.find((cli) => String(cli.id) === String(clienteSelecionado.id));
      if (encontrado) {
        clienteSelecionado = JSON.parse(JSON.stringify(encontrado));
        localStorage.setItem("clienteSelecionado", JSON.stringify(clienteSelecionado));
        atualizarBotaoCliente();
      }
    }
    let descontoValor = parseFloat(localStorage.getItem("descontoValor") || 0);
    let descontoPercentual = parseFloat(localStorage.getItem("descontoPercentual") || 0);
    const usuarioLogado = window.USUARIO_LOGADO || null;
    const LEGACY_DEPOSITO_KEY = "depositoSelecionado";
    const depositoStorageKey = usuarioLogado ? `${LEGACY_DEPOSITO_KEY}:${usuarioLogado}` : LEGACY_DEPOSITO_KEY;
    // 👇 vem do PHP (id do depósito padrão do usuário logado)
    const estoquePadraoUsuario = window.ESTOQUE_PADRAO_ID || null;

    function lerDepositoDoStorage(chave) {
      const bruto = localStorage.getItem(chave);
      if (!bruto) return null;
      try {
        const parsed = JSON.parse(bruto);
        if (parsed && parsed.id) return parsed;
      } catch (err) {
        console.warn("Cache de depósito inválido, removendo.", err);
      }
      localStorage.removeItem(chave);
      return null;
    }

    let depositoSelecionado = lerDepositoDoStorage(depositoStorageKey);

    // Migra e limpa cache legado (sem usuário atrelado)
    if (!depositoSelecionado && depositoStorageKey !== LEGACY_DEPOSITO_KEY) {
      const legado = lerDepositoDoStorage(LEGACY_DEPOSITO_KEY);
      if (legado) {
        depositoSelecionado = legado;
        localStorage.setItem(depositoStorageKey, JSON.stringify(legado));
      }
      localStorage.removeItem(LEGACY_DEPOSITO_KEY);
    }

    // Se existir estoque padrão, ignora cache salvo diferente do padrão
    if (estoquePadraoUsuario) {
      const isMesmoDeposito = depositoSelecionado && String(depositoSelecionado.id) === String(estoquePadraoUsuario);
      if (!isMesmoDeposito) {
        depositoSelecionado = null;
        localStorage.removeItem(depositoStorageKey);
      }
    }

    // ========================
    // ======= CLIENTES =======
    // ========================
    function sanitizarClientes(listaBruta) {
      if (!Array.isArray(listaBruta)) {
        return [];
      }

      const mapa = new Map();

      listaBruta.forEach((cliente) => {
        if (!cliente || typeof cliente !== 'object') return;

        const id = String(cliente.id ?? '').trim();
        if (!id) return;

        const nomesPossiveis = [cliente.nome, cliente.razaoSocial, cliente.fantasia];
        let nomeLimpo = '';
        for (let i = 0; i < nomesPossiveis.length; i += 1) {
          const valor = nomesPossiveis[i];
          if (typeof valor === 'string') {
            const texto = valor.trim();
            if (texto) {
              nomeLimpo = texto;
              break;
            }
          }
        }

        if (!nomeLimpo) return;

        const existente = mapa.get(id) || { id, nome: nomeLimpo };
        existente.nome = nomeLimpo;

        const doc = typeof cliente.numeroDocumento === 'string' && cliente.numeroDocumento.trim()
          ? cliente.numeroDocumento.trim()
          : (typeof cliente.documento === 'string' && cliente.documento.trim() ? cliente.documento.trim() : null);
        if (doc) existente.numeroDocumento = doc;

        if (typeof cliente.celular === 'string' && cliente.celular.trim()) {
          existente.celular = cliente.celular.trim();
        }

        if (typeof cliente.telefone === 'string' && cliente.telefone.trim()) {
          existente.telefone = cliente.telefone.trim();
        }

        if (cliente.endereco && typeof cliente.endereco === 'object') {
          existente.endereco = cliente.endereco;
        }

        mapa.set(id, existente);
      });

      return Array.from(mapa.values()).sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR', { sensitivity: 'base' }));
    }

    async function buscarClientes(url) {
      const resposta = await fetch(url, { cache: 'no-store' });
      if (!resposta.ok) {
        throw new Error(`Falha ao carregar clientes: ${resposta.status}`);
      }

      const texto = await resposta.text();
      if (!texto) {
        return [];
      }

      let json;
      try {
        json = JSON.parse(texto);
      } catch (erro) {
        throw new Error('JSON inválido ao carregar clientes.');
      }

      let listaBruta = [];
      if (json && typeof json === 'object' && Array.isArray(json.data)) {
        listaBruta = json.data;
      } else if (Array.isArray(json)) {
        listaBruta = json;
      }

      if (!Array.isArray(listaBruta) || !listaBruta.length) {
        return [];
      }

      return sanitizarClientes(listaBruta);
    }

    async function carregarClientes() {
      const fontes = [
        `../api/clientes.php?nocache=${Date.now()}`,
        `../cache/clientes-cache.json?nocache=${Date.now()}`,
      ];

      for (let i = 0; i < fontes.length; i += 1) {
        const url = fontes[i];
        try {
          const resultado = await buscarClientes(url);
          if (resultado.length) {
            clientesLista = resultado;
            atualizarClienteSelecionadoSeIncompleto();
            return;
          }
        } catch (erro) {
          console.warn(`Falha ao ler clientes de ${url}`, erro);
        }
      }

      clientesLista = [];
    }

    carregarClientes();

    function abrirModalCliente() {
      const input = document.getElementById("clienteBusca");
      input.value = "";
      delete input.dataset.id;
      document.getElementById("listaClientes").innerHTML = "";
      carregarClientes();
      new bootstrap.Modal(document.getElementById("modalCliente")).show();
    }

    document.addEventListener("input", (e) => {
      if (e.target.id !== "clienteBusca") return;
      const busca = e.target.value.toLowerCase();
      const lista = document.getElementById("listaClientes");
      delete e.target.dataset.id;
      lista.innerHTML = "";
      if (busca.length < 2) return;
      if (!clientesLista.length) return;
      const resultados = clientesLista.filter(c => c.nome.toLowerCase().includes(busca)).slice(0, 6);
      resultados.forEach(cliente => {
        const item = document.createElement("button");
        item.className = "list-group-item list-group-item-action";
        item.textContent = cliente.nome;
        item.onclick = () => {
          document.getElementById("clienteBusca").value = cliente.nome;
          document.getElementById("clienteBusca").dataset.id = cliente.id;
          lista.innerHTML = "";
        };
        lista.appendChild(item);
      });
    });

    function confirmarCliente() {
      const input = document.getElementById("clienteBusca");
      if (!input.dataset.id) return alert("Selecione um cliente válido.");
      const selecionadoId = String(input.dataset.id);
      const encontrado = clientesLista.find((cli) => String(cli.id) === selecionadoId);
      if (encontrado) {
        clienteSelecionado = JSON.parse(JSON.stringify(encontrado));
      } else {
        clienteSelecionado = { id: selecionadoId, nome: input.value };
      }
      localStorage.setItem("clienteSelecionado", JSON.stringify(clienteSelecionado));
      atualizarBotaoCliente();
      bootstrap.Modal.getInstance(document.getElementById("modalCliente")).hide();
    }

    function limparCliente() {
      clienteSelecionado = null;
      localStorage.removeItem("clienteSelecionado");
      atualizarBotaoCliente();
      bootstrap.Modal.getInstance(document.getElementById("modalCliente")).hide();
    }

    function atualizarBotaoCliente() {
      document.getElementById("btnClienteLabel").textContent =
        clienteSelecionado ? clienteSelecionado.nome : "Selecionar Cliente";
    }

    // ========================
    // ======= DESCONTO =======
    // ========================
    function abrirModalDesconto() {
      const campoValor = document.getElementById("descontoValor");
      const campoPerc  = document.getElementById("descontoPercentual");

      campoValor.value = Number(descontoValor || 0).toFixed(2);
      campoPerc.value  = Number(descontoPercentual || 0).toFixed(2);

      // Sincroniza valor ↔ percentual
      campoValor.oninput = () => {
        const total = totalCarrinho();
        const val = parseFloat(String(campoValor.value).replace(",", ".")) || 0;
        campoPerc.value = total > 0 ? ((val / total) * 100).toFixed(2) : "0.00";
      };

      campoPerc.oninput = () => {
        const total = totalCarrinho();
        const perc = parseFloat(String(campoPerc.value).replace(",", ".")) || 0;
        campoValor.value = total > 0 ? ((total * perc) / 100).toFixed(2) : "0.00";
      };

      new bootstrap.Modal(document.getElementById("modalDesconto")).show();
    }

    function confirmarDesconto() {
      descontoValor = parseFloat(document.getElementById("descontoValor").value || 0);
      descontoPercentual = parseFloat(document.getElementById("descontoPercentual").value || 0);
      localStorage.setItem("descontoValor", String(descontoValor));
      localStorage.setItem("descontoPercentual", String(descontoPercentual));
      renderizarCarrinho();
      bootstrap.Modal.getInstance(document.getElementById("modalDesconto")).hide();
    }

    function limparDesconto() {
      descontoValor = 0;
      descontoPercentual = 0;
      localStorage.removeItem("descontoValor");
      localStorage.removeItem("descontoPercentual");
      renderizarCarrinho();
      bootstrap.Modal.getInstance(document.getElementById("modalDesconto")).hide();
    }

    // ========================
    // ======= ESTOQUE ========
    // ========================
    function atualizarBotaoEstoque() {
      document.getElementById("estoqueSelecionadoLabel").textContent =
        depositoSelecionado ? depositoSelecionado.nome : "Nenhum";
    }

    async function abrirModalEstoque() {
      const modal = document.getElementById("modalEstoque");
      const lista = document.getElementById("listaDepositos");

      new bootstrap.Modal(modal).show();
      lista.innerHTML = "<div class='text-center text-muted py-3'>Carregando estoques...</div>";

      try {
        const res = await fetch("../api/depositos.php?nocache=" + Date.now());
        const json = await res.json();
        lista.innerHTML = "";

        if (!json.data || !Array.isArray(json.data) || json.data.length === 0) {
          lista.innerHTML = "<div class='alert alert-warning'>Nenhum depósito encontrado.</div>";
          return;
        }

        json.data.forEach(dep => {
          const id = String(dep.id);
          const checked = (depositoSelecionado && String(depositoSelecionado.id) === id) ? "checked" : "";
          const div = document.createElement("label");
          div.className = "list-group-item d-flex align-items-center";
          div.innerHTML = `
            <input class="form-check-input me-2" type="radio" name="depositoSelecionado" value="${id}" ${checked}>
            <span class="w-100">${dep.descricao}</span>
          `;
          lista.appendChild(div);
        });
      } catch (err) {
        console.error("Erro ao carregar depósitos:", err);
        lista.innerHTML = "<div class='alert alert-danger'>Erro ao carregar depósitos.</div>";
      }
    }

    function confirmarDeposito() {
      const selecionado = document.querySelector("input[name='depositoSelecionado']:checked");
      if (!selecionado) {
        alert("Selecione um depósito antes de confirmar.");
        return;
      }

      const nome = selecionado.parentElement.querySelector("span").textContent.trim();
      depositoSelecionado = { id: selecionado.value, nome };
      localStorage.setItem(depositoStorageKey, JSON.stringify(depositoSelecionado));

      // Atualiza o botão principal
      const label = document.getElementById("estoqueSelecionadoLabel");
      if (label) label.textContent = nome;

      // Fecha o modal
      const modal = bootstrap.Modal.getInstance(document.getElementById("modalEstoque"));
      if (modal) modal.hide();

      // Recarrega o estoque do carrinho
      consultarEstoqueCarrinho();
    }

    async function atualizarEstoqueLocal() {
      if (!depositoSelecionado) return alert("Selecione um depósito antes.");
      if (!confirm("Baixar estoque atualizado do Bling?")) return;
      const btn = document.getElementById("btnAtualizarEstoque");
      btn.disabled = true;
      btn.textContent = "⏳ Atualizando...";
      try {
        const res = await fetch(`../api/atualizar-estoque.php?depositoId=${depositoSelecionado.id}`);
        const json = await res.json();
        if (json.ok) {
          alert(`✅ Estoque atualizado com ${json.total} itens.`);
          await consultarEstoqueCarrinho();
        } else {
          alert("❌ Falha ao atualizar estoque.");
        }
      } catch (e) {
        alert("❌ Erro de comunicação.");
      } finally {
        btn.disabled = false;
        btn.textContent = "🔄 Atualizar Estoque";
      }
    }
    
    async function aplicarEstoquePadraoSeNecessario() {
      // Se já existe um depósito salvo manualmente ou o usuário não tem padrão, sai
      if (depositoSelecionado || !estoquePadraoUsuario) return;
    
      try {
        const res = await fetch("../api/depositos.php?nocache=" + Date.now());
        const json = await res.json();
        const lista = (json && Array.isArray(json.data)) ? json.data : [];
    
        // Busca o depósito pelo ID salvo no usuário
        const dep = lista.find(d => String(d.id) === String(estoquePadraoUsuario));
        if (dep) {
          depositoSelecionado = { id: String(dep.id), nome: dep.descricao };
          localStorage.setItem(depositoStorageKey, JSON.stringify(depositoSelecionado));
          atualizarBotaoEstoque();
          await consultarEstoqueCarrinho();
          console.log("✅ Estoque padrão aplicado:", dep.descricao);
        } else {
          console.warn("⚠️ Estoque padrão do usuário não encontrado entre os depósitos do sistema:", estoquePadraoUsuario);
        }
      } catch (err) {
        console.error("Erro ao aplicar estoque padrão:", err);
      }
    }

    async function consultarEstoqueCarrinho() {
      if (!depositoSelecionado || carrinho.length === 0) return;
      const ids = carrinho.map(p => p.id);
      const res = await fetch("../api/consulta-estoque-local.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ depositoId: depositoSelecionado.id, produtos: ids })
      });
      const json = await res.json();

      if (json.ok) {
        for (const item of carrinho) {
          item.disponivel = json.estoques[item.id] ?? 0;
        }
        renderizarCarrinho();
      }
    }

    // ========================
    // ======= CARRINHO =======
    // ========================
    function totalCarrinho() {
      return carrinho.reduce((s, it) => s + (Number(it.preco) * Number(it.quantidade)), 0);
    }

    function renderizarCarrinho() {
      const container = document.getElementById("produtos-carrinho");
      container.innerHTML = "";
      if (carrinho.length === 0) {
        container.innerHTML = "<div class='alert alert-warning'>Carrinho vazio.</div>";
        atualizarTotal(0);
        return;
      }

      let total = 0;
      carrinho.forEach((item, i) => {
        const preco = Number(item.preco) || 0;
        const qtd = Number(item.quantidade) || 0;
        const subtotal = preco * qtd;
        total += subtotal;
        const div = document.createElement("div");
        div.className = "produto-item";
        div.innerHTML = `
          <div><strong>${item.nome}</strong></div>
          <div class="text-muted small">Estoque: ${item.disponivel ?? 0}</div>
          <div class="produto-controles">
            <label class="mb-0">Qtd:</label>
            <input type="number" inputmode="numeric" min="1" value="${qtd}" onchange="atualizarQuantidade(${i}, this.value)" class="form-control form-control-sm quantidade-input">
            <label class="mb-0">Valor:</label>
            <input type="number" inputmode="decimal" min="0" step="0.01" value="${preco.toFixed(2)}" onchange="atualizarPreco(${i}, this.value)" class="form-control form-control-sm preco-input">
            <span class="subtotal-label">Subtotal: R$ ${(subtotal).toFixed(2)}</span>
            <button class="btn btn-outline-danger btn-sm btn-sm-full" onclick="removerProduto(${i})">Remover</button>
          </div>`;
        container.appendChild(div);
      });
      atualizarTotal(total);
    }

    function atualizarPreco(i, valor) {
      const novoValor = parseFloat(valor);
      if (isNaN(novoValor) || novoValor < 0) return;
      carrinho[i].preco = novoValor;
      salvarCarrinho();
      renderizarCarrinho();
    }

    function atualizarQuantidade(i, valor) {
      const v = parseInt(valor);
      if (isNaN(v) || v < 1) return;
      carrinho[i].quantidade = v;
      salvarCarrinho();
      renderizarCarrinho();
    }

    function removerProduto(i) {
      carrinho.splice(i, 1);
      salvarCarrinho();
      renderizarCarrinho();
    }

    function salvarCarrinho() {
      localStorage.setItem("carrinho", JSON.stringify(carrinho));
    }

    function atualizarTotal(total) {
      const val = Number(descontoValor) || 0;
      const perc = Number(descontoPercentual) || 0;
      const desconto = val > 0 ? val : (perc > 0 ? (total * perc / 100) : 0);
      const totalFinal = Math.max(0, total - desconto);
      document.getElementById("totalBruto").textContent = "R$ " + total.toFixed(2);
      document.getElementById("totalComDesconto").textContent = "R$ " + totalFinal.toFixed(2);
      return totalFinal;
    }

    function verificarEstoqueAntesVenda() {
      for (const item of carrinho) {
        if ((item.disponivel ?? 0) < item.quantidade) {
          alert(`❌ Estoque insuficiente para "${item.nome}". Disponível: ${item.disponivel}`);
          return false;
        }
      }
      return true;
    }

    function formatarDataISOLocal(data) {
      const ano = data.getFullYear();
      const mes = String(data.getMonth() + 1).padStart(2, '0');
      const dia = String(data.getDate()).padStart(2, '0');
      return `${ano}-${mes}-${dia}`;
    }

    async function verificarCaixaDepositoAberto() {
      if (!depositoSelecionado || !depositoSelecionado.id) {
        throw new Error('Depósito não selecionado.');
      }

      const params = new URLSearchParams({
        depositoId: depositoSelecionado.id,
        depositoNome: depositoSelecionado.nome || '',
        _: Date.now().toString()
      });

      let resposta;
      try {
        resposta = await fetch(`../api/caixa.php?${params.toString()}`);
      } catch (erro) {
        console.error('Erro de rede ao validar status do caixa:', erro);
        throw new Error('Não foi possível verificar o status do caixa. Verifique sua conexão e tente novamente.');
      }

      let json = null;
      try {
        json = await resposta.json();
      } catch (erro) {
        console.error('Resposta inválida ao consultar o caixa:', erro);
      }

      if (!resposta.ok) {
        const mensagemErro = json && json.erro ? json.erro : 'Falha ao verificar o status do caixa do depósito selecionado.';
        throw new Error(mensagemErro);
      }

      if (!json || !json.ok) {
        const mensagemErro = json && json.erro ? json.erro : 'Não foi possível obter o status do caixa.';
        throw new Error(mensagemErro);
      }

      const status = json && json.caixa && json.caixa.status
        ? String(json.caixa.status).toLowerCase()
        : '';

      let dataAbertura = null;
      const movimentos = json && Array.isArray(json.movimentos) ? json.movimentos : [];
      if (movimentos.length > 0) {
        const movimentoAbertura = movimentos.find((mov) => {
          return mov && typeof mov.tipoSlug === 'string' && mov.tipoSlug.toLowerCase() === 'abertura';
        });
        if (movimentoAbertura && movimentoAbertura.dataHora) {
          const dataStr = String(movimentoAbertura.dataHora);
          dataAbertura = dataStr.slice(0, 10);
        }
      }

      return {
        aberto: status === 'aberto',
        dataAbertura,
      };
    }

    async function irParaPagamento() {
      if (!clienteSelecionado) return alert("Selecione um cliente.");
      if (!depositoSelecionado) return alert("Selecione um estoque.");
      if (!verificarEstoqueAntesVenda()) return;

      let caixaInfo = { aberto: false, dataAbertura: null };
      try {
        caixaInfo = await verificarCaixaDepositoAberto();
      } catch (erro) {
        alert(erro.message || 'Não foi possível verificar o status do caixa.');
        return;
      }

      if (!caixaInfo.aberto) {
        const nomeDeposito = depositoSelecionado.nome ? ` do depósito ${depositoSelecionado.nome}` : '';
        alert(`O caixa${nomeDeposito} está fechado. Abra o caixa antes de finalizar a venda.`);
        return;
      }

      if (caixaInfo.dataAbertura) {
        const hoje = formatarDataISOLocal(new Date());
        if (caixaInfo.dataAbertura !== hoje) {
          const nomeDeposito = depositoSelecionado.nome ? ` do depósito ${depositoSelecionado.nome}` : '';
          alert(`O caixa${nomeDeposito} foi aberto em ${caixaInfo.dataAbertura}. Feche o caixa e abra novamente com a data atual para prosseguir.`);
          return;
        }
      }

      const total = totalCarrinho();
      const val = Number(descontoValor) || 0;
      const perc = Number(descontoPercentual) || 0;
      const totalFinal = val > 0 ? Math.max(0, total - val) :
                         (perc > 0 ? total * (1 - perc/100) : total);

      const dadosVenda = {
        carrinho, // inclui preços unitários atualizados
        cliente: clienteSelecionado,
        descontoValor: val,
        descontoPercentual: perc,
        total: Number(totalFinal.toFixed(2)),
        deposito: depositoSelecionado
      };

      localStorage.setItem("dadosVenda", JSON.stringify(dadosVenda));
      window.location.href = "pagamento.php";
    }

    function limparCarrinho() {
      if (confirm("Tem certeza que deseja limpar o carrinho?")) {
        carrinho = [];
        salvarCarrinho();
        renderizarCarrinho();
      }
    }

    function voltar() { window.location.href = "index.php"; }

    // ====== INIT ======
    carregarClientes();
    renderizarCarrinho();
    atualizarBotaoCliente();
    atualizarBotaoEstoque();
    if (depositoSelecionado) {
      consultarEstoqueCarrinho();
    }
    aplicarEstoquePadraoSeNecessario();
    
  </script>
</body>
</html>
