<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PDV Carmania</title>
  <link href="../assets/cdn-cache.php?asset=bootstrap-css" rel="stylesheet" />
  <style>
    body {
      background-color: #f8f9fa;
    }
    .product-card {
      transition: transform 0.2s ease;
      cursor: pointer;
      position: relative;
    }
    .product-card:hover {
      transform: scale(1.03);
    }
    .card-img-top {
      object-fit: contain;
      height: 100px;
    }
    .product-name {
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
      white-space: normal;
      line-height: 1.2;
      min-height: calc(1.2em * 3);
      word-break: break-word;
    }
    .badge-carrinho {
      position: absolute;
      top: 5px;
      right: 5px;
      background-color: red;
      color: white;
      font-size: 0.8rem;
    }
    .cart-btn {
      position: fixed;
      bottom: 80px;
      right: 20px;
      z-index: 1000;
    }
    .cart-total-mobile {
      position: fixed;
      bottom: 20px;
      right: 20px;
      left: 20px;
      background: white;
      padding: 8px 12px;
      border-radius: 8px;
      box-shadow: 0 0 5px rgba(0,0,0,0.2);
      font-weight: bold;
      z-index: 1000;
    }
    /* Caixa do carrinho fixa no desktop */
    .sticky-summary {
      position: sticky;
      top: 80px;
    }
  </style>
</head>
<body>
  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-danger">
    <div class="container-fluid d-flex justify-content-between">
      <!-- Bot00o menu hamburguer -->
      <button class="btn btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">
        MENU
      </button>

      <!-- Bot00o sair -->
      <a href="logout.php" class="btn btn-outline-light">Sair</a>
    </div>
  </nav>

  <!-- Menu lateral (offcanvas) -->
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
        <li><a class="btn btn-outline-danger w-100" href="produtos.php">Produtos</a></li>
      </ul>
    </div>
  </div>

  <div class="container-fluid py-4">
    <h2 class="mb-4 text-center">PDV Carmania - Produtos</h2>

    <!-- Campo de Busca -->
    <div class="row mb-3">
      <div class="col-12 col-lg-6 mx-auto">
        <input type="text" id="campoBusca" class="form-control" placeholder="Buscar produto por nome, código, código de barras ou GTIN..." />
      </div>
    </div>

    <div class="row">
      <!-- Grid de produtos -->
      <div class="col-lg-9">
        <div class="row" id="product-grid"></div>
      </div>
      <!-- Resumo do carrinho no desktop -->
      <div class="col-lg-3 d-none d-lg-block">
        <div class="bg-white p-3 rounded shadow sticky-summary">
          <h5>Resumo do Carrinho</h5>
          <div id="resumoCarrinho" class="mt-3"></div>
          <div class="mt-3 fw-bold">Total: <span id="totalResumo">R$ 0,00</span></div>
          <a href="carrinho.php" class="btn btn-success w-100 mt-3 py-3">Ir para o Carrinho</a>
          <button class="btn btn-danger w-100 mt-2 py-2" onclick="limparCarrinho()">Limpar Carrinho</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bot01es mveis -->
  <a href="carrinho.php" class="btn btn-success cart-btn d-lg-none py-3 w-100 text-center">
    Carrinho (<span id="cart-count">0</span>)
  </a>
  <div class="cart-total-mobile d-lg-none text-center">
    <div>Total: <span id="totalMobile">R$ 0,00</span></div>
    <button class="btn btn-danger btn-sm mt-2" onclick="limparCarrinho()">Limpar Carrinho</button>
  </div>

  <script src="../assets/cdn-cache.php?asset=bootstrap-js"></script>
  <script>
  let carrinho = JSON.parse(localStorage.getItem('carrinho')) || [];
  let todosProdutos = [];

  const removerAcentos = (texto) => typeof texto === 'string'
    ? texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    : '';

  const normalizarTexto = (texto) => removerAcentos(String(texto || '')).toLowerCase();

  function adicionarAoCarrinho(produto) {
    const existente = carrinho.find(p => p.id === produto.id);
    if (existente) {
      existente.quantidade += 1;
    } else {
      carrinho.push({ ...produto, quantidade: 1 });
    }
    localStorage.setItem('carrinho', JSON.stringify(carrinho));
    atualizarContadorCarrinho();
    destacarNoCard(produto.id);

    // atualizar nmero entre os bot01es
    const qtdSpan = document.getElementById('qtd-' + produto.id);
    if (qtdSpan) {
      const item = carrinho.find(p => p.id === produto.id);
      qtdSpan.textContent = item ? item.quantidade : 0;
    }
  }

  function removerDoCarrinho(produtoId) {
    const index = carrinho.findIndex(p => p.id === produtoId);
    if (index !== -1) {
      carrinho[index].quantidade -= 1;
      if (carrinho[index].quantidade <= 0) {
        carrinho.splice(index, 1);
      }
      localStorage.setItem('carrinho', JSON.stringify(carrinho));
      atualizarContadorCarrinho();
      destacarNoCard(produtoId);

      // atualizar nmero entre os bot01es
      const qtdSpan = document.getElementById('qtd-' + produtoId);
      if (qtdSpan) {
        const item = carrinho.find(p => p.id === produtoId);
        qtdSpan.textContent = item ? item.quantidade : 0;
      }
    }
  }

  function destacarNoCard(idProduto) {
    const badge = document.getElementById('badge-' + idProduto);
    const item = carrinho.find(p => p.id === idProduto);
    if (badge) {
      badge.textContent = item?.quantidade || '';
      badge.style.display = item ? 'inline-block' : 'none';
    }
  }

  function atualizarContadorCarrinho() {
    const totalQtd = carrinho.reduce((soma, p) => soma + p.quantidade, 0);
    const totalValor = carrinho.reduce((soma, p) => soma + (p.quantidade * p.preco), 0);

    document.getElementById('cart-count').textContent = totalQtd;
    document.getElementById('totalResumo').textContent = `R$ ${totalValor.toFixed(2)}`;
    document.getElementById('totalMobile').textContent = `R$ ${totalValor.toFixed(2)}`;

    const resumo = document.getElementById('resumoCarrinho');
    resumo.innerHTML = '';

    carrinho.forEach(p => {
      const linha = document.createElement('div');
      linha.className = 'd-flex justify-content-between small mb-1';
      linha.innerHTML = `<span>${p.nome}</span><span>x${p.quantidade}</span>`;
      resumo.appendChild(linha);
    });
  }

  function limparCarrinho() {
    if (confirm("Tem certeza que deseja limpar todo o carrinho?")) {
      carrinho = [];
      localStorage.setItem('carrinho', JSON.stringify(carrinho));
      atualizarContadorCarrinho();
      filtrarProdutos(document.getElementById('campoBusca').value);
    }
  }

  function criarCardProduto(produto) {
    const col = document.createElement('div');
    col.className = 'col-4 col-lg-2 mb-4';

    const card = document.createElement('div');
    card.className = 'card product-card shadow-sm';
    card.addEventListener('click', () => {
      adicionarAoCarrinho(produto);
    });

    const img = document.createElement('img');
    img.className = 'card-img-top';
    img.alt = produto.nome;

    const placeholder = '/pdv_carmania/imagens/sem-imagem.png';
    let url = produto.imagemURL;
    if (!url || typeof url !== 'string' || url.trim() === '' || url.trim().toLowerCase() === 'null') {
      url = placeholder;
    }
    img.src = url;
    img.onerror = () => {
      img.onerror = null;
      img.src = placeholder;
    };

    const badge = document.createElement('span');
    badge.className = 'badge rounded-pill badge-carrinho';
    badge.id = 'badge-' + produto.id;
    badge.style.display = 'none';

    const body = document.createElement('div');
    body.className = 'card-body p-2';

    const name = document.createElement('h6');
    name.className = 'card-title product-name mb-1';
    name.textContent = produto.nome;

    const price = document.createElement('p');
    price.className = 'card-text text-success fw-bold';
    price.textContent = `R$ ${produto.preco.toFixed(2)}`;

    const controls = document.createElement('div');
    controls.className = 'd-flex justify-content-between align-items-center mt-2';

    const btnMenos = document.createElement('button');
    btnMenos.className = 'btn btn-outline-danger btn-sm';
    btnMenos.textContent = '-';
    btnMenos.onclick = (e) => {
      e.stopPropagation();
      removerDoCarrinho(produto.id);
    };

    const qtd = document.createElement('span');
    qtd.id = 'qtd-' + produto.id;
    qtd.textContent = carrinho.find(p => p.id === produto.id)?.quantidade || 0;

    const btnMais = document.createElement('button');
    btnMais.className = 'btn btn-outline-success btn-sm';
    btnMais.textContent = '+';
    btnMais.onclick = (e) => {
      e.stopPropagation();
      adicionarAoCarrinho(produto);
    };

    controls.appendChild(btnMenos);
    controls.appendChild(qtd);
    controls.appendChild(btnMais);

    body.appendChild(name);
    body.appendChild(price);
    body.appendChild(controls);

    card.appendChild(img);
    card.appendChild(badge);
    card.appendChild(body);
    col.appendChild(card);

    const noCarrinho = carrinho.find(p => p.id === produto.id);
    if (noCarrinho) {
      badge.textContent = noCarrinho.quantidade;
      badge.style.display = 'inline-block';
    }

    return col;
  }

  function obterTermosBusca(termo) {
    return normalizarTexto(termo).split(/\s+/).filter(Boolean);
  }

  function filtrarProdutos(termo) {
    const grid = document.getElementById('product-grid');
    const termoNormalizado = normalizarTexto(termo);
    const termosNome = obterTermosBusca(termo);
    const termoLower = String(termo || '').toLowerCase();
    const termoLowerCompacto = termoLower.replace(/\s+/g, '');

    const filtrados = todosProdutos.filter(p => {
      if (!termoNormalizado) {
        return true;
      }

      const nomeNormalizado = normalizarTexto(p.nome);
      const codigo = String(p.codigo || '').toLowerCase();
      const gtin = String(p.gtin || '').toLowerCase();
      const codigoCompacto = codigo.replace(/\s+/g, '');
      const gtinCompacto = gtin.replace(/\s+/g, '');

      const correspondeNome = termosNome.every((parte) => nomeNormalizado.includes(parte));
      const correspondeCodigo = codigo.includes(termoLower) || codigoCompacto.includes(termoLowerCompacto);
      const correspondeGtin = gtin.includes(termoLower) || gtinCompacto.includes(termoLowerCompacto);

      return correspondeNome || correspondeCodigo || correspondeGtin;
    });

    grid.innerHTML = '';
    filtrados.forEach(produto => {
      const col = criarCardProduto(produto);
      grid.appendChild(col);
    });
  }

  const campoBusca = document.getElementById('campoBusca');

  function normalizarCodigoBarras(valor) {
    const texto = String(valor ?? '').trim().toLowerCase();
    return {
      completo: texto,
      compacto: texto.replace(/\s+/g, '')
    };
  }

  function buscarPorCodigoBarras(termo) {
    const { completo, compacto } = normalizarCodigoBarras(termo);
    if (!compacto) {
      return [];
    }

    return todosProdutos.filter((produto) => {
      const codigo = normalizarCodigoBarras(produto.codigo);
      const gtin = normalizarCodigoBarras(produto.gtin);

      const correspondeCodigo = codigo.completo === completo || codigo.compacto === compacto;
      const correspondeGtin = gtin.completo === completo || gtin.compacto === compacto;

      return correspondeCodigo || correspondeGtin;
    });
  }

  function tentarAdicionarPorCodigoBarras(termo) {
    const correspondencias = buscarPorCodigoBarras(termo);
    if (correspondencias.length === 1) {
      adicionarAoCarrinho(correspondencias[0]);
      campoBusca.value = '';
      filtrarProdutos('');
      return true;
    }
    return false;
  }

  campoBusca.addEventListener('input', e => {
    const termo = e.target.value;
    filtrarProdutos(termo);
  });

  campoBusca.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      const termo = campoBusca.value;
      if (!tentarAdicionarPorCodigoBarras(termo)) {
        filtrarProdutos(termo);
      }
    }
  });

  async function carregarProdutos() {
    try {
      const resposta = await fetch(`../api/produtos-json.php`);
      const dados = await resposta.json();
      todosProdutos = (dados.data || []).map((produto) => ({
        ...produto,
        nome: (produto && produto.nome) ? produto.nome : '',
        codigo: (produto && produto.codigo) ? produto.codigo : '',
        gtin: (produto && produto.gtin) ? produto.gtin : ''
      }));
      filtrarProdutos(document.getElementById('campoBusca').value);
    } catch (erro) {
      console.error('Erro ao carregar os produtos:', erro);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    carregarProdutos();
    atualizarContadorCarrinho();
  });
</script>
</body>
</html>
