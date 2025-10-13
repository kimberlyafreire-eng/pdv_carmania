<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
$usuarioLogado = $_SESSION['usuario'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Pagamento Crediário - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; margin: 0; }
    .navbar-custom { background-color: #dc3545; }
    .total-display { font-size: 1.5rem; font-weight: bold; text-align: center; margin: 20px 0; }
    .forma-card {
      background: white; border: 2px solid #dc3545; border-radius: 8px;
      padding: 20px; text-align: center; cursor: pointer;
      font-weight: bold; transition: 0.2s; user-select: none;
    }
    .forma-card:hover { background: #dc3545; color: white; }
    .resumo-pagamentos { margin-top: 20px; }
    #reciboContainer {
      display: flex; flex-direction: column; align-items: center;
      justify-content: center; height: 85vh;
    }
    #reciboBox {
      width: 90vw; max-width: 450px; height: 80vh;
      background: #fff; border-radius: 16px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      padding: 20px; overflow-y: auto;
      display: flex; flex-direction: column; justify-content: start;
      text-align: center;
    }
    #reciboBox img {
      width: 100%; border-radius: 8px;
    }
    #acoesRecibo {
      display: flex; justify-content: center; flex-wrap: wrap; gap: 8px;
      margin-top: 12px;
    }
    @media print {
      nav, #acoesRecibo { display: none; }
      body { background: white; }
      #reciboBox { box-shadow: none; height: auto; }
    }
  </style>
</head>
<body>

  <!-- Barra superior -->
  <nav class="navbar navbar-dark navbar-custom px-3">
    <button class="btn btn-light" onclick="voltar()">⬅ Receber</button>
    <span class="navbar-text text-white fw-bold">Pagamento Crediário</span>
    <div></div>
  </nav>

  <div class="container py-3" id="mainContainer">
    <div class="total-display">
      Saldo a Receber: <span id="valorTotal">R$ 0,00</span>
    </div>

    <h5>Selecione a forma de pagamento</h5>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Pix', 7697682)">Pix</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Crédito', 2941151)">Crédito</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Débito', 2941150)">Débito</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Dinheiro', 8126950)">Dinheiro</div></div>
    </div>

    <div class="resumo-pagamentos">
      <h5>Pagamentos adicionados</h5>
      <ul class="list-group" id="listaPagamentos"></ul>
      <div class="mt-3 fw-bold" id="faltando"></div>
    </div>

    <button class="btn btn-success w-100 btn-lg mt-3" id="btnConcluir" disabled onclick="concluirPagamento()">✅ Concluir Pagamento</button>

    <div id="reciboContainer" class="mt-4"></div>
  </div>

  <!-- Modal valor -->
  <div class="modal fade" id="modalValor" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Inserir valor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Valor (R$)</label>
          <input type="number" id="valorPagamento" class="form-control" min="0" step="0.01" inputmode="decimal">
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" onclick="adicionarPagamento()">Adicionar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script>
    window.USUARIO_LOGADO = <?= json_encode($usuarioLogado) ?>;
  </script>
  <script>
    const fmt = (n) => "R$ " + (Number(n)||0).toFixed(2);
    const toNum = (v) => parseFloat((v ?? 0)) || 0;
    const usuarioLogado = window.USUARIO_LOGADO || null;

    const cliente = JSON.parse(localStorage.getItem("clienteRecebimento") || "null");
    const saldoData = JSON.parse(localStorage.getItem("saldoCrediario") || "null");

    if (!cliente || !saldoData) {
      alert("Informações do cliente não encontradas. Retornando...");
      window.location.href = "receber.php";
    }

    const totalReceber = toNum(saldoData.saldoAtual || 0);
    document.getElementById("valorTotal").textContent = fmt(totalReceber);

    let pagamentos = [];
    let formaSelecionada = null;

    function voltar() { window.location.href = "receber.php"; }

    function selecionarForma(nome, id) {
      formaSelecionada = { nome, id };
      document.getElementById("valorPagamento").value = "";
      new bootstrap.Modal(document.getElementById("modalValor")).show();
    }

    function adicionarPagamento() {
  if (!formaSelecionada) return;
  let valor = toNum(document.getElementById("valorPagamento").value);
  if (valor <= 0) return alert("Informe um valor válido.");

  const totalPago = pagamentos.reduce((s, p) => s + toNum(p.valor), 0);
  const limite = totalReceber - totalPago;

  if (valor > limite + 0.001) {
    alert("⚠ O valor informado ultrapassa o saldo a receber (" + fmt(limite) + ").");
    valor = limite;
  }

  pagamentos.push({ forma: formaSelecionada.nome, id: formaSelecionada.id, valor });
  atualizarLista();
  bootstrap.Modal.getInstance(document.getElementById("modalValor")).hide();
}

    function removerPagamento(i) {
      pagamentos.splice(i, 1);
      atualizarLista();
    }

    function atualizarLista() {
      const lista = document.getElementById("listaPagamentos");
      lista.innerHTML = "";
      let totalPago = 0;
      pagamentos.forEach((p, i) => {
        totalPago += toNum(p.valor);
        const li = document.createElement("li");
        li.className = "list-group-item d-flex justify-content-between align-items-center";
        li.innerHTML = `
          <span>${p.forma}</span>
          <span>${fmt(p.valor)} <button class="btn btn-sm btn-outline-danger ms-2" onclick="removerPagamento(${i})">&times;</button></span>`;
        lista.appendChild(li);
      });
      const falta = Math.max(0, totalReceber - totalPago);
      document.getElementById("faltando").textContent = falta > 0.01
        ? "Faltando receber: " + fmt(falta)
        : "Pagamento parcial concluído.";
      document.getElementById("btnConcluir").disabled = pagamentos.length === 0;
    }

    async function concluirPagamento() {
      if (!cliente?.id) return alert("Cliente inválido.");
      if (!pagamentos.length) return alert("Adicione pelo menos uma forma de pagamento.");

      const payload = {
        clienteId: cliente.id,
        clienteNome: cliente.nome,
        titulos: saldoData.titulos,
        pagamentos: pagamentos,
        usuarioLogado: usuarioLogado || null
      };

      try {
        const res = await fetch("../api/crediario/baixar.php", {
          method: "POST",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          alert("Erro ao registrar baixa: " + (data.erro || JSON.stringify(data)));
          return;
        }

        localStorage.removeItem("clienteRecebimento");
        localStorage.removeItem("saldoCrediario");

        document.getElementById("mainContainer").innerHTML = `
          <div id="reciboBox">${data.reciboHtml}</div>
          <div id="acoesRecibo">
            <button class="btn btn-primary" onclick="imprimirRecibo()">🖨 Imprimir</button>
            <button class="btn btn-secondary" onclick="copiarRecibo()">📋 Copiar</button>
            <button class="btn btn-success" onclick="compartilharRecibo()">📤 Compartilhar</button>
            <button class="btn btn-danger" onclick="voltar()">⬅ Voltar</button>
          </div>
        `;

        gerarImagemRecibo();

      } catch (err) {
        console.error(err);
        alert("Erro inesperado: " + err);
      }
    }

    function gerarImagemRecibo() {
      const recibo = document.querySelector("#reciboBox");
      html2canvas(recibo, { scale: 2 }).then(canvas => {
        const img = document.createElement("img");
        img.src = canvas.toDataURL("image/png");
        recibo.innerHTML = "";
        recibo.appendChild(img);
      });
    }

    function imprimirRecibo() { window.print(); }

    function copiarRecibo() {
      const img = document.querySelector("#reciboBox img");
      fetch(img.src).then(r => r.blob()).then(blob => {
        navigator.clipboard.write([new ClipboardItem({'image/png': blob})]);
        alert("✅ Recibo copiado!");
      });
    }

    function compartilharRecibo() {
      const img = document.querySelector("#reciboBox img");
      fetch(img.src).then(r => r.blob()).then(blob => {
        const file = new File([blob], "recibo.png", { type: "image/png" });
        if (navigator.share) {
          navigator.share({ files: [file], title: "Recibo PDV Carmania" });
        } else alert("❌ Compartilhar não suportado neste dispositivo");
      });
    }

    atualizarLista();
  </script>
</body>
</html>
