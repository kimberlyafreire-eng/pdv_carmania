<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}

$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
$estoquePadraoId = null;
$usuarioLogado = $_SESSION['usuario'] ?? null;

if ($usuarioLogado && file_exists($dbFile)) {
    try {
        $db = new SQLite3($dbFile);
        $stmt = $db->prepare('SELECT estoque_padrao FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->bindValue(1, $usuarioLogado, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        if ($row && !empty($row['estoque_padrao'])) {
            $estoquePadraoId = trim((string) $row['estoque_padrao']);
        }
    } catch (Throwable $e) {
        error_log('Erro ao buscar estoque padrão: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Produtos - PDV Carmania</title>
  <link href="../assets/cdn-cache.php?asset=bootstrap-css" rel="stylesheet" />
  <style>
    body {
      background-color: #f8f9fa;
      min-height: 100vh;
    }

    .navbar {
      min-height: 60px;
    }

    .table thead {
      background-color: #f1f3f5;
    }

    .table thead th {
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-size: 0.8rem;
      color: #6c757d;
    }

    .produto-imagem {
      width: 70px;
      height: 70px;
      object-fit: contain;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      background: #fff;
    }

    .celula-nome {
      max-width: 360px;
      word-break: break-word;
    }

    .badge-estoque {
      font-size: 0.8rem;
      padding: 0.35rem 0.6rem;
    }

    @media (max-width: 767px) {
      .table-responsive {
        box-shadow: none !important;
      }

      .celula-nome {
        max-width: 220px;
      }

      .acoes-col {
        min-width: 220px;
      }
    }

    .shadow-soft {
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    }

    .form-select:disabled {
      opacity: 0.7;
    }

    tr[data-id] {
      cursor: pointer;
      transition: background-color 0.2s ease-in-out;
    }

    tr[data-id]:hover {
      background-color: #fff2f2;
    }

    tr[data-id].linha-aberta {
      background-color: #ffe5e5;
    }

    .linha-detalhe-estoque td {
      background-color: #fff8f8;
      padding: 0;
      border-top: 1px solid rgba(220, 53, 69, 0.15);
    }

    .detalhe-estoque-conteudo {
      padding: 1.25rem 1.5rem;
    }

    .detalhe-estoque-conteudo .list-group-item {
      background-color: transparent;
      border: none;
      border-top: 1px solid rgba(0, 0, 0, 0.05);
      padding-left: 0;
      padding-right: 0;
    }

    .detalhe-estoque-conteudo .list-group-item:first-child {
      border-top: none;
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-danger">
    <div class="container-fluid d-flex justify-content-between align-items-center">
      <button class="btn btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">MENU</button>
      <a href="logout.php" class="btn btn-outline-light">Sair</a>
    </div>
  </nav>

  <div class="offcanvas offcanvas-start bg-light" tabindex="-1" id="menuLateral">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title">Menu</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <ul class="list-unstyled">
        <li><a class="btn btn-outline-danger w-100 mb-2" href="index.php">Vender</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="clientes.php">Clientes</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="receber.php">Receber</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="vendas.php">Vendas</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="caixa.php">Caixa</a></li>
        <li><a class="btn btn-danger w-100" href="produtos.php">Produtos</a></li>
      </ul>
    </div>
  </div>

  <div class="container py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
      <div>
        <h2 class="mb-1">Produtos</h2>
        <p class="text-muted mb-0">Consulte estoque por depósito ou de forma geral e mantenha os cadastros sincronizados.</p>
      </div>
      <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2 w-100">
        <div class="flex-grow-1">
          <input
            type="search"
            id="campoBusca"
            class="form-control"
            placeholder="Buscar produto por nome, código, código de barras ou GTIN..."
            autocomplete="off"
          />
        </div>
        <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2">
          <div class="d-flex align-items-center gap-2">
            <label for="selectDeposito" class="fw-semibold text-muted mb-0">Depósito:</label>
            <select id="selectDeposito" class="form-select">
              <option value="geral">Todos os depósitos</option>
            </select>
          </div>
          <button id="btnSincronizarProdutos" type="button" class="btn btn-danger fw-semibold">
            SINCRONIZAR PRODUTOS
          </button>
        </div>
      </div>
    </div>

    <div id="alertas"></div>

    <div class="card border-0 shadow-soft">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th scope="col" class="text-center" style="width: 90px;">Foto</th>
                <th scope="col">Nome / Descrição</th>
                <th scope="col" class="text-center" style="width: 170px;">Quantidade em estoque</th>
                <th scope="col" class="text-end" style="width: 140px;">Valor</th>
                <th scope="col" class="text-end acoes-col" style="width: 220px;">Ações</th>
              </tr>
            </thead>
            <tbody id="tabelaProdutos">
              <tr>
                <td colspan="5" class="text-center py-5 text-muted">Carregando produtos...</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script src="../assets/cdn-cache.php?asset=bootstrap-js"></script>
  <script>
    window.ESTOQUE_PADRAO_ID = <?php echo json_encode($estoquePadraoId); ?>;
  </script>
  <script>
    const tabelaProdutos = document.getElementById('tabelaProdutos');
    const selectDeposito = document.getElementById('selectDeposito');
    const alertasEl = document.getElementById('alertas');
    const btnSincronizar = document.getElementById('btnSincronizarProdutos');
    const campoBusca = document.getElementById('campoBusca');

    let produtos = [];
    let estoqueAtual = {};
    let depositos = [];
    let termoBuscaAtual = '';
    const estoqueDetalhadoCache = Object.create(null);
    const detalhesAbertos = new Set();
    const carregamentosPendentes = new Map();

    const formatarMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const formatarNumero = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });

    const removerAcentos = (texto) => typeof texto === 'string'
      ? texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      : '';

    const normalizarTexto = (texto) => removerAcentos(String(texto || '')).toLowerCase();

    function obterTermosBusca(termo) {
      return normalizarTexto(termo).split(/\s+/).filter(Boolean);
    }

    function obterClasseEstoque(quantidade) {
      if (quantidade > 0) {
        return 'bg-success-subtle text-success';
      }
      if (quantidade < 0) {
        return 'bg-danger-subtle text-danger';
      }
      return 'bg-secondary-subtle text-secondary';
    }

    function escapeHtml(texto) {
      return String(texto ?? '').replace(/[&<>'"]/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\'': '&#39;', '"': '&quot;' })[c];
      });
    }

    function mostrarAlerta(tipo, mensagem) {
      const alerta = document.createElement('div');
      alerta.className = `alert alert-${tipo} alert-dismissible fade show`;
      alerta.innerHTML = `
        <span>${mensagem}</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      `;
      alertasEl.innerHTML = '';
      alertasEl.appendChild(alerta);
    }

    function limparAlerta() {
      alertasEl.innerHTML = '';
    }

    function obterProdutosFiltrados() {
      const termo = termoBuscaAtual;
      const termoNormalizado = normalizarTexto(termo);
      const termosNome = obterTermosBusca(termo);
      const termoLower = String(termo || '').toLowerCase();
      const termoLowerCompacto = termoLower.replace(/\s+/g, '');

      return produtos.filter((produto) => {
        if (!termoNormalizado) {
          return true;
        }

        if (!produto || typeof produto !== 'object') {
          return false;
        }

        const nomeNormalizado = normalizarTexto(produto.nome || '');
        const codigo = String(produto.codigo || '').toLowerCase();
        const gtin = String(produto.gtin || '').toLowerCase();
        const codigoCompacto = codigo.replace(/\s+/g, '');
        const gtinCompacto = gtin.replace(/\s+/g, '');

        const correspondeNome = termosNome.every((parte) => nomeNormalizado.includes(parte));
        const correspondeCodigo = codigo.includes(termoLower) || codigoCompacto.includes(termoLowerCompacto);
        const correspondeGtin = gtin.includes(termoLower) || gtinCompacto.includes(termoLowerCompacto);

        return correspondeNome || correspondeCodigo || correspondeGtin;
      });
    }

    function renderizarTabela() {
      detalhesAbertos.clear();
      carregamentosPendentes.clear();

      if (!produtos.length) {
        tabelaProdutos.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">Nenhum produto encontrado no banco local.</td></tr>';
        return;
      }

      const filtrados = obterProdutosFiltrados();

      if (!filtrados.length) {
        const mensagemBusca = termoBuscaAtual
          ? `Nenhum produto encontrado para "${escapeHtml(termoBuscaAtual)}".`
          : 'Nenhum produto encontrado no banco local.';
        tabelaProdutos.innerHTML = `<tr><td colspan="5" class="text-center py-5 text-muted">${mensagemBusca}</td></tr>`;
        return;
      }

      const linhas = filtrados.map(produto => {
        const estoque = estoqueAtual[produto.id] ?? 0;
        const estoqueBadgeClass = obterClasseEstoque(estoque);
        const gtin = produto.gtin ? escapeHtml(produto.gtin) : '—';

        return `
          <tr data-id="${produto.id}" title="Clique para ver o estoque por depósito">
            <td class="text-center">
              <img src="${escapeHtml(produto.imagemURL || '')}" alt="Imagem do produto" class="produto-imagem" onerror="this.src='../imagens/sem-imagem.png'" />
            </td>
            <td class="celula-nome">
              <div class="fw-semibold">${escapeHtml(produto.nome)}</div>
              <div class="text-muted small">Código: ${escapeHtml(produto.codigo || '—')}</div>
              <div class="text-muted small" data-gtin="${produto.id}">GTIN: <span class="valor-gtin">${gtin}</span></div>
            </td>
            <td class="text-center">
              <div class="d-flex flex-column align-items-center gap-1">
                <span class="badge rounded-pill badge-estoque ${estoqueBadgeClass}">${formatarNumero.format(estoque)}</span>
                <small class="text-muted">Ver depósitos</small>
              </div>
            </td>
            <td class="text-end">${formatarMoeda.format(produto.preco || 0)}</td>
            <td class="text-end" data-no-detalhe>
              <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary" data-action="consultar_gtin" data-id="${produto.id}">Consultar código de barras</button>
                <button type="button" class="btn btn-outline-danger" data-action="excluir" data-id="${produto.id}">Excluir</button>
              </div>
            </td>
          </tr>
        `;
      }).join('');

      tabelaProdutos.innerHTML = linhas;
    }

    async function carregarProdutos() {
      tabelaProdutos.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">Carregando produtos...</td></tr>';
      try {
        const resposta = await fetch('../api/produtos-json.php?nocache=' + Date.now());
        const json = await resposta.json();
        if (!json || !Array.isArray(json.data)) {
          throw new Error('Retorno inválido ao listar produtos.');
        }
        produtos = json.data
          .filter((produto) => produto && typeof produto === 'object')
          .map((produto) => ({
            ...produto,
            nome: produto.nome ?? '',
            codigo: produto.codigo ?? '',
            gtin: produto.gtin ?? ''
          }));
      } catch (erro) {
        console.error('Erro ao carregar produtos:', erro);
        mostrarAlerta('danger', 'Não foi possível carregar os produtos. Tente novamente.');
        produtos = [];
      }
    }

    async function carregarDepositos() {
      try {
        const resposta = await fetch('../api/depositos.php?nocache=' + Date.now());
        const json = await resposta.json();
        if (!json || !Array.isArray(json.data)) {
          throw new Error('Retorno inválido da API de depósitos.');
        }
        depositos = json.data;
      } catch (erro) {
        console.error('Erro ao carregar depósitos:', erro);
        depositos = [];
        mostrarAlerta('warning', 'Não foi possível carregar os depósitos do Bling. Utilize a visão geral.');
      }

      popularSelectDepositos();
    }

    function popularSelectDepositos() {
      const valorSelecionado = selectDeposito.value || 'geral';
      selectDeposito.innerHTML = '';

      const opcaoGeral = document.createElement('option');
      opcaoGeral.value = 'geral';
      opcaoGeral.textContent = 'Todos os depósitos';
      selectDeposito.appendChild(opcaoGeral);

      depositos.forEach(dep => {
        const option = document.createElement('option');
        option.value = dep.id;
        option.textContent = dep.descricao;
        selectDeposito.appendChild(option);
      });

      if (valorSelecionado && Array.from(selectDeposito.options).some(opt => opt.value === valorSelecionado)) {
        selectDeposito.value = valorSelecionado;
      } else if (window.ESTOQUE_PADRAO_ID && Array.from(selectDeposito.options).some(opt => opt.value === String(window.ESTOQUE_PADRAO_ID))) {
        selectDeposito.value = String(window.ESTOQUE_PADRAO_ID);
      } else {
        selectDeposito.value = 'geral';
      }
    }

    async function carregarEstoqueDeposito() {
      const depositoId = selectDeposito.value || 'geral';
      try {
        const url = '../api/produtos-estoque.php?depositoId=' + encodeURIComponent(depositoId);
        const resposta = await fetch(url);
        const json = await resposta.json();
        if (!json.ok) {
          throw new Error(json.erro || 'Erro ao consultar estoque.');
        }
        estoqueAtual = json.estoque || {};
      } catch (erro) {
        console.error('Erro ao carregar estoque:', erro);
        estoqueAtual = {};
        mostrarAlerta('danger', 'Não foi possível carregar o estoque selecionado. Exibindo valores zerados.');
      }
      atualizarEstoqueNaTabela();
    }

    function atualizarEstoqueNaTabela() {
      produtos = produtos.map(produto => {
        if (produto && typeof produto === 'object') {
          return { ...produto };
        }
        return produto;
      });
      renderizarTabela();
    }

    function limparCacheDetalhado() {
      Object.keys(estoqueDetalhadoCache).forEach((key) => delete estoqueDetalhadoCache[key]);
    }

    function obterDescricaoDeposito(idDeposito) {
      if (idDeposito === null || idDeposito === undefined || idDeposito === '') {
        return 'Depósito não informado';
      }

      const encontrado = depositos.find(dep => String(dep.id) === String(idDeposito));
      if (encontrado) {
        return encontrado.descricao || `Depósito ${idDeposito}`;
      }

      return `Depósito ${idDeposito}`;
    }

    function montarHtmlDetalheEstoque(dados) {
      if (!dados || dados.ok === false) {
        throw new Error(dados?.erro || 'Não foi possível carregar o estoque detalhado.');
      }

      const totalFormatado = formatarNumero.format(dados.total ?? 0);
      const depositosDetalhados = Array.isArray(dados.depositos) ? dados.depositos : [];

      if (!dados.possuiDepositos) {
        const saldo = depositosDetalhados.length ? depositosDetalhados[0].saldo ?? 0 : dados.total ?? 0;
        const badgeClass = obterClasseEstoque(saldo);
        return `
          <div class="detalhe-estoque-conteudo">
            <div class="fw-semibold text-danger mb-2">Estoque consolidado</div>
            <p class="text-muted mb-3">O banco local não possui depósitos individualizados para este produto.</p>
            <div class="d-flex align-items-center gap-3">
              <span class="badge rounded-pill badge-estoque ${badgeClass}">${formatarNumero.format(saldo)}</span>
              <span class="text-muted small">Saldo total</span>
            </div>
          </div>
        `;
      }

      if (!depositosDetalhados.length) {
        return `
          <div class="detalhe-estoque-conteudo">
            <div class="fw-semibold text-danger mb-2">Estoque por depósito</div>
            <p class="text-muted mb-0">Nenhum saldo cadastrado para este produto nos depósitos sincronizados.</p>
          </div>
        `;
      }

      const depositoSelecionado = selectDeposito.value || 'geral';

      const linhas = depositosDetalhados.map(item => {
        const descricao = obterDescricaoDeposito(item.idDeposito);
        const saldo = item.saldo ?? 0;
        const badgeClass = obterClasseEstoque(saldo);
        const isSelecionado = depositoSelecionado !== 'geral' && String(depositoSelecionado) === String(item.idDeposito);
        const badgeFiltro = isSelecionado ? '<span class="badge bg-danger-subtle text-danger ms-2">Filtro atual</span>' : '';

        return `
          <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <div class="fw-semibold">${escapeHtml(descricao)}${badgeFiltro}</div>
              <div class="text-muted small">ID: ${escapeHtml(item.idDeposito ?? '—')}</div>
            </div>
            <span class="badge rounded-pill badge-estoque ${badgeClass}">${formatarNumero.format(saldo)}</span>
          </div>
        `;
      }).join('');

      return `
        <div class="detalhe-estoque-conteudo">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <span class="fw-semibold text-danger">Estoque por depósito</span>
            <span class="text-muted small">Total: ${totalFormatado}</span>
          </div>
          <div class="list-group list-group-flush">
            ${linhas}
          </div>
          <div class="text-muted small mt-3">Clique novamente na linha do produto para ocultar o detalhamento.</div>
        </div>
      `;
    }

    async function obterEstoqueDetalhado(idProduto) {
      const chave = String(idProduto);
      if (estoqueDetalhadoCache[chave]) {
        return estoqueDetalhadoCache[chave];
      }

      const resposta = await fetch('../api/produto-estoque-detalhado.php?idProduto=' + encodeURIComponent(chave));
      const json = await resposta.json();
      if (!json.ok) {
        throw new Error(json.erro || 'Falha ao carregar o detalhamento de estoque.');
      }

      estoqueDetalhadoCache[chave] = json;
      return json;
    }

    function fecharDetalheEstoque(linha) {
      const idProduto = String(linha.getAttribute('data-id'));
      const proximaLinha = linha.nextElementSibling;
      if (proximaLinha && proximaLinha.classList.contains('linha-detalhe-estoque')) {
        proximaLinha.remove();
      }
      linha.classList.remove('linha-aberta');
      detalhesAbertos.delete(idProduto);
      carregamentosPendentes.delete(idProduto);
    }

    async function abrirDetalheEstoque(linha) {
      const idProduto = String(linha.getAttribute('data-id'));

      const existente = linha.nextElementSibling;
      if (existente && existente.classList.contains('linha-detalhe-estoque')) {
        existente.remove();
      }

      const detalheRow = document.createElement('tr');
      detalheRow.className = 'linha-detalhe-estoque';
      detalheRow.innerHTML = '<td colspan="5"><div class="py-3 text-center text-muted small">Carregando detalhamento de estoque...</div></td>';
      linha.insertAdjacentElement('afterend', detalheRow);
      linha.classList.add('linha-aberta');

      detalhesAbertos.add(idProduto);
      const marcador = Symbol('carregamento');
      carregamentosPendentes.set(idProduto, marcador);

      try {
        const dados = await obterEstoqueDetalhado(idProduto);
        if (carregamentosPendentes.get(idProduto) !== marcador || !detalheRow.isConnected) {
          return;
        }
        const html = montarHtmlDetalheEstoque(dados);
        detalheRow.innerHTML = `<td colspan="5">${html}</td>`;
      } catch (erro) {
        if (carregamentosPendentes.get(idProduto) !== marcador || !detalheRow.isConnected) {
          return;
        }
        detalheRow.innerHTML = `<td colspan="5"><div class="py-3 text-center text-danger small">${escapeHtml(erro.message || 'Não foi possível carregar o estoque detalhado.')}</div></td>`;
      } finally {
        if (carregamentosPendentes.get(idProduto) === marcador) {
          carregamentosPendentes.delete(idProduto);
        }
      }
    }

    async function alternarDetalheEstoque(linha) {
      if (!linha || !linha.hasAttribute('data-id')) {
        return;
      }

      const idProduto = String(linha.getAttribute('data-id'));

      if (detalhesAbertos.has(idProduto)) {
        fecharDetalheEstoque(linha);
        return;
      }

      await abrirDetalheEstoque(linha);
    }

    async function inicializar() {
      await carregarProdutos();
      await carregarDepositos();
      await carregarEstoqueDeposito();
      renderizarTabela();
    }

    async function consultarGtin(idProduto, botao) {
      if (!idProduto) return;
      botao.disabled = true;
      const textoOriginal = botao.textContent;
      botao.textContent = 'Consultando...';
      limparAlerta();

      try {
        const resposta = await fetch('../api/produto-acao.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ acao: 'consultar_gtin', idProduto })
        });
        const json = await resposta.json();
        if (!json.ok) {
          throw new Error(json.erro || 'Falha ao consultar GTIN.');
        }

        const linha = tabelaProdutos.querySelector(`tr[data-id="${idProduto}"]`);
        if (linha) {
          const gtinSpan = linha.querySelector('.valor-gtin');
          if (gtinSpan) {
            gtinSpan.textContent = json.gtin || '—';
          }
        }

        const produto = produtos.find(p => String(p.id) === String(idProduto));
        if (produto) {
          produto.gtin = json.gtin || null;
        }

        const mensagem = json.mensagem || 'Consulta realizada com sucesso.';
        mostrarAlerta('success', mensagem);
      } catch (erro) {
        console.error('Erro ao consultar GTIN:', erro);
        mostrarAlerta('danger', erro.message || 'Não foi possível consultar o código de barras.');
      } finally {
        botao.disabled = false;
        botao.textContent = textoOriginal;
      }
    }

    async function excluirProduto(idProduto, botao) {
      if (!idProduto) return;
      if (!confirm('Deseja realmente remover este produto do banco local?')) {
        return;
      }

      botao.disabled = true;
      const textoOriginal = botao.textContent;
      botao.textContent = 'Excluindo...';
      limparAlerta();

      try {
        const resposta = await fetch('../api/produto-acao.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ acao: 'excluir_local', idProduto })
        });
        const json = await resposta.json();
        if (!json.ok) {
          throw new Error(json.erro || 'Falha ao excluir produto.');
        }

        produtos = produtos.filter(p => String(p.id) !== String(idProduto));
        delete estoqueAtual[idProduto];
        delete estoqueDetalhadoCache[String(idProduto)];
        detalhesAbertos.delete(String(idProduto));
        carregamentosPendentes.delete(String(idProduto));
        renderizarTabela();

        mostrarAlerta('success', json.mensagem || 'Produto removido com sucesso.');
      } catch (erro) {
        console.error('Erro ao excluir produto:', erro);
        mostrarAlerta('danger', erro.message || 'Não foi possível excluir o produto.');
      } finally {
        botao.disabled = false;
        botao.textContent = textoOriginal;
      }
    }

    selectDeposito.addEventListener('change', () => {
      carregarEstoqueDeposito();
    });

    function atualizarFiltroBusca(termo) {
      termoBuscaAtual = String(termo || '');
      renderizarTabela();
    }

    if (campoBusca) {
      campoBusca.addEventListener('input', (event) => {
        atualizarFiltroBusca(event.target.value);
      });

      campoBusca.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          atualizarFiltroBusca(event.target.value);
        }

        if (event.key === 'Escape' && campoBusca.value) {
          campoBusca.value = '';
          atualizarFiltroBusca('');
        }
      });
    }

    tabelaProdutos.addEventListener('click', (event) => {
      const botao = event.target.closest('button[data-action]');
      if (botao) {
        const idProduto = botao.getAttribute('data-id');
        const acao = botao.getAttribute('data-action');

        if (acao === 'consultar_gtin') {
          consultarGtin(idProduto, botao);
        } else if (acao === 'excluir') {
          excluirProduto(idProduto, botao);
        }
        return;
      }

      if (event.target.closest('[data-no-detalhe]')) {
        return;
      }

      const linha = event.target.closest('tr[data-id]');
      if (!linha) {
        return;
      }

      alternarDetalheEstoque(linha);
    });

    async function sincronizarProdutos() {
      if (!btnSincronizar) {
        return;
      }

      if (!confirm('Deseja iniciar a sincronização completa dos produtos?')) {
        return;
      }

      limparAlerta();

      const textoOriginal = btnSincronizar.innerHTML;
      btnSincronizar.disabled = true;
      btnSincronizar.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sincronizando...';

      try {
        const resposta = await fetch('../api/sincronizar-produtos.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          }
        });

        const json = await resposta.json().catch(() => null);

        if (!resposta.ok || !json || json.ok === false) {
          const mensagemErro = (json && (json.erro || json.mensagem)) || 'Falha ao sincronizar os produtos.';
          throw new Error(mensagemErro);
        }

        const detalhes = json.resumo || json.mensagem || 'Sincronização concluída.';
        mostrarAlerta('success', escapeHtml(detalhes));

        await carregarProdutos();
        await carregarEstoqueDeposito();
        limparCacheDetalhado();
        renderizarTabela();
      } catch (erro) {
        console.error('Erro ao sincronizar produtos:', erro);
        mostrarAlerta('danger', erro.message || 'Não foi possível concluir a sincronização.');
      } finally {
        btnSincronizar.disabled = false;
        btnSincronizar.innerHTML = textoOriginal;
      }
    }

    if (btnSincronizar) {
      btnSincronizar.addEventListener('click', sincronizarProdutos);
    }

    inicializar();
  </script>
</body>
</html>
