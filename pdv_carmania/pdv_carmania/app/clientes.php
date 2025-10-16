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

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-column flex-sm-row gap-2">
          <label for="buscaCliente" class="form-label mb-0 fw-semibold">Buscar cliente</label>
          <small class="text-muted">Digite nome, CPF/CNPJ ou código para localizar rapidamente.</small>
        </div>
        <div class="input-group">
          <span class="input-group-text">🔍</span>
          <input type="text" id="buscaCliente" class="form-control" placeholder="Pesquisar clientes" autocomplete="off" />
          <button id="btnRecarregar" class="btn btn-outline-secondary" type="button">Atualizar</button>
        </div>
        <div id="listaClientes" class="mt-3"></div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header bg-white">
        <h5 class="mb-0">Dados do Cliente</h5>
        <small class="text-muted">Campos com * são obrigatórios.</small>
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
              <option value="">Selecione...</option>
              <option value="F">Física</option>
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

          <div class="col-12">
            <label for="endereco" class="form-label">Rua</label>
            <input type="text" class="form-control" id="endereco" name="endereco" placeholder="Rua, número e complemento" maxlength="150" />
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
    const buscaClienteInput = document.getElementById('buscaCliente');
    const tipoPessoaSelect = document.getElementById('tipoPessoa');
    const documentoInput = document.getElementById('documento');

    let clientes = [];
    let clienteSelecionado = null;

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

    function preencherFormulario(cliente) {
      clienteSelecionado = cliente;
      document.getElementById('clienteId').value = cliente?.id || '';
      document.getElementById('nome').value = cliente?.nome || '';
      let tipo = cliente?.tipo || '';
      if (!tipo && cliente?.numeroDocumento) {
        const tamanhoDoc = cliente.numeroDocumento.replace(/\D+/g, '').length;
        tipo = tamanhoDoc > 11 ? 'J' : 'F';
      }
      tipoPessoaSelect.value = tipo || '';
      documentoInput.value = cliente?.numeroDocumento || '';
      document.getElementById('celular').value = cliente?.celular || '';
      document.getElementById('telefone').value = cliente?.telefone || '';

      const endereco = cliente?.endereco?.geral || {};
      document.getElementById('endereco').value = endereco.endereco || '';
      document.getElementById('bairro').value = endereco.bairro || '';
      document.getElementById('cidade').value = endereco.municipio || '';
      document.getElementById('estado').value = endereco.uf || '';
      document.getElementById('cep').value = endereco.cep || '';
    }

    function limparFormulario() {
      clienteSelecionado = null;
      formCliente.reset();
      document.getElementById('clienteId').value = '';
      tipoPessoaSelect.value = '';
      tipoPessoaSelect.setCustomValidity('');
      formCliente.classList.remove('was-validated');
      limparMensagem();
    }

    function criarItemLista(cliente) {
      const li = document.createElement('button');
      li.type = 'button';
      li.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
      const nome = cliente.nome || 'Sem nome';
      const documento = cliente.numeroDocumento || '—';
      const telefone = cliente.celular || cliente.telefone || '';
      li.innerHTML = `
        <div>
          <div class="fw-semibold">${nome}</div>
          <small class="text-muted">${documento}${telefone ? ' • ' + telefone : ''}</small>
        </div>
        <span class="badge badge-id">#${cliente.id || '—'}</span>`;
      li.addEventListener('click', () => {
        preencherFormulario(cliente);
        setMensagem('info', 'Cliente carregado. Edite os dados e clique em salvar para atualizar.');
      });
      return li;
    }

    function atualizarListaClientes(filtro = '') {
      listaClientesEl.innerHTML = '';
      if (!clientes.length) {
        listaClientesEl.innerHTML = '<p class="text-muted mb-0">Nenhum cliente encontrado. Utilize o botão atualizar para sincronizar.</p>';
        return;
      }

      const termo = filtro.trim().toLowerCase();
      const fragment = document.createDocumentFragment();
      let resultados = clientes;
      if (termo) {
        resultados = clientes.filter(cliente => {
          const nome = (cliente.nome || '').toLowerCase();
          const documento = (cliente.numeroDocumento || '').toLowerCase();
          const codigo = (cliente.codigo || '').toLowerCase();
          return nome.includes(termo) || documento.includes(termo) || codigo.includes(termo);
        });
      }

      if (!resultados.length) {
        listaClientesEl.innerHTML = '<p class="text-muted mb-0">Nenhum resultado para a busca realizada.</p>';
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'list-group';
      resultados.slice(0, 30).forEach(cliente => {
        wrapper.appendChild(criarItemLista(cliente));
      });
      listaClientesEl.appendChild(wrapper);
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
        clientes = Array.isArray(json.data) ? json.data : [];
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
      carregarClientes(true);
    });

    btnNovoCliente.addEventListener('click', () => {
      limparFormulario();
      setMensagem('primary', 'Preencha os dados para cadastrar um novo cliente.');
      document.getElementById('nome').focus();
    });

    formCliente.addEventListener('submit', async (event) => {
      event.preventDefault();
      formCliente.classList.add('was-validated');
      tipoPessoaSelect.setCustomValidity('');
      const documento = formatarDocumento(documentoInput.value);
      const tipoPessoaSelecionado = tipoPessoaSelect.value;
      if (documento && !tipoPessoaSelecionado) {
        tipoPessoaSelect.setCustomValidity('Selecione o tipo de pessoa ao informar CPF/CNPJ.');
      }

      if (!formCliente.reportValidity()) {
        if (tipoPessoaSelect.validationMessage) {
          setMensagem('warning', tipoPessoaSelect.validationMessage);
          tipoPessoaSelect.focus();
        }
        return;
      }

      const dados = {
        id: document.getElementById('clienteId').value,
        nome: document.getElementById('nome').value.trim(),
        tipoPessoa: tipoPessoaSelecionado,
        documento,
        celular: document.getElementById('celular').value.trim(),
        telefone: document.getElementById('telefone').value.trim(),
        endereco: document.getElementById('endereco').value.trim(),
        bairro: document.getElementById('bairro').value.trim(),
        cidade: document.getElementById('cidade').value.trim(),
        estado: document.getElementById('estado').value.trim(),
        cep: formatarDocumento(document.getElementById('cep').value),
      };

      try {
        const resultado = await salvarCliente(dados);
        setMensagem('success', resultado.mensagem || 'Cliente salvo com sucesso.');
        const clienteRetornado = resultado.cliente;
        if (clienteRetornado) {
          const indice = clientes.findIndex(c => String(c.id) === String(clienteRetornado.id));
          if (indice >= 0) {
            clientes[indice] = clienteRetornado;
          } else {
            clientes.unshift(clienteRetornado);
          }
          atualizarListaClientes(buscaClienteInput.value);
          preencherFormulario(clienteRetornado);
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
