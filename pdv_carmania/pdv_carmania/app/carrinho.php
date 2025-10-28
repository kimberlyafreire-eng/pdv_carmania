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

    .navbar-top {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      width: 100%;
    }

    .navbar-custom .navbar-title {
      font-size: clamp(1rem, 2.8vw, 1.25rem);
      flex: 1 1 auto;
      text-align: center;
    }

    .voltar-btn {
      flex: 0 0 auto;
    }

    .navbar-actions {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem;
      justify-content: flex-end;
      overflow-x: auto;
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

    .produto-topo {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .produto-item strong {
      font-size: 1rem;
      line-height: 1.35;
      flex: 1 1 auto;
    }

    .produto-estoque {
      font-size: 0.85rem;
      color: #6c757d;
      flex: 0 0 auto;
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

    .produto-controles .controle {
      display: flex;
      align-items: center;
      gap: 0.35rem;
    }

    .quantidade-input {
      width: 92px;
    }

    .controle-preco {
      white-space: nowrap;
    }

    .valor-unitario-group {
      width: 130px;
    }

    .valor-unitario-input {
      text-align: right;
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
      .quantidade-input {
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

      .navbar-title {
        text-align: center;
      }

      .navbar-actions {
        justify-content: center;
        flex-wrap: nowrap;
      }

      .navbar-actions .btn {
        flex: 1 1 auto;
        min-width: 0;
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
        flex-wrap: nowrap;
      }

      .navbar-actions .btn {
        flex: 1 1 auto;
        min-height: 48px;
        min-width: 0;
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
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .produto-controles label {
        margin-right: 0;
      }

      .quantidade-input {
        width: 72px;
      }

      .controle-preco {
        flex: 0 1 auto;
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
      <div class="navbar-top">
        <button class="btn btn-light voltar-btn" onclick="voltar()">⬅ Voltar</button>
        <span class="navbar-text text-white fw-bold navbar-title">Carrinho</span>
      </div>
      <div class="navbar-actions">
        <button id="btnEstoque" class="btn estoque-btn" onclick="abrirModalEstoque()">🏷 Estoque: <span id="estoqueSelecionadoLabel">Nenhum</span></button>
        <button class="btn btn-outline-light" onclick="abrirModalCliente()">
          <span id="btnClienteLabel">Selecionar Cliente</span>
        </button>
        <button id="btnAtualizarEstoque" class="btn btn-outline-warning" onclick="atualizarEstoqueLocal()">🔄 Atualizar Estoque</button>
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
          <input type="text" id="clienteBusca" class="form-control" placeholder="Digite o nome ou celular do cliente">
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
    const CLIENTES_CACHE_KEY = "clientesListaCache";
    const CLIENTES_CACHE_TTL_MS = 1000 * 60 * 30; // 30 minutos
    const CLIENTES_CACHE_REFRESH_INTERVAL_MS = 1000 * 60 * 2; // força atualização em segundo plano após 2 minutos
    let clientesCarregandoPromise = null;
    let clientesCacheExpirado = false;
    let clientesCacheUltimaAtualizacao = 0;

    function removerAcentos(texto) {
      return String(texto || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
    }

    function normalizarTexto(texto) {
      return removerAcentos(texto).toLowerCase();
    }

    function extrairDigitos(valor) {
      return String(valor || '').replace(/\D+/g, '');
    }

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

    function lerClientesCacheLocal() {
      const bruto = localStorage.getItem(CLIENTES_CACHE_KEY);
      if (!bruto) return null;
      try {
        const cache = JSON.parse(bruto);
        if (!cache || !Array.isArray(cache.lista) || cache.lista.length === 0) {
          return null;
        }
        const atualizadoEm = typeof cache.atualizadoEm === 'number' ? cache.atualizadoEm : 0;
        cache.atualizadoEm = atualizadoEm;
        cache.expirado = atualizadoEm > 0 && (Date.now() - atualizadoEm) > CLIENTES_CACHE_TTL_MS;
        return cache;
      } catch (erro) {
        console.warn('Cache local de clientes inválido. Limpando.', erro);
        localStorage.removeItem(CLIENTES_CACHE_KEY);
        return null;
      }
    }

    function salvarClientesCacheLocal(lista) {
      try {
        const payload = { lista, atualizadoEm: Date.now() };
        localStorage.setItem(CLIENTES_CACHE_KEY, JSON.stringify(payload));
      } catch (erro) {
        console.warn('Não foi possível salvar o cache local de clientes.', erro);
      }
    }

    function aplicarClientesCacheLocalSeDisponivel({ permitirExpirado = false } = {}) {
      const cache = lerClientesCacheLocal();
      if (!cache) return false;
      if (!permitirExpirado && cache.expirado) {
        return false;
      }
      clientesLista = cache.lista;
      clientesCacheExpirado = !!cache.expirado;
      clientesCacheUltimaAtualizacao = cache.atualizadoEm || 0;
      atualizarClienteSelecionadoSeIncompleto();
      return true;
    }

    aplicarClientesCacheLocalSeDisponivel({ permitirExpirado: true });

    function precisaAtualizarClientesRapido() {
      if (clientesCacheExpirado) return true;
      if (!clientesCacheUltimaAtualizacao) return true;
      return (Date.now() - clientesCacheUltimaAtualizacao) > CLIENTES_CACHE_REFRESH_INTERVAL_MS;
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

    async function buscarClientes(url, cacheMode = 'no-store') {
      const resposta = await fetch(url, { cache: cacheMode });
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

    async function carregarClientes({ forcarAtualizacao = false } = {}) {
      if (!forcarAtualizacao) {
        if (clientesLista.length > 0 && !clientesCacheExpirado) {
          return clientesLista;
        }
        if (clientesLista.length === 0 && aplicarClientesCacheLocalSeDisponivel()) {
          if (!clientesCacheExpirado) {
            return clientesLista;
          }
        }
        if (clientesCarregandoPromise) {
          return clientesCarregandoPromise;
        }
      }

      const listaAnterior = Array.isArray(clientesLista) ? [...clientesLista] : [];
      const cacheExpiradoAnterior = clientesCacheExpirado;
      const ultimaAtualizacaoAnterior = clientesCacheUltimaAtualizacao;

      const fontes = [
        { url: `../api/clientes.php?nocache=${Date.now()}`, cache: 'no-store' },
        { url: '../cache/clientes-cache.json', cache: 'default' },
      ];

      clientesCarregandoPromise = (async () => {
        for (let i = 0; i < fontes.length; i += 1) {
          const { url, cache } = fontes[i];
          try {
            const resultado = await buscarClientes(url, cache);
            if (resultado.length) {
              clientesLista = resultado;
              clientesCacheExpirado = false;
              clientesCacheUltimaAtualizacao = Date.now();
              salvarClientesCacheLocal(resultado);
              atualizarClienteSelecionadoSeIncompleto();
              return resultado;
            }
          } catch (erro) {
            console.warn(`Falha ao ler clientes de ${url}`, erro);
          }
        }

        if (listaAnterior.length) {
          clientesLista = listaAnterior;
          clientesCacheExpirado = cacheExpiradoAnterior;
          clientesCacheUltimaAtualizacao = ultimaAtualizacaoAnterior;
          return clientesLista;
        }

        if (aplicarClientesCacheLocalSeDisponivel({ permitirExpirado: true })) {
          return clientesLista;
        }

        clientesLista = [];
        clientesCacheExpirado = false;
        return clientesLista;
      })();

      try {
        return await clientesCarregandoPromise;
      } finally {
        clientesCarregandoPromise = null;
      }
    }

    const deveForcarAtualizacaoInicial = precisaAtualizarClientesRapido();
    carregarClientes(deveForcarAtualizacaoInicial ? { forcarAtualizacao: true } : {});

    function abrirModalCliente() {
      const input = document.getElementById("clienteBusca");
      const lista = document.getElementById("listaClientes");
      input.value = "";
      delete input.dataset.id;
      lista.innerHTML = "";

      const modalElemento = document.getElementById("modalCliente");
      const modal = new bootstrap.Modal(modalElemento);
      modal.show();

      const promessaAtualizacao = carregarClientes({ forcarAtualizacao: true })
        .catch((erro) => {
          console.warn('Não foi possível atualizar a lista de clientes imediatamente.', erro);
          return [];
        });

      if (!clientesLista.length) {
        lista.innerHTML = "<div class='text-center text-muted py-2'>Carregando clientes...</div>";
        promessaAtualizacao.then(() => {
          if (!clientesLista.length) {
            lista.innerHTML = "<div class='alert alert-warning mb-0'>Nenhum cliente cadastrado encontrado.</div>";
          } else if (lista.innerHTML.includes('Carregando clientes')) {
            lista.innerHTML = "";
          }
        });
      } else {
        promessaAtualizacao.then(() => {
          if (input.value && input.value.length >= 2) {
            input.dispatchEvent(new Event("input"));
          }
        });
      }
    }

    document.addEventListener("input", (e) => {
      if (e.target.id !== "clienteBusca") return;
      const termoBruto = e.target.value.trim();
      const buscaTexto = normalizarTexto(termoBruto);
      const buscaNumerica = extrairDigitos(termoBruto);
      const contemLetras = /[a-z]/i.test(removerAcentos(termoBruto));
      const temBuscaTexto = contemLetras && buscaTexto.length >= 2;
      const temBuscaNumerica = buscaNumerica.length >= 3;
      const lista = document.getElementById("listaClientes");
      delete e.target.dataset.id;
      lista.innerHTML = "";
      if (!temBuscaTexto && !temBuscaNumerica) return;
      if (!clientesLista.length) return;
      const resultados = clientesLista.filter((cli) => {
        const nomeNormalizado = normalizarTexto(cli.nome);
        const documentoTexto = normalizarTexto(cli.numeroDocumento || '');
        const documentoNumerico = extrairDigitos(cli.numeroDocumento || '');
        const celularNumerico = extrairDigitos(cli.celular || '');
        const telefoneNumerico = extrairDigitos(cli.telefone || '');

        const correspondeTexto = temBuscaTexto
          ? nomeNormalizado.includes(buscaTexto) || documentoTexto.includes(buscaTexto)
          : false;
        const correspondeNumerico = temBuscaNumerica
          ? documentoNumerico.includes(buscaNumerica)
            || celularNumerico.includes(buscaNumerica)
            || telefoneNumerico.includes(buscaNumerica)
          : false;

        return correspondeTexto || correspondeNumerico;
      }).slice(0, 6);
      resultados.forEach(cliente => {
        const item = document.createElement("button");
        item.className = "list-group-item list-group-item-action";
        const infoContato = cliente.celular || cliente.telefone || cliente.numeroDocumento || '';
        item.innerHTML = infoContato
          ? `<div class="fw-semibold">${cliente.nome}</div><small class="text-muted">${infoContato}</small>`
          : cliente.nome;
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
        const subtotalFormatado = subtotal.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const div = document.createElement("div");
        div.className = "produto-item";
        div.innerHTML = `
          <div class="produto-topo">
            <strong>${item.nome}</strong>
            <span class="produto-estoque">Estoque: ${item.disponivel ?? 0}</span>
          </div>
          <div class="produto-controles">
            <div class="controle controle-quantidade">
              <label class="mb-0" for="quantidade-${i}">Qtd:</label>
              <input id="quantidade-${i}" type="number" inputmode="numeric" min="1" value="${qtd}" onchange="atualizarQuantidade(${i}, this.value)" class="form-control form-control-sm quantidade-input">
            </div>
            <div class="controle controle-preco">
              <label class="mb-0" for="valor-${i}">Valor:</label>
              <div class="input-group input-group-sm valor-unitario-group">
                <span class="input-group-text">R$</span>
                <input id="valor-${i}" type="number" inputmode="decimal" min="0" step="0.01" value="${preco.toFixed(2)}" onchange="atualizarPreco(${i}, this.value)" class="form-control valor-unitario-input">
              </div>
            </div>
            <span class="subtotal-label">Subtotal: R$ ${subtotalFormatado}</span>
            <button class="btn btn-outline-danger btn-sm btn-sm-full" onclick="removerProduto(${i})">Remover</button>
          </div>`;
        container.appendChild(div);
      });
      atualizarTotal(total);
    }

    function atualizarQuantidade(i, valor) {
      const v = parseInt(valor);
      if (isNaN(v) || v < 1) return;
      carrinho[i].quantidade = v;
      salvarCarrinho();
      renderizarCarrinho();
    }

    function atualizarPreco(i, valor) {
      const v = parseFloat(valor);
      const item = carrinho[i];
      if (!item) {
        return;
      }

      const precoAtual = Number(item.preco) || 0;
      if (Number.isNaN(v) || v < 0) {
        renderizarCarrinho();
        return;
      }

      const EPSILON = 0.0001;
      if (v <= precoAtual + EPSILON) {
        alert("O novo valor unitário deve ser maior que o valor atual do produto.");
        renderizarCarrinho();
        return;
      }

      item.preco = v;
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
