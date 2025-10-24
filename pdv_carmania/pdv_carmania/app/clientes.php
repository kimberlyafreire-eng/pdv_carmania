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
  <title>PDV Carmania - Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background-color: #f5f7fa;
      min-height: 100vh;
    }
    .card {
      border: none;
      border-radius: 16px;
    }
    .card-header {
      border-top-left-radius: 16px;
      border-top-right-radius: 16px;
    }
    .list-group-item-action {
      border-radius: 12px;
      margin-bottom: 8px;
    }
    .badge-id {
      font-size: 0.7rem;
      background: #efefef;
      color: #555;
    }
    .required::after {
      content: '*';
      color: #dc3545;
      margin-left: 4px;
    }
    .saldo-crediario {
      font-size: 0.9rem;
    }
    .saldo-crediario.text-danger {
      font-weight: 600;
    }
    .saldo-crediario.text-muted {
      font-style: italic;
    }
    @media (max-width: 576px) {
      h2 {
        font-size: 1.5rem;
      }
      .card-body {
        padding: 1.25rem;
      }
      .btn-lg {
        padding: 0.65rem 1rem;
        font-size: 1rem;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-danger">
    <div class="container-fluid d-flex justify-content-between">
      <button class="btn btn-outline-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral">
        MENU
      </button>
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
        <li><a class="btn btn-danger w-100 mb-2" href="clientes.php">Clientes</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="receber.php">Receber</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="vendas.php">Vendas</a></li>
        <li><a class="btn btn-outline-danger w-100 mb-2" href="caixa.php">Caixa</a></li>
        <li><a class="btn btn-outline-danger w-100" href="produtos.php">Produtos</a></li>
      </ul>
    </div>
  </div>

  <div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
      <div>
        <h2 class="mb-1">Cadastro de Clientes</h2>
        <p class="text-muted mb-0">Gerencie rapidamente os clientes do PDV e mantenha os dados sempre atualizados.</p>
      </div>
      <button id="btnNovoCliente" class="btn btn-success btn-lg">+ Novo Cliente</button>
    </div>

    <div id="mensagens"></div>

    <div id="listaWrapper" class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-column flex-sm-row gap-2">
          <label for="buscaCliente" class="form-label mb-0 fw-semibold">Buscar cliente</label>
          <small class="text-muted">Digite nome, CPF/CNPJ ou código para localizar rapidamente.</small>
        </div>
        <div class="input-group">
          <span class="input-group-text">🔍</span>
          <input type="text" id="buscaCliente" class="form-control" placeholder="Pesquisar clientes" autocomplete="off" />
          <button id="btnRecarregar" class="btn btn-outline-secondary" type="button">Atualizar</button>
          <button id="btnSaldoNegativo" class="btn btn-outline-danger" type="button" aria-pressed="false">Saldo Negativo</button>
        </div>
        <div id="listaClientes" class="mt-3"></div>
      </div>
    </div>

    <div id="formWrapper" class="card shadow-sm d-none">
      <div class="card-header bg-white d-flex justify-content-between align-items-start flex-column flex-md-row gap-2">
        <div>
          <h5 class="mb-0" id="tituloFormulario">Dados do Cliente</h5>
          <small class="text-muted">Campos com * são obrigatórios.</small>
        </div>
        <button type="button" class="btn btn-link text-decoration-none px-0" id="btnVoltarLista">&larr; Voltar para a lista</button>
      </div>
      <div class="card-body">
        <form id="formCliente" class="row g-3">
          <input type="hidden" id="clienteId" name="id" />

          <div class="col-12">
            <label for="nome" class="form-label required">Nome completo</label>
            <input type="text" class="form-control" id="nome" name="nome" required maxlength="120" placeholder="Nome completo" />
          </div>

          <div class="col-12 col-md-6">
            <label for="tipoPessoa" class="form-label">Pessoa</label>
            <select class="form-select" id="tipoPessoa" name="tipoPessoa">
              <option value="F" selected>Física</option>
              <option value="J">Jurídica</option>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label for="documento" class="form-label">CPF/CNPJ</label>
            <input type="text" class="form-control" id="documento" name="documento" placeholder="Somente números" maxlength="18" />
          </div>

          <div class="col-12 col-md-6">
            <label for="celular" class="form-label required">Celular</label>
            <input type="tel" class="form-control" id="celular" name="celular" required placeholder="(00) 90000-0000" maxlength="20" />
          </div>

          <div class="col-12 col-md-6">
            <label for="telefone" class="form-label">Telefone</label>
            <input type="tel" class="form-control" id="telefone" name="telefone" placeholder="(00) 3000-0000" maxlength="20" />
          </div>

          <div class="col-12 col-md-8">
            <label for="endereco" class="form-label">Rua</label>
            <input type="text" class="form-control" id="endereco" name="endereco" placeholder="Rua" maxlength="150" />
          </div>

          <div class="col-12 col-md-4">
            <label for="numero" class="form-label">Número</label>
            <input type="text" class="form-control" id="numero" name="numero" placeholder="Número" maxlength="20" />
          </div>

          <div class="col-12 col-md-6">
            <label for="bairro" class="form-label">Bairro</label>
            <input type="text" class="form-control" id="bairro" name="bairro" maxlength="80" />
          </div>

          <div class="col-12 col-md-6">
            <label for="cidade" class="form-label">Cidade</label>
            <input type="text" class="form-control" id="cidade" name="cidade" maxlength="80" />
          </div>

          <div class="col-12 col-md-4">
            <label for="estado" class="form-label">Estado</label>
            <input type="text" class="form-control text-uppercase" id="estado" name="estado" maxlength="2" placeholder="UF" />
          </div>

          <div class="col-12 col-md-4">
            <label for="cep" class="form-label">CEP</label>
            <input type="text" class="form-control" id="cep" name="cep" maxlength="10" placeholder="00000-000" />
          </div>

          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="permiteBoleto" name="permiteBoleto" />
              <label class="form-check-label" for="permiteBoleto">Permitir pagamento com boleto</label>
            </div>
          </div>

          <div class="col-12 col-md-4 align-self-end">
            <button type="submit" class="btn btn-danger w-100 btn-lg">Salvar Cliente</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const mensagensEl = document.getElementById('mensagens');
    const listaClientesEl = document.getElementById('listaClientes');
    const formCliente = document.getElementById('formCliente');
    const btnNovoCliente = document.getElementById('btnNovoCliente');
    const btnRecarregar = document.getElementById('btnRecarregar');
    const btnSaldoNegativo = document.getElementById('btnSaldoNegativo');
    const btnVoltarLista = document.getElementById('btnVoltarLista');
    const listaWrapper = document.getElementById('listaWrapper');
    const formWrapper = document.getElementById('formWrapper');
    const tituloFormulario = document.getElementById('tituloFormulario');
    const buscaClienteInput = document.getElementById('buscaCliente');
    const tipoPessoaSelect = document.getElementById('tipoPessoa');
    const documentoInput = document.getElementById('documento');
    const nomeInput = document.getElementById('nome');
    const clienteIdInput = document.getElementById('clienteId');
    const celularInput = document.getElementById('celular');
    const telefoneInput = document.getElementById('telefone');
    const enderecoInput = document.getElementById('endereco');
    const numeroInput = document.getElementById('numero');
    const bairroInput = document.getElementById('bairro');
    const cidadeInput = document.getElementById('cidade');
    const estadoInput = document.getElementById('estado');
    const cepInput = document.getElementById('cep');
    const permiteBoletoInput = document.getElementById('permiteBoleto');

    const SALDO_CACHE_KEY = 'clientesSaldoCrediario';
    const MAX_CONCORRENCIA_SALDOS = 3;
    const formatadorMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const HABILITAR_SALDOS = false;

    const saldosClientes = new Map();
    const elementosSaldo = new Map();
    const filaSaldos = [];
    const saldosEmProcesso = new Set();
    const saldosPendentes = new Set();

    let clientes = [];
    let clienteSelecionado = null;
    let saldoNegativoAtivo = false;
    let modoFormulario = 'lista';

    if (HABILITAR_SALDOS) {
      carregarSaldosDoStorage();
      atualizarEstadoBotaoSaldo();
    } else {
      btnSaldoNegativo.classList.add('d-none');
    }

    function setMensagem(tipo, texto) {
      mensagensEl.innerHTML = `
        <div class="alert alert-${tipo} alert-dismissible fade show" role="alert">
          ${texto}
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
    }

    function limparMensagem() {
      mensagensEl.innerHTML = '';
    }

    function formatarDocumento(valor) {
      return valor.replace(/\D+/g, '');
    }

    function obterChaveCliente(clienteId) {
      if (clienteId === undefined || clienteId === null) {
        return '';
      }
      return String(clienteId);
    }

    function carregarSaldosDoStorage() {
      if (typeof sessionStorage === 'undefined') {
        return;
      }
      try {
        const bruto = sessionStorage.getItem(SALDO_CACHE_KEY);
        if (!bruto) {
          return;
        }
        const armazenados = JSON.parse(bruto);
        if (armazenados && typeof armazenados === 'object') {
          Object.entries(armazenados).forEach(([id, valor]) => {
            const numero = Number(valor);
            if (Number.isFinite(numero)) {
              saldosClientes.set(String(id), numero);
            }
          });
        }
      } catch (erro) {
        console.warn('Não foi possível carregar cache de saldos', erro);
        try {
          sessionStorage.removeItem(SALDO_CACHE_KEY);
        } catch (_) {
          /* ignorado */
        }
      }
    }

    function persistirSaldosNoStorage() {
      if (typeof sessionStorage === 'undefined') {
        return;
      }
      try {
        if (!saldosClientes.size) {
          sessionStorage.removeItem(SALDO_CACHE_KEY);
          return;
        }
        const dados = {};
        saldosClientes.forEach((valor, id) => {
          dados[id] = valor;
        });
        sessionStorage.setItem(SALDO_CACHE_KEY, JSON.stringify(dados));
      } catch (erro) {
        console.warn('Não foi possível salvar cache de saldos', erro);
      }
    }

    function obterSaldoRegistrado(clienteId) {
      const chave = obterChaveCliente(clienteId);
      if (!chave) {
        return null;
      }
      const valor = saldosClientes.get(chave);
      return typeof valor === 'number' && !Number.isNaN(valor) ? valor : null;
    }

    function getSaldoDisplayInfo(valor) {
      if (typeof valor === 'number') {
        if (valor > 0.009) {
          return {
            texto: `Saldo crediário em aberto: ${formatadorMoeda.format(valor)}`,
            classe: 'text-danger'
          };
        }
        if (valor < -0.009) {
          return {
            texto: `Saldo crediário: ${formatadorMoeda.format(valor)}`,
            classe: 'text-success'
          };
        }
        return {
          texto: `Saldo crediário: ${formatadorMoeda.format(0)}`,
          classe: 'text-success'
        };
      }
      return {
        texto: 'Saldo crediário: consultando...',
        classe: 'text-muted'
      };
    }

    function atualizarSaldoNaInterface(clienteId) {
      const chave = obterChaveCliente(clienteId);
      if (!chave) {
        return;
      }
      const elemento = elementosSaldo.get(chave);
      if (!elemento) {
        return;
      }
      const saldo = obterSaldoRegistrado(chave);
      const info = getSaldoDisplayInfo(saldo);
      elemento.textContent = info.texto;
      elemento.className = `small saldo-crediario ${info.classe}`;
    }

    function registrarSaldoCliente(clienteId, valor) {
      const chave = obterChaveCliente(clienteId);
      if (!chave) {
        return;
      }
      const numero = Number(valor);
      const saldoNormalizado = Number.isFinite(numero) ? numero : 0;
      saldosClientes.set(chave, saldoNormalizado);
      persistirSaldosNoStorage();

      const clienteLocal = clientes.find(c => obterChaveCliente(c.id) === chave);
      if (clienteLocal) {
        clienteLocal.saldoCrediario = saldoNormalizado;
      }
      if (clienteSelecionado && obterChaveCliente(clienteSelecionado.id) === chave) {
        clienteSelecionado.saldoCrediario = saldoNormalizado;
      }

      if (saldoNegativoAtivo) {
        atualizarListaClientes(buscaClienteInput.value);
      } else {
        atualizarSaldoNaInterface(chave);
      }
    }

    async function consultarSaldoCliente(clienteId) {
      const resposta = await fetch('../api/crediario/saldo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clienteId })
      });
      const json = await resposta.json().catch(() => null);
      if (!resposta.ok || !json || json.ok !== true) {
        throw new Error(json?.erro || 'Erro ao consultar saldo do crediário.');
      }
      const valor = Number(json.saldoAtual);
      return Number.isFinite(valor) ? valor : 0;
    }

    function processarFilaSaldos() {
      while (saldosEmProcesso.size < MAX_CONCORRENCIA_SALDOS && filaSaldos.length) {
        const chave = filaSaldos.shift();
        if (!chave) {
          continue;
        }
        saldosPendentes.delete(chave);
        if (saldosEmProcesso.has(chave)) {
          continue;
        }
        if (!saldosClientes.has(chave)) {
          atualizarSaldoNaInterface(chave);
        }
        saldosEmProcesso.add(chave);
        consultarSaldoCliente(chave)
          .then(valor => registrarSaldoCliente(chave, valor))
          .catch(erro => {
            console.error('Erro ao consultar saldo do cliente', chave, erro);
            registrarSaldoCliente(chave, 0);
          })
          .finally(() => {
            saldosEmProcesso.delete(chave);
            processarFilaSaldos();
          });
      }
    }

    function agendarSaldoCliente(clienteId, forcar = false) {
      const chave = obterChaveCliente(clienteId);
      if (!chave) {
        return;
      }
      if (forcar) {
        if (saldosClientes.has(chave)) {
          saldosClientes.delete(chave);
          persistirSaldosNoStorage();
          const clienteLocal = clientes.find(c => obterChaveCliente(c.id) === chave);
          if (clienteLocal) {
            clienteLocal.saldoCrediario = null;
          }
          if (clienteSelecionado && obterChaveCliente(clienteSelecionado.id) === chave) {
            clienteSelecionado.saldoCrediario = null;
          }
        }
      } else if (saldosClientes.has(chave)) {
        return;
      }
      if (saldosEmProcesso.has(chave) || saldosPendentes.has(chave)) {
        return;
      }
      saldosPendentes.add(chave);
      filaSaldos.push(chave);
      atualizarSaldoNaInterface(chave);
      processarFilaSaldos();
    }

    function limparCacheSaldos() {
      saldosClientes.clear();
      persistirSaldosNoStorage();
      filaSaldos.length = 0;
      saldosPendentes.clear();
      clientes.forEach(cliente => {
        cliente.saldoCrediario = null;
      });
    }

    function atualizarEstadoBotaoSaldo() {
      if (saldoNegativoAtivo) {
        btnSaldoNegativo.classList.remove('btn-outline-danger');
        btnSaldoNegativo.classList.add('btn-danger', 'active');
        btnSaldoNegativo.setAttribute('aria-pressed', 'true');
      } else {
        btnSaldoNegativo.classList.add('btn-outline-danger');
        btnSaldoNegativo.classList.remove('btn-danger', 'active');
        btnSaldoNegativo.setAttribute('aria-pressed', 'false');
      }
    }

    function preencherFormulario(cliente) {
      if (!cliente || typeof cliente !== 'object') {
        return;
      }
      clienteSelecionado = cliente;
      clienteIdInput.value = cliente?.id || '';
      nomeInput.value = cliente?.nome || '';
      let tipo = cliente?.tipo || '';
      if (!tipo && cliente?.numeroDocumento) {
        const tamanhoDoc = cliente.numeroDocumento.replace(/\D+/g, '').length;
        tipo = tamanhoDoc > 11 ? 'J' : 'F';
      }
      tipoPessoaSelect.value = tipo || 'F';
      tipoPessoaSelect.setCustomValidity('');
      documentoInput.value = cliente?.numeroDocumento || '';
      celularInput.value = cliente?.celular || '';
      telefoneInput.value = cliente?.telefone || '';

      const endereco = cliente?.endereco?.geral || {};
      enderecoInput.value = endereco.endereco || '';
      numeroInput.value = endereco.numero || cliente?.numero || '';
      bairroInput.value = endereco.bairro || '';
      cidadeInput.value = endereco.municipio || '';
      estadoInput.value = endereco.uf || '';
      cepInput.value = endereco.cep || '';
      const permiteBoletoValor = cliente?.permiteBoleto ?? cliente?.permite_boleto ?? false;
      permiteBoletoInput.checked = Boolean(permiteBoletoValor);

      mostrarFormulario('editar');
    }

    function limparFormulario() {
      clienteSelecionado = null;
      formCliente.reset();
      clienteIdInput.value = '';
      tipoPessoaSelect.value = 'F';
      tipoPessoaSelect.setCustomValidity('');
      documentoInput.value = '';
      numeroInput.value = '';
      permiteBoletoInput.checked = false;
      formCliente.classList.remove('was-validated');
    }

    function mostrarFormulario(modo) {
      modoFormulario = modo;
      listaWrapper.classList.add('d-none');
      formWrapper.classList.remove('d-none');
      btnNovoCliente.classList.add('d-none');
      tituloFormulario.textContent = modo === 'editar' ? 'Editar Cliente' : 'Novo Cliente';
      formCliente.classList.remove('was-validated');
      setTimeout(() => {
        nomeInput.focus();
      }, 50);
    }

    function mostrarLista() {
      modoFormulario = 'lista';
      formWrapper.classList.add('d-none');
      listaWrapper.classList.remove('d-none');
      btnNovoCliente.classList.remove('d-none');
      formCliente.classList.remove('was-validated');
      setTimeout(() => {
        buscaClienteInput.focus();
      }, 50);
    }

    function criarItemLista(cliente) {
      const li = document.createElement('button');
      li.type = 'button';
      li.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3 text-start';
      const conteudo = document.createElement('div');
      conteudo.className = 'flex-grow-1';

      const nomeEl = document.createElement('div');
      nomeEl.className = 'fw-semibold';
      nomeEl.textContent = cliente.nome || 'Sem nome';
      conteudo.appendChild(nomeEl);

      const chave = obterChaveCliente(cliente.id);
      if (HABILITAR_SALDOS) {
        const saldoEl = document.createElement('div');
        saldoEl.className = 'small saldo-crediario text-muted';
        saldoEl.dataset.clienteSaldo = chave;
        elementosSaldo.set(chave, saldoEl);
        if (typeof cliente.saldoCrediario === 'number' && !Number.isNaN(cliente.saldoCrediario)) {
          saldosClientes.set(chave, cliente.saldoCrediario);
        }
        atualizarSaldoNaInterface(chave);
        conteudo.appendChild(saldoEl);
      }

      const documento = cliente.numeroDocumento || '—';
      const telefone = cliente.celular || cliente.telefone || '';
      if (documento || telefone) {
        const info = document.createElement('small');
        info.className = 'text-muted d-block mt-1';
        info.textContent = telefone ? `${documento}${documento !== '—' ? ' • ' : ''}${telefone}` : documento;
        conteudo.appendChild(info);
      }

      li.appendChild(conteudo);

      const badge = document.createElement('span');
      badge.className = 'badge badge-id';
      badge.textContent = `#${cliente.id || '—'}`;
      li.appendChild(badge);

      li.addEventListener('click', () => {
        preencherFormulario(cliente);
        setMensagem('info', 'Cliente carregado. Edite os dados e clique em salvar para atualizar.');
      });

      if (HABILITAR_SALDOS) {
        agendarSaldoCliente(cliente.id);
      }
      return li;
    }

    function atualizarListaClientes(filtro = '') {
      listaClientesEl.innerHTML = '';
      elementosSaldo.clear();

      if (!clientes.length) {
        listaClientesEl.innerHTML = '<p class="text-muted mb-0">Nenhum cliente encontrado. Utilize o botão atualizar para sincronizar.</p>';
        return;
      }

      const termo = filtro.trim().toLowerCase();
      let resultados = clientes;
      if (termo) {
        resultados = clientes.filter(cliente => {
          const nome = (cliente.nome || '').toLowerCase();
          const documento = (cliente.numeroDocumento || '').toLowerCase();
          const codigo = (cliente.codigo || '').toLowerCase();
          return nome.includes(termo) || documento.includes(termo) || codigo.includes(termo);
        });
      }

      let pendentesSaldo = false;
      if (HABILITAR_SALDOS && saldoNegativoAtivo) {
        const filtrados = [];
        resultados.forEach(cliente => {
          const saldo = obterSaldoRegistrado(cliente.id);
          if (saldo === null) {
            pendentesSaldo = true;
            agendarSaldoCliente(cliente.id);
            return;
          }
          if (saldo > 0.009) {
            filtrados.push(cliente);
          }
        });
        resultados = filtrados;
      }

      if (!resultados.length) {
        if (HABILITAR_SALDOS && saldoNegativoAtivo) {
          listaClientesEl.innerHTML = pendentesSaldo
            ? '<p class="text-muted mb-0">Consultando saldos do crediário...</p>'
            : '<p class="text-muted mb-0">Nenhum cliente com saldo em aberto no crediário.</p>';
        } else {
          listaClientesEl.innerHTML = '<p class="text-muted mb-0">Nenhum resultado para a busca realizada.</p>';
        }
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'list-group';
      resultados.forEach(cliente => {
        wrapper.appendChild(criarItemLista(cliente));
      });
      listaClientesEl.appendChild(wrapper);

      if (HABILITAR_SALDOS && saldoNegativoAtivo) {
        const aindaCarregando = clientes.some(cliente => obterSaldoRegistrado(cliente.id) === null);
        if (aindaCarregando) {
          const aviso = document.createElement('p');
          aviso.className = 'text-muted small mt-2 mb-0';
          aviso.textContent = 'Consultando saldos restantes...';
          listaClientesEl.appendChild(aviso);
        }
      }
    }

    async function carregarTiposContato() {
      try {
        const resposta = await fetch('../api/contatos-tipos.php');
        if (!resposta.ok) {
          throw new Error('Erro ao carregar tipos de contato.');
        }
        await resposta.json();
      } catch (erro) {
        setMensagem('warning', 'Não foi possível sincronizar os tipos de contato. Tente novamente se ocorrer erro ao salvar.');
      }
    }

    async function carregarClientes(forcarAtualizacao = false) {
      listaClientesEl.innerHTML = '<div class="text-center text-muted py-3">Carregando clientes...</div>';
      try {
        const endpoint = forcarAtualizacao ? '../api/clientes.php?refresh=1' : '../api/clientes.php';
        const resposta = await fetch(endpoint, { cache: 'no-store' });
        if (!resposta.ok) {
          throw new Error('Erro ao consultar clientes.');
        }
        const json = await resposta.json();
        const dados = Array.isArray(json.data) ? json.data : [];
        clientes = dados
          .filter(cliente => cliente && typeof cliente === 'object')
          .map(cliente => {
            const chave = obterChaveCliente(cliente.id);
            const saldo = HABILITAR_SALDOS ? obterSaldoRegistrado(chave) : null;
            return {
              ...cliente,
              saldoCrediario: saldo
            };
          });

        if (HABILITAR_SALDOS) {
          clientes.forEach(cliente => agendarSaldoCliente(cliente.id, saldoNegativoAtivo));
        }

        atualizarListaClientes(buscaClienteInput.value);
      } catch (erro) {
        listaClientesEl.innerHTML = '<div class="alert alert-danger" role="alert">Não foi possível carregar os clientes. Tente novamente.</div>';
      }
    }

    async function salvarCliente(dados) {
      const resposta = await fetch('../api/salvar-cliente.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(dados),
      });

      const json = await resposta.json().catch(() => ({}));
      if (!resposta.ok || !json.sucesso) {
        const erro = json.erro || 'Falha ao salvar o cliente.';
        throw new Error(erro);
      }
      return json;
    }

    buscaClienteInput.addEventListener('input', () => {
      atualizarListaClientes(buscaClienteInput.value);
    });

    btnRecarregar.addEventListener('click', () => {
      if (HABILITAR_SALDOS) {
        limparCacheSaldos();
      }
      atualizarListaClientes(buscaClienteInput.value);
      carregarClientes(true);
    });

    btnSaldoNegativo.addEventListener('click', () => {
      if (!HABILITAR_SALDOS) {
        return;
      }
      saldoNegativoAtivo = !saldoNegativoAtivo;
      atualizarEstadoBotaoSaldo();
      if (saldoNegativoAtivo) {
        setMensagem('info', 'Mostrando apenas clientes com saldo em aberto no crediário.');
      }
      clientes.forEach(cliente => agendarSaldoCliente(cliente.id, saldoNegativoAtivo));
      atualizarListaClientes(buscaClienteInput.value);
    });

    btnNovoCliente.addEventListener('click', () => {
      limparFormulario();
      mostrarFormulario('novo');
      setMensagem('primary', 'Preencha os dados para cadastrar um novo cliente.');
    });

    btnVoltarLista.addEventListener('click', () => {
      limparFormulario();
      mostrarLista();
      if (!mensagensEl.innerHTML.trim()) {
        setMensagem('primary', 'Busque um cliente para editar ou clique em "Novo Cliente".');
      }
    });

    formCliente.addEventListener('submit', async (event) => {
      event.preventDefault();
      formCliente.classList.add('was-validated');

      const documento = formatarDocumento(documentoInput.value);
      let tipoPessoaSelecionado = (tipoPessoaSelect.value || 'F').toUpperCase();
      if (!['F', 'J'].includes(tipoPessoaSelecionado)) {
        tipoPessoaSelecionado = 'F';
        tipoPessoaSelect.value = 'F';
      }

      if (!formCliente.reportValidity()) {
        return;
      }

      const dados = {
        id: clienteIdInput.value,
        nome: nomeInput.value.trim(),
        tipoPessoa: tipoPessoaSelecionado,
        documento,
        celular: celularInput.value.trim(),
        telefone: telefoneInput.value.trim(),
        endereco: enderecoInput.value.trim(),
        numero: numeroInput.value.trim(),
        bairro: bairroInput.value.trim(),
        cidade: cidadeInput.value.trim(),
        estado: estadoInput.value.trim(),
        cep: formatarDocumento(cepInput.value),
        permiteBoleto: !!permiteBoletoInput.checked,
      };

      try {
        const resultado = await salvarCliente(dados);
        setMensagem('success', resultado.mensagem || 'Cliente salvo com sucesso.');
        const clienteRetornado = resultado.cliente && typeof resultado.cliente === 'object' ? resultado.cliente : null;
        let chaveAtualizada = null;

        if (clienteRetornado) {
          chaveAtualizada = obterChaveCliente(clienteRetornado.id);
          const saldoAtual = HABILITAR_SALDOS ? obterSaldoRegistrado(chaveAtualizada) : null;
          if (typeof saldoAtual === 'number') {
            clienteRetornado.saldoCrediario = saldoAtual;
          }

          const indice = clientes.findIndex(c => obterChaveCliente(c.id) === chaveAtualizada);
          if (indice >= 0) {
            const saldoAnterior = typeof clientes[indice].saldoCrediario === 'number'
              ? clientes[indice].saldoCrediario
              : (typeof saldoAtual === 'number' ? saldoAtual : null);
            clientes[indice] = { ...clienteRetornado, saldoCrediario: saldoAnterior };
          } else {
            clientes.unshift({
              ...clienteRetornado,
              saldoCrediario: typeof saldoAtual === 'number' ? saldoAtual : null,
            });
          }
        }

        limparFormulario();
        mostrarLista();

        if (clienteRetornado) {
          atualizarListaClientes(buscaClienteInput.value);
          if (HABILITAR_SALDOS && chaveAtualizada !== null) {
            agendarSaldoCliente(chaveAtualizada, true);
          }
        } else {
          carregarClientes();
        }
      } catch (erro) {
        setMensagem('danger', erro.message);
      }
    });

    carregarTiposContato();
    carregarClientes();
    setMensagem('primary', 'Busque um cliente para editar ou clique em "Novo Cliente".');
  </script>

</body>
</html>
