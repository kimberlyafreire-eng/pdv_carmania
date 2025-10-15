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
    .resumo-card-mes {
      border: 2px solid rgba(25, 135, 84, 0.15);
      background: rgba(25, 135, 84, 0.05);
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
        <span class="label">Vendas do dia (status atendido)</span>
        <span class="valor" id="totalDia">R$ 0,00</span>
      </div>
      <div class="resumo-card resumo-card-mes flex-fill">
        <span class="label">Vendas do mês (status atendido)</span>
        <span class="valor" id="totalMes">R$ 0,00</span>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const tabelaCorpo = document.getElementById('tabelaVendasCorpo');
    const semResultados = document.getElementById('semResultados');
    const loader = document.getElementById('loaderVendas');
    const mensagemVendas = document.getElementById('mensagemVendas');
    const formFiltros = document.getElementById('formFiltros');
    const formaPagamentoSelect = document.getElementById('formaPagamento');
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

    const totalDiaEl = document.getElementById('totalDia');
    const totalMesEl = document.getElementById('totalMes');

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
            <button type="button" class="btn btn-outline-danger btn-sm cancelar-venda-btn" title="Cancelar venda">
              Cancelar venda
            </button>
          </td>
        `;

        tr.addEventListener('click', (event) => {
          if (event.target.closest('.cancelar-venda-btn')) {
            event.stopPropagation();
            return;
          }
          abrirDetalheVenda(venda.id);
        });

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
        totalMesEl.textContent = formatoMoeda.format(json.resumo?.totalMes || 0);
        renderizarVendas(json.vendas || []);
        if (Array.isArray(json.formasPagamento)) {
          popularFormasPagamento(json.formasPagamento);
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

    formFiltros.addEventListener('submit', (event) => {
      event.preventDefault();
      carregarVendas();
    });

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
