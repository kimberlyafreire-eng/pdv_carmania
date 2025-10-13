<?php
session_start();
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
  <title>Carrinho de Compras - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .navbar-custom { background-color: #dc3545; }
    .navbar-custom .d-flex {
      flex-wrap: wrap;
      gap: 0.5rem;
      justify-content: flex-end;
    }
    .produto-item { border-bottom: 1px solid #ddd; padding: 12px 0; }
    .summary-card {
      background: #fff; border-radius: 12px; padding: 16px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .estoque-btn {
      background: white;
      border: 2px solid #dc3545;
      color: #dc3545;
      font-weight: bold;
    }
    .estoque-btn:hover { background: #dc3545; color: white; }

    .modal-travado .modal-dialog {
      margin: 0 auto;
      max-width: 480px;
      width: calc(100% - 2rem);
    }

    .modal-travado .modal-content {
      border-radius: 16px;
      box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
    }

    .modal-travado .modal-body {
      max-height: 60vh;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }

    @media (max-width: 768px) {
      .container { padding-left: 1rem; padding-right: 1rem; }
      .summary-card { padding: 16px; }
      .navbar-custom .btn { flex: 1 1 100%; }
      .navbar-custom .d-flex { justify-content: center; }
    }

    @media (max-width: 576px) {
      body { font-size: 0.95rem; }

      .navbar-custom {
        padding-top: 0.75rem !important;
        padding-bottom: 0.75rem !important;
      }

      .navbar-custom .btn {
        flex: 1 1 calc(50% - 0.5rem);
        min-width: 140px;
      }

      .navbar-custom .btn:first-child {
        flex: 0 0 auto;
        min-width: unset;
      }

      .modal-travado {
        padding: 1.5rem 1rem;
      }

      .modal-travado .modal-dialog {
        margin: 0 auto;
        width: 100%;
        min-height: calc(100vh - 3rem);
        display: flex;
        align-items: center;
      }

      .modal-travado .modal-content {
        width: 100%;
        border-radius: 18px;
      }

      .modal-travado .modal-header,
      .modal-travado .modal-footer {
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .modal-travado .modal-footer .btn {
        flex: 1 1 calc(50% - 0.5rem);
      }

      .modal-travado .modal-body {
        max-height: 65vh;
      }
    }
  </style>
</head>
<body>

  <!-- Barra superior -->
  <nav class="navbar navbar-dark navbar-custom px-3 d-flex justify-content-between align-items-center">
    <button class="btn btn-light" onclick="voltar()">⬅ Voltar</button>
    <span class="navbar-text text-white fw-bold">Carrinho</span>
    <div class="d-flex gap-2">
      <button id="btnEstoque" class="btn estoque-btn" onclick="abrirModalEstoque()">🏷 Estoque: <span id="estoqueSelecionadoLabel">Nenhum</span></button>
      <button id="btnAtualizarEstoque" class="btn btn-outline-warning" onclick="atualizarEstoqueLocal()">🔄 Atualizar Estoque</button>
      <button class="btn btn-outline-light" onclick="abrirModalCliente()">
        <span id="btnClienteLabel">Selecionar Cliente</span>
      </button>
    </div>
  </nav>

  <div class="container py-3">
    <div id="produtos-carrinho" class="mb-3"></div>

    <div class="summary-card mt-4">
      <div class="d-flex justify-content-between">
        <span>Total:</span><span id="totalBruto">R$ 0,00</span>
      </div>
      <div class="d-flex justify-content-between text-success fw-bold mt-2">
        <span>Total com Desconto:</span><span id="totalComDesconto">R$ 0,00</span>
      </div>
    </div>

    <div class="d-flex gap-2 mt-4">
      <button class="btn btn-outline-primary flex-fill" onclick="abrirModalDesconto()">Aplicar Desconto</button>
      <button class="btn btn-success flex-fill" onclick="irParaPagamento()">💰 Finalizar Venda</button>
    </div>
    <button class="btn btn-danger w-100 mt-2" onclick="limparCarrinho()">🗑 Limpar Carrinho</button>
  </div>

  <!-- Modal Cliente -->
  <div class="modal fade modal-travado" id="modalCliente" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
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
    <div class="modal-dialog modal-dialog-centered">
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
    <div class="modal-dialog modal-dialog-centered">
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
    async function carregarClientes() {
      try {
        const res = await fetch("../cache/clientes-cache.json?nocache=" + Date.now());
        const json = await res.json();
        clientesLista = json.data || json;
      } catch {
        clientesLista = [];
      }
    }

    function abrirModalCliente() {
      document.getElementById("clienteBusca").value = "";
      document.getElementById("listaClientes").innerHTML = "";
      new bootstrap.Modal(document.getElementById("modalCliente")).show();
    }

    document.addEventListener("input", (e) => {
      if (e.target.id !== "clienteBusca") return;
      const busca = e.target.value.toLowerCase();
      const lista = document.getElementById("listaClientes");
      lista.innerHTML = "";
      if (busca.length < 2) return;
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
      clienteSelecionado = { id: input.dataset.id, nome: input.value };
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
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <label>Qtd:</label>
            <input type="number" min="1" value="${qtd}" onchange="atualizarQuantidade(${i}, this.value)" class="form-control form-control-sm" style="width:60px;">
            <label>Valor:</label>
            <input type="number" min="0" step="0.01" value="${preco.toFixed(2)}" onchange="atualizarPreco(${i}, this.value)" class="form-control form-control-sm" style="width:80px;">
            <span class="ms-auto">Subtotal: R$ ${(subtotal).toFixed(2)}</span>
            <button class="btn btn-sm btn-outline-danger" onclick="removerProduto(${i})">Remover</button>
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

    function irParaPagamento() {
      if (!clienteSelecionado) return alert("Selecione um cliente.");
      if (!depositoSelecionado) return alert("Selecione um estoque.");
      if (!verificarEstoqueAntesVenda()) return;

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
