<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit();
}

$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
$estoquePadraoId = null;
$usuarioLogado = $_SESSION['usuario'] ?? null;

if (file_exists($dbFile)) {
    try {
        $db = new SQLite3($dbFile);
        $stmt = $db->prepare('SELECT estoque_padrao FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->bindValue(1, $usuarioLogado, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        if ($row && !empty($row['estoque_padrao'])) {
            $estoquePadraoId = trim((string)$row['estoque_padrao']);
        }
    } catch (Throwable $e) {
        error_log('Erro ao buscar estoque padrão: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Controle de Caixa - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    :root {
      color-scheme: light;
    }

    body {
      background-color: #f8f9fa;
      min-height: 100vh;
    }

    .navbar {
      min-height: 60px;
    }

    .valor-destaque {
      border: 2px solid rgba(220, 53, 69, 0.2);
      border-radius: 16px;
      padding: 1.5rem;
      background: rgba(220, 53, 69, 0.05);
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .valor-destaque .label {
      font-size: 0.9rem;
      color: #6c757d;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .valor-destaque .valor {
      font-size: clamp(1.9rem, 4vw, 2.7rem);
      font-weight: 700;
      color: #212529;
    }

    .status-badge {
      align-self: flex-start;
      font-size: 0.85rem;
      padding: 0.35rem 0.75rem;
      border-radius: 999px;
    }

    .operacao-card {
      border: none;
      border-radius: 14px;
      padding: 1.5rem 1.25rem;
      background: #ffffff;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
      width: 100%;
      text-align: left;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .operacao-card .icon {
      font-size: 1.8rem;
    }

    .operacao-card .titulo {
      font-weight: 600;
      font-size: 1.1rem;
    }

    .operacao-card .descricao {
      font-size: 0.9rem;
      color: #6c757d;
    }

    .operacao-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 14px 30px rgba(220, 53, 69, 0.18);
    }

    .operacao-card.disabled {
      opacity: 0.45;
      pointer-events: none;
      box-shadow: none;
    }

    .operacao-card:not(.disabled):focus {
      outline: 3px solid rgba(220, 53, 69, 0.3);
      outline-offset: 2px;
    }

    .movimentacoes-card {
      border-radius: 16px;
      border: none;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
    }

    .table-movimentacoes thead {
      background-color: #f1f3f5;
    }

    .table-movimentacoes th {
      font-size: 0.85rem;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .table-movimentacoes td {
      vertical-align: middle;
    }

    .saldo-positivo {
      color: #198754;
      font-weight: 600;
    }

    .saldo-negativo {
      color: #dc3545;
      font-weight: 600;
    }

    .observacao-cell {
      max-width: 260px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    @media (max-width: 768px) {
      .operacao-card {
        padding: 1.25rem 1rem;
      }
      .observacao-cell {
        max-width: 160px;
      }
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
        <li><a class="btn btn-outline-danger w-100 mb-2" href="produtos.php">Produtos</a></li>
        <li><a class="btn btn-danger w-100" href="caixa.php">Caixa</a></li>
      </ul>
    </div>
  </div>

  <div class="container py-4">
    <div class="d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3 mb-4">
      <div>
        <h2 class="mb-1">Controle de Caixa</h2>
        <p class="text-muted mb-0">Gerencie o status e as movimentações por depósito com segurança.</p>
      </div>
      <div class="d-flex gap-2 align-items-center w-100 w-lg-auto">
        <label class="form-label mb-0 text-muted" for="selectDeposito">Depósito</label>
        <select id="selectDeposito" class="form-select">
          <option value="">Selecione um depósito</option>
        </select>
      </div>
    </div>

    <div id="alertas"></div>

    <div class="row g-3 mb-4">
      <div class="col-lg-4">
        <div class="valor-destaque h-100">
          <span class="label">Saldo Atual</span>
          <span id="saldoAtual" class="valor">R$ 0,00</span>
          <span id="statusCaixa" class="status-badge badge bg-secondary">Fechado</span>
          <small class="text-muted" id="atualizadoEm">Atualizado em --</small>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card movimentacoes-card h-100">
          <div class="card-body">
            <h5 class="card-title mb-2">Resumo rápido</h5>
            <p class="mb-0 text-muted" id="resumoMensagem">Selecione um depósito para visualizar o status do caixa.</p>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4" id="cardsOperacoes">
      <div class="col-6 col-lg-3">
        <button type="button" class="operacao-card" data-tipo="abertura">
          <span class="icon">🔓</span>
          <span class="titulo">Abertura</span>
          <span class="descricao">Disponível apenas com o caixa fechado.</span>
        </button>
      </div>
      <div class="col-6 col-lg-3">
        <button type="button" class="operacao-card" data-tipo="fechamento">
          <span class="icon">🔒</span>
          <span class="titulo">Fechamento</span>
          <span class="descricao">Finalize o turno encerrando o caixa.</span>
        </button>
      </div>
      <div class="col-6 col-lg-3">
        <button type="button" class="operacao-card" data-tipo="retirada">
          <span class="icon">💸</span>
          <span class="titulo">Retirada</span>
          <span class="descricao">Registre saídas de valores durante o dia.</span>
        </button>
      </div>
      <div class="col-6 col-lg-3">
        <button type="button" class="operacao-card" data-tipo="abastecimento">
          <span class="icon">➕</span>
          <span class="titulo">Abastecimento</span>
          <span class="descricao">Adicione recursos ao caixa em operação.</span>
        </button>
      </div>
    </div>

    <div class="card movimentacoes-card">
      <div class="card-header bg-white d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <div>
          <h5 class="mb-0">Movimentações recentes</h5>
          <small class="text-muted">Histórico limitado às últimas 50 movimentações.</small>
        </div>
        <button class="btn btn-outline-secondary btn-sm" id="btnAtualizar" type="button">Atualizar</button>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 table-movimentacoes">
            <thead>
              <tr>
                <th>Data</th>
                <th>Movimentação</th>
                <th class="text-end">Valor</th>
                <th class="text-end">Saldo após</th>
                <th>Usuário</th>
                <th>Observação</th>
              </tr>
            </thead>
            <tbody id="listaMovimentacoes">
              <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma movimentação carregada.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modalMovimentacao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="formMovimentacao">
          <div class="modal-header">
            <h5 class="modal-title" id="modalTitulo">Registrar movimentação</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="valorMovimentacao" class="form-label">Valor</label>
              <input type="number" step="0.01" min="0.01" class="form-control" id="valorMovimentacao" required />
              <div class="form-text" id="ajudaValor">Informe o valor em reais. Use ponto para separar os centavos.</div>
            </div>
            <div class="mb-0">
              <label for="observacaoMovimentacao" class="form-label">Observação (opcional)</label>
              <textarea class="form-control" id="observacaoMovimentacao" maxlength="140" rows="2" placeholder="Máximo de 140 caracteres"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-danger" id="btnConfirmarMovimentacao">Confirmar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    window.USUARIO_LOGADO = <?php echo json_encode($usuarioLogado); ?>;
    window.ESTOQUE_PADRAO_ID = <?php echo json_encode($estoquePadraoId); ?>;

    const numeroBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const dataHoraBR = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' });

    const selectDeposito = document.getElementById('selectDeposito');
    const saldoAtualEl = document.getElementById('saldoAtual');
    const statusCaixaEl = document.getElementById('statusCaixa');
    const atualizadoEmEl = document.getElementById('atualizadoEm');
    const resumoMensagemEl = document.getElementById('resumoMensagem');
    const listaMovimentacoes = document.getElementById('listaMovimentacoes');
    const alertasEl = document.getElementById('alertas');
    const btnAtualizar = document.getElementById('btnAtualizar');
    const cardsOperacoes = document.querySelectorAll('.operacao-card');

    const modalEl = document.getElementById('modalMovimentacao');
    const modalTitulo = document.getElementById('modalTitulo');
    const inputValor = document.getElementById('valorMovimentacao');
    const inputObs = document.getElementById('observacaoMovimentacao');
    const formMovimentacao = document.getElementById('formMovimentacao');
    const btnConfirmar = document.getElementById('btnConfirmarMovimentacao');
    const ajudaValor = document.getElementById('ajudaValor');
    const modal = new bootstrap.Modal(modalEl);

    const informacoesOperacoes = {
      abertura: {
        titulo: 'Abertura de Caixa',
        mensagem: 'Informe o valor inicial do caixa.',
      },
      fechamento: {
        titulo: 'Fechamento de Caixa',
        mensagem: 'Informe o valor total encontrado no caixa para encerramento.',
      },
      retirada: {
        titulo: 'Retirada',
        mensagem: 'Informe o valor retirado do caixa.',
      },
      abastecimento: {
        titulo: 'Abastecimento',
        mensagem: 'Informe o valor que será adicionado ao caixa.',
      }
    };

    let depositos = [];
    let caixaAtual = null;
    let tipoSelecionado = null;

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

    function formatarSaldo(valor) {
      return numeroBRL.format(valor || 0);
    }

    function atualizarStatus(status) {
      if (status === 'Aberto') {
        statusCaixaEl.classList.remove('bg-secondary');
        statusCaixaEl.classList.add('bg-success');
      } else {
        statusCaixaEl.classList.remove('bg-success');
        statusCaixaEl.classList.add('bg-secondary');
      }
      statusCaixaEl.textContent = status || 'Fechado';
    }

    function podeExecutarOperacao(status, tipo) {
      if (!status) return false;
      switch (tipo) {
        case 'abertura':
          return status === 'Fechado';
        case 'fechamento':
        case 'retirada':
        case 'abastecimento':
          return status === 'Aberto';
        default:
          return false;
      }
    }

    function atualizarCards(status) {
      cardsOperacoes.forEach(card => {
        const tipo = card.dataset.tipo;
        if (podeExecutarOperacao(status, tipo)) {
          card.classList.remove('disabled');
        } else {
          card.classList.add('disabled');
        }
      });
    }

    function montarTabelaMovimentacoes(movimentos) {
      if (!movimentos || movimentos.length === 0) {
        listaMovimentacoes.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma movimentação registrada.</td></tr>';
        return;
      }

      const linhas = movimentos.map(mov => {
        const valor = mov.natureza < 0 ? -Math.abs(mov.valor) : Math.abs(mov.valor);
        const classeValor = valor >= 0 ? 'saldo-positivo' : 'saldo-negativo';
        const saldoClasse = mov.saldoResultante >= 0 ? 'saldo-positivo' : 'saldo-negativo';
        const dataFormatada = mov.dataHora ? dataHoraBR.format(new Date(mov.dataHora)) : '--';
        const usuario = mov.usuarioNome || '—';
        const observacao = mov.observacao ? mov.observacao : '—';

        return `
          <tr>
            <td>${dataFormatada}</td>
            <td>${mov.tipoNome}</td>
            <td class="text-end ${classeValor}">${formatarSaldo(valor)}</td>
            <td class="text-end ${saldoClasse}">${formatarSaldo(mov.saldoResultante)}</td>
            <td>${usuario}</td>
            <td class="observacao-cell" title="${observacao}">${observacao}</td>
          </tr>
        `;
      }).join('');

      listaMovimentacoes.innerHTML = linhas;
    }

    async function carregarDepositos() {
      try {
        const resposta = await fetch('../api/depositos.php?nocache=' + Date.now());
        const json = await resposta.json();
        if (!json || !Array.isArray(json.data)) {
          throw new Error('Retorno inválido da API de depósitos.');
        }
        depositos = json.data;
        popularSelectDepositos();
      } catch (erro) {
        console.error('Erro ao carregar depósitos:', erro);
        mostrarAlerta('danger', 'Não foi possível carregar a lista de depósitos. Tente novamente.');
      }
    }

    function popularSelectDepositos() {
      selectDeposito.innerHTML = '<option value="">Selecione um depósito</option>';
      depositos.forEach(dep => {
        const option = document.createElement('option');
        option.value = dep.id;
        option.textContent = dep.descricao;
        selectDeposito.appendChild(option);
      });

      if (window.ESTOQUE_PADRAO_ID) {
        const encontrado = depositos.find(dep => String(dep.id) === String(window.ESTOQUE_PADRAO_ID));
        if (encontrado) {
          selectDeposito.value = encontrado.id;
          carregarCaixaAtual();
          return;
        }
      }

      if (depositos.length === 1) {
        selectDeposito.value = depositos[0].id;
        carregarCaixaAtual();
      }
    }

    function obterNomeDepositoSelecionado() {
      const selecionado = selectDeposito.selectedOptions[0];
      return selecionado ? selecionado.textContent : '';
    }

    async function carregarCaixaAtual(mostrarMensagem = false) {
      const depositoId = selectDeposito.value;
      if (!depositoId) {
        caixaAtual = null;
        saldoAtualEl.textContent = formatarSaldo(0);
        atualizarStatus('Fechado');
        atualizadoEmEl.textContent = 'Atualizado em --';
        resumoMensagemEl.textContent = 'Selecione um depósito para visualizar o status do caixa.';
        montarTabelaMovimentacoes([]);
        atualizarCards(null);
        if (mostrarMensagem) {
          mostrarAlerta('warning', 'Selecione um depósito antes de atualizar.');
        }
        return;
      }

      limparAlerta();
      resumoMensagemEl.textContent = 'Carregando dados do caixa...';

      try {
        const params = new URLSearchParams({
          depositoId,
          depositoNome: obterNomeDepositoSelecionado(),
          _: Date.now().toString()
        });
        const resposta = await fetch('../api/caixa.php?' + params.toString());
        if (!resposta.ok) {
          const erro = await resposta.json().catch(() => ({ erro: 'Falha ao carregar o caixa.' }));
          throw new Error(erro.erro || 'Erro desconhecido.');
        }
        const json = await resposta.json();
        if (!json.ok) {
          throw new Error(json.erro || 'Erro ao carregar caixa.');
        }

        caixaAtual = json.caixa;
        atualizarInterface(json);
        if (mostrarMensagem) {
          mostrarAlerta('success', 'Informações atualizadas com sucesso.');
        }
      } catch (erro) {
        console.error('Erro ao carregar caixa:', erro);
        mostrarAlerta('danger', erro.message || 'Erro ao carregar dados do caixa.');
      } finally {
        resumoMensagemEl.textContent = caixaAtual ? `Caixa ${caixaAtual.status.toLowerCase()} para o depósito selecionado.` : 'Selecione um depósito para visualizar o status do caixa.';
      }
    }

    function atualizarInterface(dados) {
      if (!dados || !dados.caixa) return;
      const { caixa, movimentos } = dados;
      saldoAtualEl.textContent = formatarSaldo(caixa.saldoAtual);
      atualizarStatus(caixa.status);
      atualizadoEmEl.textContent = caixa.atualizadoEm ? `Atualizado em ${dataHoraBR.format(new Date(caixa.atualizadoEm))}` : 'Atualizado em --';
      resumoMensagemEl.textContent = `Caixa ${caixa.status.toLowerCase()} para o depósito ${caixa.nome}.`;
      montarTabelaMovimentacoes(movimentos || []);
      atualizarCards(caixa.status);
    }

    function abrirModalOperacao(tipo) {
      if (!caixaAtual || !podeExecutarOperacao(caixaAtual.status, tipo)) {
        return;
      }
      tipoSelecionado = tipo;
      const info = informacoesOperacoes[tipo] || { titulo: 'Registrar movimentação', mensagem: '' };
      modalTitulo.textContent = info.titulo;
      inputValor.value = '';
      inputObs.value = '';
      ajudaValor.textContent = info.mensagem || 'Informe o valor em reais. Use ponto para separar os centavos.';
      modal.show();
      setTimeout(() => inputValor.focus(), 300);
    }

    async function registrarMovimentacao(event) {
      event.preventDefault();
      if (!tipoSelecionado || !caixaAtual) {
        modal.hide();
        return;
      }

      const depositoId = selectDeposito.value;
      if (!depositoId) {
        mostrarAlerta('warning', 'Selecione um depósito antes de registrar a movimentação.');
        modal.hide();
        return;
      }

      const valor = parseFloat(inputValor.value);
      if (!valor || valor <= 0) {
        mostrarAlerta('warning', 'Informe um valor válido para a movimentação.');
        return;
      }

      btnConfirmar.disabled = true;
      btnConfirmar.innerText = 'Enviando...';

      try {
        const payload = {
          depositoId,
          depositoNome: obterNomeDepositoSelecionado(),
          tipo: tipoSelecionado,
          valor,
          observacao: inputObs.value || ''
        };
        const resposta = await fetch('../api/caixa.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const json = await resposta.json().catch(() => ({ ok: false, erro: 'Erro ao processar resposta.' }));
        if (!resposta.ok || !json.ok) {
          throw new Error(json.erro || 'Falha ao registrar movimentação.');
        }

        modal.hide();
        mostrarAlerta('success', json.mensagem || 'Movimentação registrada.');
        caixaAtual = json.caixa;
        atualizarInterface(json);
      } catch (erro) {
        console.error('Erro ao registrar movimentação:', erro);
        mostrarAlerta('danger', erro.message || 'Erro ao registrar movimentação.');
      } finally {
        btnConfirmar.disabled = false;
        btnConfirmar.innerText = 'Confirmar';
        tipoSelecionado = null;
      }
    }

    selectDeposito.addEventListener('change', () => carregarCaixaAtual(true));
    btnAtualizar.addEventListener('click', () => carregarCaixaAtual(true));
    formMovimentacao.addEventListener('submit', registrarMovimentacao);

    cardsOperacoes.forEach(card => {
      card.addEventListener('click', () => abrirModalOperacao(card.dataset.tipo));
    });

    document.addEventListener('DOMContentLoaded', () => {
      carregarDepositos();
    });
  </script>
</body>
</html>
