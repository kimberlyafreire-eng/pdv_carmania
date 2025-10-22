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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
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
      <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    window.ESTOQUE_PADRAO_ID = <?php echo json_encode($estoquePadraoId); ?>;
  </script>
  <script>
    const tabelaProdutos = document.getElementById('tabelaProdutos');
    const selectDeposito = document.getElementById('selectDeposito');
    const alertasEl = document.getElementById('alertas');
    const btnSincronizar = document.getElementById('btnSincronizarProdutos');

    let produtos = [];
    let estoqueAtual = {};
    let depositos = [];

    const formatarMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const formatarNumero = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });

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

    function renderizarTabela() {
      if (!produtos.length) {
        tabelaProdutos.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">Nenhum produto encontrado no banco local.</td></tr>';
        return;
      }

      const linhas = produtos.map(produto => {
        const estoque = estoqueAtual[produto.id] ?? 0;
        let estoqueBadgeClass = 'bg-secondary-subtle text-secondary';
        if (estoque > 0) {
          estoqueBadgeClass = 'bg-success-subtle text-success';
        } else if (estoque < 0) {
          estoqueBadgeClass = 'bg-danger-subtle text-danger';
        }
        const gtin = produto.gtin ? escapeHtml(produto.gtin) : '—';

        return `
          <tr data-id="${produto.id}">
            <td class="text-center">
              <img src="${escapeHtml(produto.imagemURL || '')}" alt="Imagem do produto" class="produto-imagem" onerror="this.src='../imagens/sem-imagem.png'" />
            </td>
            <td class="celula-nome">
              <div class="fw-semibold">${escapeHtml(produto.nome)}</div>
              <div class="text-muted small">Código: ${escapeHtml(produto.codigo || '—')}</div>
              <div class="text-muted small" data-gtin="${produto.id}">GTIN: <span class="valor-gtin">${gtin}</span></div>
            </td>
            <td class="text-center">
              <span class="badge rounded-pill badge-estoque ${estoqueBadgeClass}">${formatarNumero.format(estoque)}</span>
            </td>
            <td class="text-end">${formatarMoeda.format(produto.preco || 0)}</td>
            <td class="text-end">
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
        produtos = json.data;
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

    tabelaProdutos.addEventListener('click', (event) => {
      const botao = event.target.closest('button[data-action]');
      if (!botao) return;
      const idProduto = botao.getAttribute('data-id');
      const acao = botao.getAttribute('data-action');

      if (acao === 'consultar_gtin') {
        consultarGtin(idProduto, botao);
      } else if (acao === 'excluir') {
        excluirProduto(idProduto, botao);
      }
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
