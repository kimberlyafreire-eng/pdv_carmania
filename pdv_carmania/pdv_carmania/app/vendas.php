<?php
require_once __DIR__ . '/../session.php';
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Vendas - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background-color: #f8f9fa;
      min-height: 100vh;
    }
    .resumo-card {
      background: #ffffff;
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .resumo-card .label {
      font-size: 0.85rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: #6c757d;
    }
    .resumo-card .valor {
      font-size: clamp(1.8rem, 4vw, 2.5rem);
      font-weight: 700;
      color: #212529;
    }
    .resumo-card-dia {
      border: 2px solid rgba(220, 53, 69, 0.2);
      background: rgba(220, 53, 69, 0.05);
    }
    .filtros-card {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
      padding: 1.5rem;
    }
    .table-vendas tbody tr {
      cursor: pointer;
      transition: background-color 0.15s ease;
    }
    .table-vendas tbody tr:hover {
      background: rgba(220, 53, 69, 0.08);
    }
    .badge-situacao {
      font-size: 0.75rem;
      padding: 0.25rem 0.6rem;
      border-radius: 999px;
    }
    .loader {
      display: none;
      align-items: center;
      gap: 0.5rem;
      color: #6c757d;
      font-size: 0.95rem;
    }
    .loader.show {
      display: inline-flex;
    }
    .loader .spinner-border {
      width: 1.25rem;
      height: 1.25rem;
      border-width: 0.15em;
    }
    #detalheVendaSection {
      display: none;
    }
    .detalhe-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
    }
    .detalhe-item {
      background: #f1f3f5;
      border-radius: 12px;
      padding: 1rem;
    }
    .detalhe-item h6 {
      font-size: 0.9rem;
      text-transform: uppercase;
      color: #6c757d;
      margin-bottom: 0.35rem;
      letter-spacing: 0.05em;
    }
    .detalhe-item p {
      margin: 0;
      font-weight: 600;
      color: #212529;
      word-break: break-word;
    }
    #reciboContainer {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      background: rgba(0, 0, 0, 0.55);
      z-index: 2000;
    }
    #reciboContainer.ativo {
      display: flex;
    }
    #reciboContainer .recibo-wrapper {
      max-width: 640px;
      width: 100%;
      max-height: 90vh;
      overflow: auto;
      background: #ffffff;
      border-radius: 14px;
      padding: 1.5rem 1.25rem 1.75rem;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    }
    #reciboContainer .recibo-acoes {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      justify-content: center;
      margin-top: 1rem;
    }
    #reciboContainer .recibo-acoes .btn {
      min-width: 140px;
    }
    @media (max-width: 576px) {
      .resumo-card {
        padding: 1.25rem;
      }
      .filtros-card {
        padding: 1.25rem;
      }
      .table-responsive {
        border-radius: 12px;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-danger">
    <div class="container-fluid d-flex justify-content-between align-items-center">
      <button class="btn btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">MENU</button>
      <span class="navbar-brand mb-0 h1 text-white">Vendas</span>
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
        <li><a class="btn btn-danger w-100 mb-2" href="vendas.php">Vendas</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="caixa.php">Caixa</a></li>
        <li><a class="btn btn-outline-danger w-100" href="produtos.php">Produtos</a></li>
      </ul>
    </div>
  </div>

  <div class="container-fluid py-4 px-3 px-md-4 px-lg-5">
    <div class="d-flex flex-column flex-lg-row gap-3 mb-4">
      <div class="resumo-card resumo-card-dia flex-fill">
        <span class="label">Total das vendas filtradas</span>
        <span class="valor" id="totalDia">R$ 0,00</span>
      </div>
    </div>

    <div class="filtros-card mb-4">
      <form id="formFiltros" class="row g-3 align-items-end">
        <div class="col-12 col-md-4 col-lg-3">
          <label for="dataInicio" class="form-label">Data inicial</label>
          <input type="date" id="dataInicio" name="dataInicio" class="form-control" required />
        </div>
        <div class="col-12 col-md-4 col-lg-3">
          <label for="dataFim" class="form-label">Data final</label>
          <input type="date" id="dataFim" name="dataFim" class="form-control" required />
        </div>
        <div class="col-12 col-md-4 col-lg-3">
          <label for="formaPagamento" class="form-label">Forma de pagamento</label>
          <select id="formaPagamento" name="formaPagamento" class="form-select">
            <option value="">Todas as formas</option>
          </select>
        </div>
        <div class="col-12 col-md-4 col-lg-3">
          <label for="vendedor" class="form-label">Vendedor</label>
          <select id="vendedor" name="vendedor" class="form-select">
            <option value="">Todos os vendedores</option>
          </select>
        </div>
        <div class="col-12 col-lg-3 col-xl-2">
          <button class="btn btn-danger w-100" type="submit">Filtrar</button>
        </div>
      </form>
      <div class="loader mt-3" id="loaderVendas">
        <div class="spinner-border text-danger" role="status"></div>
        <span>Carregando vendas...</span>
      </div>
      <div class="mt-3" id="mensagemVendas"></div>
    </div>

    <div id="listaVendasSection">
      <div class="table-responsive bg-white rounded-4 shadow-sm">
        <table class="table table-hover align-middle mb-0 table-vendas">
          <thead class="table-light">
            <tr>
              <th style="min-width: 150px;">Data e hora</th>
              <th>ID</th>
              <th>Cliente</th>
              <th>Valor total</th>
              <th>Desconto</th>
              <th>Formas de pagamento</th>
              <th>Usuário</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody id="tabelaVendasCorpo"></tbody>
        </table>
      </div>
      <div class="text-center text-muted mt-4" id="semResultados" style="display: none;">
        Nenhuma venda encontrada para o período informado.
      </div>
    </div>

    <div id="detalheVendaSection" class="mb-5">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0">Detalhes da venda <span id="detalheVendaId"></span></h5>
            <small class="text-muted" id="detalheSituacao"></small>
          </div>
          <button class="btn btn-outline-secondary" id="btnVoltarLista">Voltar</button>
        </div>
        <div class="card-body">
          <div class="detalhe-grid mb-4">
            <div class="detalhe-item">
              <h6>Data e hora</h6>
              <p id="detalheDataHora">-</p>
            </div>
            <div class="detalhe-item">
              <h6>Cliente</h6>
              <p id="detalheCliente">-</p>
            </div>
            <div class="detalhe-item">
              <h6>Usuário responsável</h6>
              <p id="detalheUsuario">-</p>
            </div>
            <div class="detalhe-item">
              <h6>Depósito</h6>
              <p id="detalheDeposito">-</p>
            </div>
            <div class="detalhe-item">
              <h6>Total da venda</h6>
              <p id="detalheTotal">R$ 0,00</p>
            </div>
            <div class="detalhe-item">
              <h6>Desconto</h6>
              <p id="detalheDesconto">R$ 0,00</p>
            </div>
          </div>

          <h6 class="fw-semibold text-danger mb-2">Formas de pagamento</h6>
          <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width: 60%;">Forma</th>
                  <th>Valor</th>
                </tr>
              </thead>
              <tbody id="detalhePagamentos"></tbody>
            </table>
          </div>

          <h6 class="fw-semibold text-danger mb-2">Itens da venda</h6>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Produto</th>
                  <th class="text-center" style="width: 120px;">Quantidade</th>
                  <th class="text-end" style="width: 140px;">Valor unitário</th>
                  <th class="text-end" style="width: 140px;">Subtotal</th>
                </tr>
              </thead>
              <tbody id="detalheItens"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="reciboContainer"></div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script>
    const tabelaCorpo = document.getElementById('tabelaVendasCorpo');
    const semResultados = document.getElementById('semResultados');
    const loader = document.getElementById('loaderVendas');
    const mensagemVendas = document.getElementById('mensagemVendas');
    const formFiltros = document.getElementById('formFiltros');
    const formaPagamentoSelect = document.getElementById('formaPagamento');
    const vendedorSelect = document.getElementById('vendedor');
    const listaSection = document.getElementById('listaVendasSection');
    const detalheSection = document.getElementById('detalheVendaSection');
    const btnVoltarLista = document.getElementById('btnVoltarLista');

    const detalheVendaId = document.getElementById('detalheVendaId');
    const detalheSituacao = document.getElementById('detalheSituacao');
    const detalheDataHora = document.getElementById('detalheDataHora');
    const detalheCliente = document.getElementById('detalheCliente');
    const detalheUsuario = document.getElementById('detalheUsuario');
    const detalheDeposito = document.getElementById('detalheDeposito');
    const detalheTotal = document.getElementById('detalheTotal');
    const detalheDesconto = document.getElementById('detalheDesconto');
    const detalhePagamentos = document.getElementById('detalhePagamentos');
    const detalheItens = document.getElementById('detalheItens');
    const reciboContainer = document.getElementById('reciboContainer');

    const totalDiaEl = document.getElementById('totalDia');

    const formatoMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    function formatarDataHora(texto) {
      if (!texto) return '-';
      const partes = texto.split(' ');
      if (partes.length !== 2) return texto;
      const [data, hora] = partes;
      const [ano, mes, dia] = data.split('-');
      return `${dia}/${mes}/${ano} ${hora}`;
    }

    function exibirMensagem(tipo, mensagem) {
      mensagemVendas.innerHTML = `<div class="alert alert-${tipo} mb-0">${mensagem}</div>`;
    }

    function limparMensagem() {
      mensagemVendas.innerHTML = '';
    }

    function popularFormasPagamento(formas) {
      const selecionada = formaPagamentoSelect.value;
      const opcoesExistentes = new Set();
      Array.from(formaPagamentoSelect.options).forEach(opt => {
        if (opt.value !== '') {
          opcoesExistentes.add(opt.value);
        }
      });
      formas.forEach(forma => {
        const valor = String(forma.id);
        if (!opcoesExistentes.has(valor)) {
          const option = document.createElement('option');
          option.value = valor;
          option.textContent = forma.nome;
          formaPagamentoSelect.appendChild(option);
        }
      });
      formaPagamentoSelect.value = selecionada;
    }

    function obterValorOpcaoVendedor(vendedor) {
      if (!vendedor || typeof vendedor !== 'object') {
        return '';
      }
      const id = Number(vendedor.id);
      if (Number.isFinite(id)) {
        return `id:${id}`;
      }
      const login = typeof vendedor.login === 'string' ? vendedor.login.trim() : '';
      if (login) {
        return `login:${encodeURIComponent(login)}`;
      }
      const nome = typeof vendedor.nome === 'string' ? vendedor.nome.trim() : '';
      if (nome) {
        return `nome:${encodeURIComponent(nome)}`;
      }
      return '';
    }

    function gerarRotuloVendedor(vendedor) {
      if (!vendedor || typeof vendedor !== 'object') {
        return '';
      }
      const nome = typeof vendedor.nome === 'string' ? vendedor.nome.trim() : '';
      const login = typeof vendedor.login === 'string' ? vendedor.login.trim() : '';
      if (nome && login && nome.toLowerCase() !== login.toLowerCase()) {
        return `${nome} (${login})`;
      }
      return nome || login || '';
    }

    function popularVendedores(vendedores) {
      if (!vendedorSelect) {
        return;
      }
      const selecionado = vendedorSelect.value;
      while (vendedorSelect.options.length > 1) {
        vendedorSelect.remove(1);
      }
      const valoresAdicionados = new Set();
      (Array.isArray(vendedores) ? vendedores : []).forEach(vendedor => {
        const valor = obterValorOpcaoVendedor(vendedor);
        const rotulo = gerarRotuloVendedor(vendedor);
        if (!valor || !rotulo || valoresAdicionados.has(valor)) {
          return;
        }
        const option = document.createElement('option');
        option.value = valor;
        option.textContent = rotulo;
        vendedorSelect.appendChild(option);
        valoresAdicionados.add(valor);
      });
      if (selecionado) {
        vendedorSelect.value = valoresAdicionados.has(selecionado) ? selecionado : '';
      }
    }

    function renderizarVendas(vendas) {
      tabelaCorpo.innerHTML = '';
      if (!Array.isArray(vendas) || vendas.length === 0) {
        semResultados.style.display = 'block';
        return;
      }
      semResultados.style.display = 'none';

      vendas.forEach(venda => {
        const tr = document.createElement('tr');
        tr.dataset.vendaId = venda.id;

        const pagamentosTexto = (venda.pagamentos || []).map(p => {
          const nome = p.nome || 'Forma';
          const valor = formatoMoeda.format(p.valor || 0);
          return `${nome} (${valor})`;
        }).join(' + ');

        tr.innerHTML = `
          <td>${formatarDataHora(venda.data_hora)}</td>
          <td>${venda.id}</td>
          <td>${venda.contato_nome || '-'}</td>
          <td>${formatoMoeda.format(venda.valor_total || 0)}</td>
          <td>${formatoMoeda.format(venda.valor_desconto || 0)}</td>
          <td>${pagamentosTexto || '-'}</td>
          <td>${venda.usuario_nome || venda.usuario_login || '-'}</td>
          <td class="text-end">
            <div class="d-flex gap-2 justify-content-end flex-wrap">
              <button type="button" class="btn btn-outline-secondary btn-sm recibo-venda-btn" title="Gerar recibo da venda">
                Gerar recibo
              </button>
              <button type="button" class="btn btn-outline-danger btn-sm cancelar-venda-btn" title="Cancelar venda">
                Cancelar venda
              </button>
            </div>
          </td>
        `;

        tr.addEventListener('click', (event) => {
          if (event.target.closest('.recibo-venda-btn')) {
            event.stopPropagation();
            return;
          }
          if (event.target.closest('.cancelar-venda-btn')) {
            event.stopPropagation();
            return;
          }
          abrirDetalheVenda(venda.id);
        });

        const btnRecibo = tr.querySelector('.recibo-venda-btn');
        if (btnRecibo) {
          btnRecibo.addEventListener('click', async (event) => {
            event.stopPropagation();
            await gerarReciboVenda(venda.id, btnRecibo);
          });
        }

        const btnCancelar = tr.querySelector('.cancelar-venda-btn');
        if (btnCancelar) {
          btnCancelar.addEventListener('click', (event) => {
            event.stopPropagation();
            exibirMensagem('warning', 'Ação de cancelar venda ainda não implementada.');
            setTimeout(limparMensagem, 3000);
          });
        }

        tabelaCorpo.appendChild(tr);
      });
    }

    async function carregarVendas() {
      limparMensagem();
      loader.classList.add('show');
      tabelaCorpo.innerHTML = '';
      semResultados.style.display = 'none';

      const dataInicio = document.getElementById('dataInicio').value;
      const dataFim = document.getElementById('dataFim').value;
      const formaPagamento = formaPagamentoSelect.value;
      const vendedorSelecionado = vendedorSelect ? vendedorSelect.value : '';

      if (dataInicio && dataFim && dataInicio > dataFim) {
        document.getElementById('dataInicio').value = dataFim;
        document.getElementById('dataFim').value = dataInicio;
      }

      const params = new URLSearchParams({
        dataInicio: document.getElementById('dataInicio').value,
        dataFim: document.getElementById('dataFim').value,
      });
      if (formaPagamento) {
        params.append('formaPagamentoId', formaPagamento);
      }
      if (vendedorSelecionado) {
        params.append('vendedor', vendedorSelecionado);
      }

      try {
        const resposta = await fetch(`../api/vendas-listar.php?${params.toString()}&nocache=${Date.now()}`);
        if (!resposta.ok) {
          throw new Error(`Falha ao carregar vendas (HTTP ${resposta.status})`);
        }
        const json = await resposta.json();
        if (!json.ok) {
          throw new Error(json.erro || 'Erro desconhecido ao carregar vendas');
        }

        totalDiaEl.textContent = formatoMoeda.format(json.resumo?.totalDia || 0);
        renderizarVendas(json.vendas || []);
        if (Array.isArray(json.formasPagamento)) {
          popularFormasPagamento(json.formasPagamento);
        }
        if (Array.isArray(json.usuarios)) {
          popularVendedores(json.usuarios);
        }
      } catch (erro) {
        console.error(erro);
        exibirMensagem('danger', erro.message || 'Não foi possível carregar as vendas.');
        semResultados.style.display = 'block';
      } finally {
        loader.classList.remove('show');
      }
    }

    async function abrirDetalheVenda(id) {
      detalhePagamentos.innerHTML = '';
      detalheItens.innerHTML = '';
      detalheVendaId.textContent = `#${id}`;
      detalheSituacao.textContent = 'Carregando detalhes...';
      detalheDataHora.textContent = '-';
      detalheCliente.textContent = '-';
      detalheUsuario.textContent = '-';
      detalheDeposito.textContent = '-';
      detalheTotal.textContent = formatoMoeda.format(0);
      detalheDesconto.textContent = formatoMoeda.format(0);

      listaSection.style.display = 'none';
      detalheSection.style.display = 'block';

      try {
        const resposta = await fetch(`../api/venda-detalhes.php?id=${id}&nocache=${Date.now()}`);
        if (!resposta.ok) {
          throw new Error(`Falha ao carregar detalhes (HTTP ${resposta.status})`);
        }
        const json = await resposta.json();
        if (!json.ok) {
          throw new Error(json.erro || 'Erro ao carregar detalhes da venda');
        }

        const venda = json.venda || {};
        detalheSituacao.textContent = venda.situacao_nome ? `Situação: ${venda.situacao_nome}` : 'Situação não informada';
        detalheDataHora.textContent = formatarDataHora(venda.data_hora || '');
        detalheCliente.textContent = venda.contato_nome || '-';
        detalheUsuario.textContent = venda.usuario_nome || venda.usuario_login || '-';
        detalheDeposito.textContent = venda.deposito_nome || (venda.deposito_id ? `Depósito #${venda.deposito_id}` : '-');
        detalheTotal.textContent = formatoMoeda.format(venda.valor_total || 0);
        detalheDesconto.textContent = formatoMoeda.format(venda.valor_desconto || 0);

        const pagamentos = json.pagamentos || [];
        if (pagamentos.length === 0) {
          detalhePagamentos.innerHTML = '<tr><td colspan="2" class="text-center text-muted">Nenhuma forma de pagamento registrada.</td></tr>';
        } else {
          pagamentos.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${p.nome || 'Forma'}</td><td class="text-end">${formatoMoeda.format(p.valor || 0)}</td>`;
            detalhePagamentos.appendChild(tr);
          });
        }

        const itens = json.itens || [];
        if (itens.length === 0) {
          detalheItens.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Nenhum item registrado.</td></tr>';
        } else {
          itens.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${item.nome || 'Produto'}</td>
              <td class="text-center">${item.quantidade}</td>
              <td class="text-end">${formatoMoeda.format(item.valor_unitario || 0)}</td>
              <td class="text-end">${formatoMoeda.format(item.subtotal || 0)}</td>
            `;
            detalheItens.appendChild(tr);
          });
        }
      } catch (erro) {
        console.error(erro);
        exibirMensagem('danger', erro.message || 'Não foi possível carregar os detalhes da venda.');
        voltarParaLista();
      }
    }

    function voltarParaLista() {
      detalheSection.style.display = 'none';
      listaSection.style.display = 'block';
    }

    btnVoltarLista.addEventListener('click', () => {
      voltarParaLista();
    });

    reciboContainer.addEventListener('click', (event) => {
      if (event.target === reciboContainer) {
        fecharRecibo();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && reciboContainer.classList.contains('ativo')) {
        fecharRecibo();
      }
    });

    formFiltros.addEventListener('submit', (event) => {
      event.preventDefault();
      carregarVendas();
    });

    async function gerarReciboVenda(id, botao) {
      if (!id) return;
      limparMensagem();
      let labelOriginal = '';
      if (botao) {
        labelOriginal = botao.innerHTML;
        botao.disabled = true;
        botao.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Gerando...';
      }
      try {
        const resposta = await fetch(`../api/venda-recibo.php?id=${encodeURIComponent(id)}&nocache=${Date.now()}`);
        if (!resposta.ok) {
          throw new Error(`Falha ao gerar recibo (HTTP ${resposta.status})`);
        }
        const json = await resposta.json();
        if (!json.ok || !json.reciboHtml) {
          throw new Error(json.erro || 'Não foi possível gerar o recibo.');
        }
        exibirRecibo(json.reciboHtml);
      } catch (erro) {
        console.error(erro);
        exibirMensagem('danger', erro.message || 'Falha ao gerar o recibo.');
        setTimeout(limparMensagem, 4000);
      } finally {
        if (botao) {
          botao.disabled = false;
          botao.innerHTML = labelOriginal || 'Gerar recibo';
        }
      }
    }

    function exibirRecibo(html) {
      reciboContainer.innerHTML = `
        <div class="recibo-wrapper">
          <div class="recibo-area"><div id="reciboVendaHtml">${html}</div></div>
          <div class="recibo-acoes">
            <button type="button" class="btn btn-primary" id="btnImprimirRecibo">🖨 Imprimir</button>
            <button type="button" class="btn btn-secondary" id="btnCopiarRecibo">📋 Copiar</button>
            <button type="button" class="btn btn-success" id="btnCompartilharRecibo">📤 Compartilhar</button>
            <button type="button" class="btn btn-outline-dark" id="btnFecharRecibo">Fechar</button>
          </div>
        </div>`;
      reciboContainer.dataset.htmlRecibo = html;
      reciboContainer.classList.add('ativo');
      gerarImagemReciboAtual();
      document.getElementById('btnImprimirRecibo')?.addEventListener('click', imprimirReciboAtual);
      document.getElementById('btnCopiarRecibo')?.addEventListener('click', copiarReciboAtual);
      document.getElementById('btnCompartilharRecibo')?.addEventListener('click', compartilharReciboAtual);
      document.getElementById('btnFecharRecibo')?.addEventListener('click', fecharRecibo);
    }

    function fecharRecibo() {
      reciboContainer.classList.remove('ativo');
      reciboContainer.innerHTML = '';
      delete reciboContainer.dataset.htmlRecibo;
    }

    async function gerarImagemReciboAtual() {
      const reciboHtmlEl = document.getElementById('reciboVendaHtml');
      if (!reciboHtmlEl || typeof html2canvas !== 'function') {
        return;
      }
      let wrapperClonado = null;
      try {
        const clone = reciboHtmlEl.cloneNode(true);
        clone.style.maxWidth = 'none';
        clone.style.boxSizing = 'border-box';

        wrapperClonado = document.createElement('div');
        wrapperClonado.style.position = 'fixed';
        wrapperClonado.style.left = '-9999px';
        wrapperClonado.style.top = '0';
        wrapperClonado.style.pointerEvents = 'none';
        wrapperClonado.style.opacity = '0';
        wrapperClonado.style.background = '#ffffff';
        wrapperClonado.appendChild(clone);
        document.body.appendChild(wrapperClonado);

        const agendarMedicao = typeof requestAnimationFrame === 'function'
          ? (callback) => requestAnimationFrame(callback)
          : (callback) => setTimeout(callback, 0);
        await new Promise(resolve => agendarMedicao(resolve));

        const bounds = clone.getBoundingClientRect();
        const largura = Math.max(bounds.width, clone.scrollWidth, clone.offsetWidth);
        const altura = Math.max(bounds.height, clone.scrollHeight, clone.offsetHeight);
        const escala = Math.max(window.devicePixelRatio || 1, 2);

        const canvas = await html2canvas(clone, {
          backgroundColor: '#ffffff',
          scale: escala,
          width: Math.max(1, Math.round(largura)),
          height: Math.max(1, Math.round(altura)),
          windowWidth: Math.max(1, Math.round(largura)),
          windowHeight: Math.max(1, Math.round(altura)),
          scrollX: 0,
          scrollY: 0,
          useCORS: true,
        });
        const img = document.createElement('img');
        img.id = 'reciboVendaImg';
        img.src = canvas.toDataURL('image/png');
        img.alt = 'Recibo PDV Carmania';
        img.classList.add('img-fluid');
        img.dataset.htmlRecibo = reciboContainer.dataset.htmlRecibo || '';
        reciboHtmlEl.replaceWith(img);
      } catch (erro) {
        console.error('Falha ao gerar imagem do recibo', erro);
      } finally {
        if (wrapperClonado?.parentNode) {
          wrapperClonado.parentNode.removeChild(wrapperClonado);
        }
      }
    }

    function imprimirReciboAtual() {
      const html = reciboContainer.dataset.htmlRecibo || '';
      if (!html) {
        alert('Recibo não disponível no momento.');
        return;
      }
      const popup = window.open('', '_blank');
      if (!popup) {
        alert('Não foi possível abrir a janela de impressão.');
        return;
      }
      popup.document.write('<html><head><title>Recibo PDV Carmania</title></head><body>' + html + '</body></html>');
      popup.document.close();
      popup.focus();
      popup.print();
    }

    async function copiarReciboAtual() {
      const img = document.getElementById('reciboVendaImg');
      const html = reciboContainer.dataset.htmlRecibo || img?.dataset.htmlRecibo || '';
      if (!img && !html) {
        alert('Recibo não disponível no momento.');
        return;
      }
      try {
        if (img && navigator.clipboard?.write && window.ClipboardItem) {
          const blob = await fetch(img.src).then(r => r.blob());
          await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
          alert('Recibo copiado!');
          return;
        }
        if (html && navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(html);
          alert('Recibo copiado como texto!');
          return;
        }
        throw new Error('Clipboard API não suportada.');
      } catch (erro) {
        console.error(erro);
        alert('Não foi possível copiar o recibo automaticamente.');
      }
    }

    async function compartilharReciboAtual() {
      const img = document.getElementById('reciboVendaImg');
      if (!img) {
        alert('Recibo não disponível no momento.');
        return;
      }
      try {
        const blob = await fetch(img.src).then(r => r.blob());
        const arquivo = new File([blob], 'recibo-pdv.png', { type: 'image/png' });
        if (navigator.share) {
          await navigator.share({ files: [arquivo], title: 'Recibo PDV Carmania' });
        } else {
          alert('Compartilhar não é suportado neste dispositivo.');
        }
      } catch (erro) {
        console.error(erro);
        alert('Não foi possível compartilhar o recibo automaticamente.');
      }
    }

    function inicializarPaginaVendas() {
      const hoje = new Date().toISOString().slice(0, 10);
      document.getElementById('dataInicio').value = hoje;
      document.getElementById('dataFim').value = hoje;
      carregarVendas();
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', inicializarPaginaVendas);
    } else {
      inicializarPaginaVendas();
    }
  </script>
</body>
</html>
