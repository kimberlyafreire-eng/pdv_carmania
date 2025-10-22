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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receber - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .card-saldo { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 24px; }
    .saldo-valor { font-size: 2rem; font-weight: bold; color: #198754; }
    .list-group-item small { color: #666; }
    .autocomplete-list button { text-align:left; }
    .main-content { max-width: 960px; margin: 0 auto; }
    .list-group-item-action { cursor: pointer; }
    .list-group-item-action .badge { font-size: 0.75rem; }
    .titulo-origem { color: #888; }
    .recibo-mini { background: #fff; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); padding: 16px; }
    .relacao-card { border-radius: 12px; }
    .relacao-conteudo h5 { font-size: 1.05rem; }
    .relacao-conteudo table { width: 100%; }
    .relacao-conteudo thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .relacao-conteudo tbody td, .relacao-conteudo tfoot th { font-size: 0.95rem; }

    @media (max-width: 576px) {
      .card-saldo { padding: 20px; border-radius: 10px; }
      .saldo-valor { font-size: 1.5rem; }
      .navbar-brand { font-size: 1rem; }
    }
  </style>
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar navbar-dark bg-danger">
    <div class="container-fluid d-flex justify-content-between align-items-center">
      <button class="btn btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">
        MENU
      </button>
      <span class="navbar-brand mb-0 h1 text-white">Receber</span>
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
        <li><a class="btn btn-danger w-100 mb-2" href="receber.php">Receber</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="vendas.php">Vendas</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="caixa.php">Caixa</a></li>
        <li><a class="btn btn-outline-danger w-100" href="produtos.php">Produtos</a></li>
      </ul>
    </div>
  </div>

  <div class="container-fluid py-4 px-3 px-md-4 px-lg-5">
    <div class="main-content">
      <h3 class="mb-3 text-danger text-center text-md-start">Consultar Crediário</h3>

      <!-- Seleção de cliente -->
      <div class="card p-3 p-md-4 mb-4 shadow-sm border-0">
        <label class="form-label fw-bold">Cliente</label>
        <input type="text" id="clienteBusca" class="form-control" placeholder="Digite o nome do cliente">
        <div id="listaClientes" class="list-group mt-2 autocomplete-list"></div>
        <button class="btn btn-danger mt-3 w-100" id="btnBuscarSaldo" disabled>🔎 Consultar Saldo</button>
      </div>

      <!-- Exibição de saldo -->
      <div id="resultadoSaldo"></div>
    </div>
  </div>

  <div class="modal fade" id="detalheTituloModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Detalhes do crediário</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div id="detalheTituloInfo"></div>
          <div id="detalheTituloRecibo" class="mt-3"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script>
    let clientesLista = [];
    let clienteSelecionado = null;
    let titulosCliente = [];
    let relacaoImagemDataUrl = null;
    let relacaoGeradaEm = '';
    const situacaoLabels = {
      1: 'Em aberto',
      2: 'Liquidado',
      3: 'Parcial',
      4: 'Cancelado',
      5: 'Em atraso'
    };

    function formatCurrency(value) {
      const numero = typeof value === 'number' ? value : parseFloat(value);
      if (!Number.isFinite(numero)) {
        return 'R$ 0,00';
      }
      return numero.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function formatDate(value) {
      if (!value) return '-';
      const parsed = new Date(value);
      if (!Number.isNaN(parsed.getTime())) {
        return parsed.toLocaleDateString('pt-BR');
      }
      if (typeof value === 'string') {
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
          const [ano, mes, dia] = value.split('-');
          return `${dia}/${mes}/${ano}`;
        }
        if (/^\d{2}\/\d{2}\/\d{4}$/.test(value)) {
          return value;
        }
      }
      return value;
    }

    function formatDateTime(value) {
      if (!value) return '-';
      const parsed = new Date(value);
      if (!Number.isNaN(parsed.getTime())) {
        return parsed.toLocaleString('pt-BR');
      }
      return value;
    }

    function textoSeguro(valor) {
      return (typeof valor === 'string' && valor.trim()) ? valor.trim() : '';
    }

    function labelSituacao(codigo) {
      const chave = Number(codigo);
      return situacaoLabels[chave] || `Situação ${chave}`;
    }

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
            return;
          }
        } catch (erro) {
          console.warn(`Falha ao ler clientes de ${url}`, erro);
        }
      }

      clientesLista = [];
    }

    carregarClientes();

    // Autocomplete
    const inputBusca = document.getElementById("clienteBusca");
    const listaEl = document.getElementById("listaClientes");

    inputBusca.addEventListener("input", function() {
      const busca = this.value.toLowerCase();
      delete this.dataset.id;
      clienteSelecionado = null;
      document.getElementById("btnBuscarSaldo").disabled = true;
      listaEl.innerHTML = "";
      if (!busca) return;

      const encontrados = clientesLista.filter(c => c.nome.toLowerCase().includes(busca)).slice(0, 5);
      encontrados.forEach(cli => {
        const btn = document.createElement("button");
        btn.className = "list-group-item list-group-item-action";
        btn.textContent = cli.nome;
        btn.onclick = () => {
          inputBusca.value = cli.nome;
          inputBusca.dataset.id = cli.id;
          clienteSelecionado = cli;
          listaEl.innerHTML = "";
          document.getElementById("btnBuscarSaldo").disabled = false;
        };
        listaEl.appendChild(btn);
      });
    });

    // Buscar saldo
    document.getElementById("btnBuscarSaldo").addEventListener("click", async () => {
      if (!clienteSelecionado?.id) {
        alert("Selecione um cliente válido.");
        return;
      }

      const resultDiv = document.getElementById("resultadoSaldo");
      resultDiv.innerHTML = "<div class='alert alert-info'>Consultando saldo...</div>";
      titulosCliente = [];
      relacaoImagemDataUrl = null;
      relacaoGeradaEm = '';

      try {
        const res = await fetch("../api/crediario/saldo.php", {
          method: "POST",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify({ clienteId: clienteSelecionado.id })
        });
        const data = await res.json();

        if (!data.ok) {
          resultDiv.innerHTML = "<div class='alert alert-danger'>Erro ao consultar saldo.</div>";
          return;
        }

        const titulosRecebidos = Array.isArray(data.titulos) ? data.titulos : [];
        if (!titulosRecebidos.length) {
          resultDiv.innerHTML = `
            <div class="card-saldo text-center">
              <h5>${clienteSelecionado.nome}</h5>
              <p class="text-success mt-2 mb-1">Nenhum débito em aberto no crediário.</p>
            </div>`;
          localStorage.setItem("clienteRecebimento", JSON.stringify(clienteSelecionado));
          localStorage.setItem("saldoCrediario", JSON.stringify(data));
          return;
        }

        titulosCliente = titulosRecebidos.map((titulo) => {
          const copia = { ...titulo };
          const restanteNumero = Number.parseFloat(titulo.restante ?? titulo.valor ?? 0);
          const valorNumero = Number.parseFloat(titulo.valor ?? titulo.restante ?? 0);
          copia.restante = Number.isFinite(restanteNumero) ? restanteNumero : 0;
          copia.valor = Number.isFinite(valorNumero) ? valorNumero : copia.restante;
          const totalRecebidoNumero = Number.parseFloat(titulo.totalRecebido ?? 0);
          copia.totalRecebido = Number.isFinite(totalRecebidoNumero) ? totalRecebidoNumero : 0;
          if (Array.isArray(titulo.recebimentos)) {
            copia.recebimentos = titulo.recebimentos.map((bordero) => {
              const borderoCopia = { ...bordero };
              const pagamentos = Array.isArray(bordero.pagamentos) ? bordero.pagamentos.map((pg) => {
                const pagamentoCopia = { ...pg };
                const valorPagoNumero = Number.parseFloat(pg.valorPago ?? 0);
                const jurosNumero = Number.parseFloat(pg.juros ?? 0);
                const descontoNumero = Number.parseFloat(pg.desconto ?? 0);
                const acrescimoNumero = Number.parseFloat(pg.acrescimo ?? 0);
                const tarifaNumero = Number.parseFloat(pg.tarifa ?? 0);
                const valorAplicadoNumero = Number.parseFloat(pg.valorAplicado ?? (valorPagoNumero + jurosNumero + acrescimoNumero - descontoNumero - tarifaNumero));
                pagamentoCopia.valorPago = Number.isFinite(valorPagoNumero) ? valorPagoNumero : 0;
                pagamentoCopia.juros = Number.isFinite(jurosNumero) ? jurosNumero : 0;
                pagamentoCopia.desconto = Number.isFinite(descontoNumero) ? descontoNumero : 0;
                pagamentoCopia.acrescimo = Number.isFinite(acrescimoNumero) ? acrescimoNumero : 0;
                pagamentoCopia.tarifa = Number.isFinite(tarifaNumero) ? tarifaNumero : 0;
                pagamentoCopia.valorAplicado = Number.isFinite(valorAplicadoNumero) ? valorAplicadoNumero : pagamentoCopia.valorPago;
                return pagamentoCopia;
              }) : [];
              borderoCopia.pagamentos = pagamentos;
              const totalBordero = pagamentos.reduce((acc, pg) => acc + (Number.isFinite(Number(pg.valorAplicado)) ? Number(pg.valorAplicado) : 0), 0);
              borderoCopia.totalRecebido = Number.isFinite(Number(bordero.totalRecebido))
                ? Number(bordero.totalRecebido)
                : totalBordero;
              return borderoCopia;
            });
          } else {
            copia.recebimentos = [];
          }
          if ((!Number.isFinite(copia.totalRecebido) || copia.totalRecebido === 0) && Array.isArray(copia.recebimentos)) {
            const totalCalculado = copia.recebimentos.reduce((acc, bordero) => acc + (Number.isFinite(Number(bordero.totalRecebido)) ? Number(bordero.totalRecebido) : 0), 0);
            if (Number.isFinite(totalCalculado) && totalCalculado > 0) {
              copia.totalRecebido = totalCalculado;
            }
          }
          copia.saldoVendaAnterior = (titulo.saldoVendaAnterior ?? titulo.saldoVendaAnterior === 0)
            ? Number.parseFloat(titulo.saldoVendaAnterior)
            : null;
          copia.saldoVendaNovo = (titulo.saldoVendaNovo ?? titulo.saldoVendaNovo === 0)
            ? Number.parseFloat(titulo.saldoVendaNovo)
            : null;
          copia.valorCrediarioVenda = (titulo.valorCrediarioVenda ?? titulo.valorCrediarioVenda === 0)
            ? Number.parseFloat(titulo.valorCrediarioVenda)
            : null;
          return copia;
        });

        const titulosHtml = titulosCliente.map((t, idx) => {
          const doc = textoSeguro(t.documento);
          const partesOrigem = [];
          const tipoOrigem = textoSeguro(t.origemTipo);
          if (tipoOrigem) {
            partesOrigem.push(tipoOrigem.replace(/_/g, ' ').replace(/\b\w/g, (letra) => letra.toUpperCase()));
          }
          const numeroOrigem = textoSeguro(t.origemNumero);
          if (numeroOrigem) {
            partesOrigem.push(`#${numeroOrigem}`);
          } else {
            const origemId = textoSeguro(t.origemId);
            if (origemId) {
              partesOrigem.push(`#${origemId}`);
            }
          }
          const origemTexto = partesOrigem.length ? partesOrigem.join(' • ') : 'Origem não informada';

          return `
            <li class="list-group-item list-group-item-action d-flex justify-content-between align-items-start" onclick="mostrarDetalheTitulo(${idx})">
              <div>
                <div class="fw-bold">#${t.id} • ${formatCurrency(t.restante)}</div>
                <small>Vencimento: ${formatDate(t.vencimento)}${doc ? ` • Doc: ${doc}` : ''}</small><br>
                <small class="titulo-origem">${origemTexto}</small>
              </div>
              <span class="badge bg-danger-subtle text-danger">Ver</span>
            </li>`;
        }).join("");

        resultDiv.innerHTML = `
          <div class="card-saldo">
            <h5 class="text-danger">${clienteSelecionado.nome}</h5>
            <p class="saldo-valor mb-3">Saldo atual: ${formatCurrency(Number.parseFloat(data.saldoAtual ?? 0))}</p>
            <ul class="list-group mb-3">${titulosHtml}</ul>
            <div class="d-grid gap-2">
              <button class="btn btn-success btn-lg" onclick="pagarDebito()">💳 Pagar Débito</button>
              <button class="btn btn-outline-secondary btn-lg" onclick="gerarRelacaoTitulos()">📄 Relação do saldo</button>
            </div>
            <div id="relacaoCrediarioWrapper" class="mt-4" style="display:none;">
              <div class="card border-0 shadow-sm relacao-card">
                <div class="card-body relacao-conteudo">
                  <div id="relacaoParaImagem"></div>
                  <div class="d-grid gap-2 mt-3">
                    <button id="btnCompartilharRelacao" class="btn btn-success" onclick="compartilharRelacao()" disabled>📤 Compartilhar</button>
                  </div>
                </div>
              </div>
            </div>
          </div>`;

        localStorage.setItem("clienteRecebimento", JSON.stringify(clienteSelecionado));
        localStorage.setItem("saldoCrediario", JSON.stringify(data));

      } catch (err) {
        console.error(err);
        resultDiv.innerHTML = "<div class='alert alert-danger'>Erro de comunicação com o servidor.</div>";
      }
    });

    function mostrarDetalheTitulo(index) {
      const titulo = titulosCliente[index];
      if (!titulo) {
        return;
      }

      const modalEl = document.getElementById("detalheTituloModal");
      const infoEl = document.getElementById("detalheTituloInfo");
      const reciboEl = document.getElementById("detalheTituloRecibo");
      if (!modalEl || !infoEl || !reciboEl) {
        return;
      }

      const doc = textoSeguro(titulo.documento);
      const partesOrigem = [];
      const tipoOrigem = textoSeguro(titulo.origemTipo);
      if (tipoOrigem) {
        partesOrigem.push(tipoOrigem.replace(/_/g, ' ').replace(/\b\w/g, (letra) => letra.toUpperCase()));
      }
      const numeroOrigem = textoSeguro(titulo.origemNumero);
      if (numeroOrigem) {
        partesOrigem.push(`#${numeroOrigem}`);
      } else {
        const origemId = textoSeguro(titulo.origemId);
        if (origemId) {
          partesOrigem.push(`#${origemId}`);
        }
      }
      const origemTexto = partesOrigem.length ? partesOrigem.join(' • ') : 'Origem não informada';
      const linkDocumento = textoSeguro(titulo.origemUrl)
        ? `<a href="${titulo.origemUrl}" target="_blank" rel="noopener">Abrir documento no Bling</a>`
        : 'Documento não disponível';
      const totalRecebidoTitulo = Number.isFinite(Number(titulo.totalRecebido)) ? Number(titulo.totalRecebido) : 0;
      const saldoAnteriorHtml = titulo.saldoVendaAnterior !== null && titulo.saldoVendaAnterior !== undefined
        ? `<li>Saldo antes: <strong>${formatCurrency(titulo.saldoVendaAnterior)}</strong></li>`
        : '';
      const valorCrediarioHtml = titulo.valorCrediarioVenda !== null && titulo.valorCrediarioVenda !== undefined
        ? `<li>Valor lançado: <strong>${formatCurrency(titulo.valorCrediarioVenda)}</strong></li>`
        : '';
      const saldoNovoHtml = titulo.saldoVendaNovo !== null && titulo.saldoVendaNovo !== undefined
        ? `<li>Novo saldo: <strong>${formatCurrency(titulo.saldoVendaNovo)}</strong></li>`
        : '';
      const resumoSaldoVenda = (saldoAnteriorHtml || valorCrediarioHtml || saldoNovoHtml)
        ? `<div class="alert alert-secondary mt-3 mb-0">
             <h6 class="mb-2">Saldo registrado na venda</h6>
             <ul class="list-unstyled mb-0 small">
               ${saldoAnteriorHtml}${valorCrediarioHtml}${saldoNovoHtml}
           </ul>
         </div>`
        : '';
      const recebimentos = Array.isArray(titulo.recebimentos) ? titulo.recebimentos : [];
      const recebimentosDetalhes = recebimentos.map((bordero) => {
        const dataBordero = formatDate(bordero.data);
        const historico = textoSeguro(bordero.historico);
        const pagamentos = Array.isArray(bordero.pagamentos) ? bordero.pagamentos.map((pg) => {
          const detalhesExtras = [];
          if (Number(pg.juros)) detalhesExtras.push(`Juros ${formatCurrency(pg.juros)}`);
          if (Number(pg.desconto)) detalhesExtras.push(`Desconto ${formatCurrency(pg.desconto)}`);
          if (Number(pg.acrescimo)) detalhesExtras.push(`Acréscimo ${formatCurrency(pg.acrescimo)}`);
          if (Number(pg.tarifa)) detalhesExtras.push(`Tarifa ${formatCurrency(pg.tarifa)}`);
          const extrasTexto = detalhesExtras.length ? ` <span class="text-muted">(${detalhesExtras.join(' • ')})</span>` : '';
          const docPg = textoSeguro(pg.numeroDocumento);
          const docTexto = docPg ? ` • Doc: ${docPg}` : '';
          return `<li>Valor baixado: <strong>${formatCurrency(pg.valorAplicado ?? pg.valorPago ?? 0)}</strong>${extrasTexto}${docTexto}</li>`;
        }).join('') : '';
        const corpoPagamentos = pagamentos ? `<ul class="small ps-3 mb-0">${pagamentos}</ul>` : '<div class="small text-muted">Pagamentos não informados.</div>';
        return `<div class="mb-3">
          <div class="small fw-semibold">Borderô #${textoSeguro(bordero.id) || bordero.id || '-'} • ${dataBordero}</div>
          ${historico ? `<div class="small text-muted mb-1">${historico}</div>` : ''}
          ${corpoPagamentos}
        </div>`;
      }).join('');
      const blocoRecebimentos = recebimentosDetalhes
        ? `<div class="mt-3">
            <h6 class="mb-2">Recebimentos localizados</h6>
            ${recebimentosDetalhes}
          </div>`
        : '<div class="alert alert-light border mt-3 mb-0">Nenhum recebimento localizado no Bling para este título.</div>';

      infoEl.innerHTML = `
        <div class="mb-3">
          <h5 class="mb-1">Conta #${titulo.id}</h5>
          <p class="mb-2"><span class="badge bg-danger-subtle text-danger">${labelSituacao(titulo.situacao)}</span></p>
          <p class="mb-1"><strong>Vencimento:</strong> ${formatDate(titulo.vencimento)}</p>
          <p class="mb-1"><strong>Saldo em aberto:</strong> ${formatCurrency(titulo.restante)}</p>
          <p class="mb-1"><strong>Valor original:</strong> ${formatCurrency(titulo.valor)}</p>
          <p class="mb-1"><strong>Total recebido:</strong> ${formatCurrency(totalRecebidoTitulo)}</p>
          <p class="mb-1"><strong>Data de emissão:</strong> ${formatDate(titulo.dataEmissao)}</p>
          <p class="mb-1"><strong>Documento:</strong> ${doc || '-'}</p>
          <p class="mb-1"><strong>Origem:</strong> ${origemTexto}</p>
          <p class="mb-0"><strong>Detalhes:</strong> ${linkDocumento}</p>
        </div>
        ${resumoSaldoVenda}
        ${blocoRecebimentos}
        ${titulo.vendaId ? `<div class="alert alert-light border mt-3 mb-0">
            <h6 class="mb-1">Venda vinculada</h6>
            <small>ID da venda: #${titulo.vendaId}</small>
          </div>` : '<div class="alert alert-info mt-3 mb-0">Não foi possível localizar automaticamente a venda vinculada a este título.</div>'}
      `;

      reciboEl.innerHTML = '';

      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();

      if (titulo.vendaId) {
        carregarReciboTitulo(index);
      }
    }

    async function carregarReciboTitulo(index, forceRefresh = false) {
      const titulo = titulosCliente[index];
      const reciboEl = document.getElementById("detalheTituloRecibo");
      if (!titulo || !titulo.vendaId || !reciboEl) {
        if (reciboEl && (!titulo || !titulo.vendaId)) {
          reciboEl.innerHTML = '<div class="alert alert-info mt-3 mb-0">Venda não encontrada para exibir recibo.</div>';
        }
        return;
      }

      if (titulo.reciboHtml && !forceRefresh) {
        reciboEl.innerHTML = `<div class="recibo-mini">${titulo.reciboHtml}</div>`;
        return;
      }

      reciboEl.innerHTML = '<div class="text-center text-muted py-3">Carregando notinha da venda...</div>';

      try {
        const resposta = await fetch(`../api/venda-recibo.php?id=${encodeURIComponent(titulo.vendaId)}`, { cache: 'no-store' });
        const dados = await resposta.json();
        if (!resposta.ok || !dados.ok || !dados.reciboHtml) {
          throw new Error(dados.erro || 'Falha ao carregar recibo');
        }
        titulo.reciboHtml = dados.reciboHtml;
        reciboEl.innerHTML = `<div class="recibo-mini">${dados.reciboHtml}</div>`;
      } catch (erro) {
        console.error(erro);
        reciboEl.innerHTML = '<div class="alert alert-danger">Não foi possível carregar a notinha da venda no momento.</div>';
      }
    }

    function gerarRelacaoTitulos() {
      if (!titulosCliente.length) {
        alert('Nenhum título em aberto para gerar a relação.');
        return;
      }

      const wrapper = document.getElementById('relacaoCrediarioWrapper');
      const container = document.getElementById('relacaoParaImagem');
      const botaoShare = document.getElementById('btnCompartilharRelacao');
      if (!wrapper || !container || !botaoShare) {
        return;
      }

      const agora = new Date();
      relacaoGeradaEm = agora.toLocaleString('pt-BR');
      const totalAberto = titulosCliente.reduce((acc, t) => acc + (Number.isFinite(Number(t.restante)) ? Number(t.restante) : 0), 0);
      const totalOriginal = titulosCliente.reduce((acc, t) => acc + (Number.isFinite(Number(t.valor)) ? Number(t.valor) : 0), 0);
      const totalRecebido = titulosCliente.reduce((acc, t) => acc + (Number.isFinite(Number(t.totalRecebido)) ? Number(t.totalRecebido) : 0), 0);

      const linhas = titulosCliente.map((t) => `
        <tr>
          <td class="text-start">#${t.id}</td>
          <td class="text-start">${formatDate(t.vencimento)}</td>
          <td class="text-end">${formatCurrency(t.valor)}</td>
          <td class="text-end">${formatCurrency(t.totalRecebido ?? 0)}</td>
          <td class="text-end">${formatCurrency(t.restante)}</td>
        </tr>
      `).join('');

      const detalhesRecebimentos = titulosCliente.map((t) => {
        if (!Array.isArray(t.recebimentos) || !t.recebimentos.length) {
          return '';
        }
        const borderosHtml = t.recebimentos.map((bordero) => {
          const dataTexto = formatDate(bordero.data);
          const historico = textoSeguro(bordero.historico);
          const pagamentos = Array.isArray(bordero.pagamentos) ? bordero.pagamentos.map((pg) => {
            const extras = [];
            if (Number(pg.juros)) extras.push(`Juros ${formatCurrency(pg.juros)}`);
            if (Number(pg.desconto)) extras.push(`Desconto ${formatCurrency(pg.desconto)}`);
            if (Number(pg.acrescimo)) extras.push(`Acréscimo ${formatCurrency(pg.acrescimo)}`);
            if (Number(pg.tarifa)) extras.push(`Tarifa ${formatCurrency(pg.tarifa)}`);
            const extrasTexto = extras.length ? ` <span class="text-muted">(${extras.join(' • ')})</span>` : '';
            const docPg = textoSeguro(pg.numeroDocumento);
            const docTexto = docPg ? ` • Doc: ${docPg}` : '';
            return `<li>Valor baixado: <strong>${formatCurrency(pg.valorAplicado ?? pg.valorPago ?? 0)}</strong>${extrasTexto}${docTexto}</li>`;
          }).join('') : '';
          const corpoPagamentos = pagamentos ? `<ul class="small ps-3 mb-0">${pagamentos}</ul>` : '<div class="small text-muted">Pagamentos não informados.</div>';
          return `<div class="mb-3">
            <div class="small fw-semibold">Borderô #${textoSeguro(bordero.id) || bordero.id || '-'} • ${dataTexto}</div>
            ${historico ? `<div class="small text-muted mb-1">${historico}</div>` : ''}
            ${corpoPagamentos}
          </div>`;
        }).join('');
        return `<div class="mt-3">
          <div class="fw-semibold">Conta #${t.id} — Recebido ${formatCurrency(t.totalRecebido ?? 0)}</div>
          ${borderosHtml}
        </div>`;
      }).filter((html) => Boolean(html)).join('');

      container.innerHTML = `
        <div class="text-center mb-3">
          <h5 class="mb-1">Relação de crediário em aberto</h5>
          <div class="small text-muted">${clienteSelecionado?.nome ? textoSeguro(clienteSelecionado.nome) : ''}</div>
          <div class="small text-muted">Gerado em ${relacaoGeradaEm}</div>
        </div>
        <table class="table table-borderless table-sm mb-0">
          <thead>
            <tr>
              <th class="text-start">Conta</th>
              <th class="text-start">Vencimento</th>
              <th class="text-end">Valor original</th>
              <th class="text-end">Recebido</th>
              <th class="text-end">Saldo</th>
            </tr>
          </thead>
          <tbody>
            ${linhas}
          </tbody>
          <tfoot>
            <tr>
              <th colspan="2" class="text-start">Totais</th>
              <th class="text-end">${formatCurrency(totalOriginal)}</th>
              <th class="text-end">${formatCurrency(totalRecebido)}</th>
              <th class="text-end">${formatCurrency(totalAberto)}</th>
            </tr>
          </tfoot>
        </table>
        ${detalhesRecebimentos ? `<div class="mt-4">
            <h6 class="mb-2">Recebimentos detalhados</h6>
            ${detalhesRecebimentos}
          </div>` : ''}
      `;

      wrapper.style.display = 'block';
      botaoShare.disabled = false;
      relacaoImagemDataUrl = null;
    }

    async function compartilharRelacao() {
      const botaoShare = document.getElementById('btnCompartilharRelacao');
      const container = document.getElementById('relacaoParaImagem');
      if (!botaoShare || !container) {
        return;
      }

      if (typeof html2canvas !== 'function') {
        alert('Biblioteca de captura não carregada. Recarregue a página e tente novamente.');
        return;
      }

      botaoShare.disabled = true;

      try {
        if (!relacaoImagemDataUrl) {
          const canvas = await html2canvas(container, { scale: 2, backgroundColor: '#ffffff' });
          relacaoImagemDataUrl = canvas.toDataURL('image/png');
        }

        const resposta = await fetch(relacaoImagemDataUrl);
        const blob = await resposta.blob();
        const nomeArquivo = `relacao-crediario-${Date.now()}.png`;
        const arquivo = new File([blob], nomeArquivo, { type: 'image/png' });

        if (navigator.share) {
          if (navigator.canShare && navigator.canShare({ files: [arquivo] })) {
            await navigator.share({ files: [arquivo], title: 'Relação de crediário' });
          } else {
            await navigator.share({ files: [arquivo], title: 'Relação de crediário' });
          }
        } else {
          alert('Compartilhamento não suportado neste dispositivo.');
        }
      } catch (erro) {
        console.error(erro);
        alert('Não foi possível compartilhar a relação no momento.');
      } finally {
        botaoShare.disabled = false;
      }
    }

    function pagarDebito() {
      window.location.href = "pagamento-crediario.php";
    }

    window.mostrarDetalheTitulo = mostrarDetalheTitulo;
    window.gerarRelacaoTitulos = gerarRelacaoTitulos;
    window.compartilharRelacao = compartilharRelacao;
    window.pagarDebito = pagarDebito;
  </script>
</body>
</html>
