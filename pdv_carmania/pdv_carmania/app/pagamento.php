<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Formas de Pagamento - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f8f9fa; }
    .navbar-custom { background-color: #dc3545; }
    .total-display {
      font-size: 2rem;
      font-weight: bold;
      text-align: center;
      margin: 20px 0;
    }
    .forma-card {
      background: white;
      border: 2px solid #dc3545;
      border-radius: 8px;
      padding: 20px;
      text-align: center;
      cursor: pointer;
      font-weight: bold;
      transition: 0.2s;
      user-select: none;
    }
    .forma-card:hover { background: #dc3545; color: white; }
    .resumo-pagamentos { margin-top: 20px; }
    .btn-remove { font-weight: bold; }
    #reciboContainer { text-align:center; margin-top:20px; }
    #reciboImg { max-width:220px; border:1px dashed #aaa; display:block; margin:0 auto; }
  </style>
</head>
<body>

  <!-- Barra superior -->
  <nav class="navbar navbar-dark navbar-custom px-3">
    <button class="btn btn-light" onclick="voltar()">⬅ Carrinho</button>
    <span class="navbar-text text-white fw-bold">Pagamento</span>
    <div></div>
  </nav>

  <div class="container py-3" id="telaPagamento">
    <div class="total-display">
      Total da Venda: <span id="valorTotal">R$ 0,00</span>
    </div>

    <h5>Selecione a forma de pagamento</h5>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Pix', 7697682)">Pix</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Crédito', 2941151)">Crédito</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Débito', 2941150)">Débito</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Crediário', 8126949)">Crediário</div></div>
      <div class="col-6 col-md-3"><div class="forma-card" onclick="selecionarForma('Dinheiro', 2009802)">💵 Dinheiro</div></div>
    </div>

    <!-- Resumo dos pagamentos -->
    <div class="resumo-pagamentos">
      <h5>Pagamentos adicionados</h5>
      <ul class="list-group" id="listaPagamentos"></ul>
      <div class="mt-3 fw-bold" id="faltando"></div>
    </div>

    <button class="btn btn-success w-100 btn-lg mt-3" id="btnConcluir" disabled onclick="concluirVenda()">
      ✅ Concluir Venda
    </button>
  </div>

  <!-- Container do recibo -->
  <div id="reciboContainer" class="container py-3" style="display:none;"></div>

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
    const fmt = (n) => "R$ " + (Number(n)||0).toFixed(2);
    const toNum = (v) => parseFloat((v ?? 0)) || 0;

    const dadosVenda = JSON.parse(localStorage.getItem("dadosVenda") || "null");
    let carrinho = [], clienteSelecionado = null, descontoValor = 0, descontoPercentual = 0, totalVenda = 0;

    if (dadosVenda) {
      carrinho = dadosVenda.carrinho || [];
      clienteSelecionado = dadosVenda.cliente || null;
      descontoValor = toNum(dadosVenda.descontoValor);
      descontoPercentual = toNum(dadosVenda.descontoPercentual);
      totalVenda = toNum(dadosVenda.total);
    }

    if (!totalVenda || totalVenda <= 0) {
      let bruto = carrinho.reduce((s, it) => s + (toNum(it.preco) * toNum(it.quantidade)), 0);
      totalVenda = descontoValor > 0 ? Math.max(0, bruto - descontoValor) :
                   descontoPercentual > 0 ? bruto * (1 - descontoPercentual / 100) : bruto;
    }

    document.getElementById("valorTotal").textContent = fmt(totalVenda);

    let pagamentos = [], formaSelecionada = null;

    function voltar(){ window.location.href = "carrinho.php"; }

    function selecionarForma(nome, id){
      formaSelecionada = { nome, id };
      const falta = calcularFaltando();
      if (falta <= 0) { alert("O total já foi quitado."); return; }
      document.getElementById("valorPagamento").value = falta.toFixed(2);
      new bootstrap.Modal(document.getElementById("modalValor")).show();
    }

    function adicionarPagamento(){
      if (!formaSelecionada) return;
      let valor = toNum(document.getElementById("valorPagamento").value);
      const falta = calcularFaltando();
      if (valor <= 0) return alert("Valor inválido.");
      if (valor > falta + 0.0001) valor = falta;
      pagamentos.push({ forma: formaSelecionada.nome, id: formaSelecionada.id, valor });
      atualizarLista();
      bootstrap.Modal.getInstance(document.getElementById("modalValor")).hide();
    }

    function removerPagamento(i){ pagamentos.splice(i,1); atualizarLista(); }

    function atualizarLista(){
      const lista = document.getElementById("listaPagamentos"); lista.innerHTML="";
      let totalPago=0;
      pagamentos.forEach((p,i)=>{
        totalPago+=toNum(p.valor);
        lista.innerHTML += `<li class="list-group-item d-flex justify-content-between align-items-center">
          <span>${p.forma}</span>
          <span>${fmt(p.valor)} <button class="btn btn-sm btn-outline-danger ms-2" onclick="removerPagamento(${i})">&times;</button></span>
        </li>`;
      });
      const falta = totalVenda-totalPago;
      document.getElementById("faltando").textContent = falta>0.001? "Falta pagar: "+fmt(falta):"Pagamento completo!";
      document.getElementById("btnConcluir").disabled = falta>0.001;
    }

    function calcularFaltando(){ return Math.max(0,totalVenda-pagamentos.reduce((s,p)=>s+toNum(p.valor),0)); }

    async function concluirVenda(){
      if (!clienteSelecionado?.id) return alert("Cliente não selecionado.");
      if (!carrinho.length) return alert("Carrinho vazio.");

        // 🔹 Recupera também o depósito selecionado salvo no localStorage
        const depositoSelecionado = JSON.parse(localStorage.getItem("depositoSelecionado") || "null");
        
        const payload = {
          clienteId: clienteSelecionado.id,
          clienteNome: clienteSelecionado.nome || "",
          descontoValor,
          descontoPercentual,
          carrinho,
          pagamentos,
          deposito: depositoSelecionado // ⚠️ essencial para lançar estoque
        };


      try{
        const res=await fetch('../api/salvar-venda.php',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify(payload)
        });
        const data=await res.json();
        if(!res.ok||!data.ok) return alert('Erro: '+(data.erro||JSON.stringify(data)));

        localStorage.clear();
        document.getElementById("telaPagamento").style.display = "none";
        document.getElementById("reciboContainer").style.display = "block";
        document.getElementById("reciboContainer").innerHTML = `<div id="recibo">${data.reciboHtml}</div>`;

        gerarImagemRecibo().then(()=>{
          document.getElementById("reciboContainer").innerHTML += `
            <div class="mt-3">
              <button class="btn btn-primary me-2" onclick="imprimirRecibo()">🖨 Imprimir</button>
              <button class="btn btn-secondary me-2" onclick="copiarRecibo()">📋 Copiar</button>
              <button class="btn btn-success me-2" onclick="compartilharRecibo()">📤 Compartilhar</button>
              <button class="btn btn-dark" onclick="window.location.href='index.php'">⬅ Nova Venda</button>
            </div>`;
        });
      }catch(err){ console.error(err); alert('Erro inesperado.'); }
    }

    async function gerarImagemRecibo(){
      const recibo=document.querySelector("#recibo");
      const canvas=await html2canvas(recibo);
      const img=document.createElement("img");
      img.id="reciboImg"; img.src=canvas.toDataURL("image/png");
      recibo.replaceWith(img);
    }

    function imprimirRecibo(){ window.print(); }

    function copiarRecibo(){
      const img=document.getElementById("reciboImg");
      fetch(img.src).then(r=>r.blob()).then(blob=>{
        navigator.clipboard.write([new ClipboardItem({'image/png':blob})]);
        alert("✅ Recibo copiado!");
      });
    }

    function compartilharRecibo(){
      const img=document.getElementById("reciboImg");
      fetch(img.src).then(r=>r.blob()).then(blob=>{
        const file=new File([blob],"recibo.png",{type:"image/png"});
        if(navigator.share){ navigator.share({files:[file],title:"Recibo PDV"}); }
        else alert("❌ Compartilhar não suportado neste dispositivo");
      });
    }

    atualizarLista();
  </script>
</body>
</html>
