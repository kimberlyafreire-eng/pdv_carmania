<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
$usuarioLogado = $_SESSION['usuario'] ?? null;

$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
$vendedorId = null;

if ($usuarioLogado) {
    try {
        if (file_exists($dbFile)) {
            $db = new SQLite3($dbFile);
            $stmt = $db->prepare("SELECT vendedor_id FROM usuarios WHERE LOWER(TRIM(usuario)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->bindValue(1, $usuarioLogado, SQLITE3_TEXT);
            $res = $stmt->execute();
            $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

            if ($row && isset($row['vendedor_id'])) {
                $valor = trim((string)$row['vendedor_id']);
                if ($valor !== '') {
                    $vendedorId = $valor;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Erro ao buscar vendedor_id do usuário: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Formas de Pagamento - PDV Carmania</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
    }

    html, body {
      height: 100%;
    }

    body {
      background: #f8f9fa;
      display: flex;
      flex-direction: column;
      min-height: 100%;
    }

    .navbar-custom {
      background-color: #dc3545;
    }

    #telaPagamento {
      flex: 1;
      display: flex;
      padding: 1.5rem 0;
    }

    .pagamento-conteudo {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }

    .resumo-card {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
      padding: 1.75rem;
    }

    .resumo-valores {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
    }

    .valor-destaque {
      border: 2px solid rgba(220, 53, 69, 0.2);
      border-radius: 14px;
      padding: 1rem 1.25rem;
      background: rgba(220, 53, 69, 0.06);
    }

    .valor-destaque.total {
      background: rgba(33, 37, 41, 0.05);
      border-color: rgba(33, 37, 41, 0.15);
    }

    .valor-destaque .label {
      display: block;
      font-size: 0.9rem;
      color: #6c757d;
      margin-bottom: 0.35rem;
    }

    .valor-destaque .valor {
      font-size: clamp(1.6rem, 4vw, 2.2rem);
      font-weight: 700;
      color: #212529;
      display: block;
    }

    .valor-destaque.faltando .valor {
      color: #dc3545;
    }

    .status-pagamento {
      margin-top: 0.35rem;
      font-size: 0.85rem;
      font-weight: 500;
      color: #495057;
    }

    .status-pagamento.completo {
      color: #198754;
    }

    .lista-pagamentos-container h5 {
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .lista-pagamentos-container .list-group-item {
      border-radius: 12px;
      border: 1px solid rgba(0, 0, 0, 0.06);
      margin-bottom: 0.5rem;
    }

    .lista-pagamentos-container .list-group-item:last-child {
      margin-bottom: 0;
    }

    .lista-vazia {
      font-size: 0.9rem;
      color: #6c757d;
      margin-top: 0.5rem;
    }

    .formas-wrapper {
      margin-top: auto;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .formas-wrapper h5 {
      font-weight: 600;
    }

    .formas-grid {
      display: grid;
      gap: 0.9rem;
      grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    }

    .forma-card {
      background: #ffffff;
      border: 2px solid #dc3545;
      border-radius: 14px;
      padding: 0;
      width: 100%;
      aspect-ratio: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      font-weight: 600;
      color: #dc3545;
      transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
      cursor: pointer;
      user-select: none;
    }

    .forma-card:hover,
    .forma-card:focus {
      background: #dc3545;
      color: #ffffff;
      transform: translateY(-2px);
      outline: none;
      box-shadow: 0 8px 18px rgba(220, 53, 69, 0.25);
    }

    .forma-card:active {
      transform: scale(0.97);
    }

    #reciboContainer {
      display: none;
      width: 100%;
      padding: 2.5rem 1rem 3rem;
      justify-content: center;
    }

    #reciboContainer.ativo {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1.5rem;
    }

    .recibo-area {
      background: #ffffff;
      border-radius: 18px;
      box-shadow: 0 16px 35px rgba(0, 0, 0, 0.12);
      padding: 1.5rem;
      display: flex;
      justify-content: center;
      width: min(100%, 380px);
    }

    #reciboImg {
      width: 100%;
      height: auto;
      border-radius: 12px;
      border: 1px dashed #adb5bd;
      background: #f8f9fa;
    }

    .recibo-acoes {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      justify-content: center;
    }

    @media (max-width: 576px) {
      .pagamento-conteudo {
        padding-inline: 0.75rem;
      }

      .resumo-card {
        padding: 1.25rem;
      }

      .formas-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .recibo-area {
        width: min(100%, 320px);
        padding: 1rem;
      }
    }
  </style>
</head>
<body>

  <!-- Barra superior -->
  <nav class="navbar navbar-dark navbar-custom px-3">
    <button class="btn btn-light" onclick="voltar()">⬅ Carrinho</button>
    <span class="navbar-text text-white fw-bold">Pagamento</span>
    <div></div>
  </nav>

  <main class="container-fluid" id="telaPagamento">
    <div class="container pagamento-conteudo">
      <section class="resumo-card">
        <div class="resumo-valores">
          <div class="valor-destaque total">
            <span class="label">Total da venda</span>
            <span class="valor" id="valorTotal">R$ 0,00</span>
          </div>
          <div class="valor-destaque faltando">
            <span class="label">Valor pendente</span>
            <span class="valor" id="valorFaltante">R$ 0,00</span>
            <div class="status-pagamento" id="statusPagamento">Faltando calcular…</div>
          </div>
        </div>
        <div class="lista-pagamentos-container mt-4">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Pagamentos adicionados</h5>
            <span class="badge text-bg-light" id="qtdPagamentos">0</span>
          </div>
          <ul class="list-group mt-3" id="listaPagamentos"></ul>
          <p class="lista-vazia" id="listaVazia">Nenhuma forma de pagamento adicionada até o momento.</p>
        </div>
      </section>

      <section class="formas-wrapper">
        <div>
          <h5>Selecione a forma de pagamento</h5>
          <div class="formas-grid">
            <button class="forma-card" type="button" onclick="selecionarForma('Pix', 7697682)">Pix</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Crédito', 2941151)">Crédito</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Débito', 2941150)">Débito</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Crediário', 8126949)">Crediário</button>
            <button class="forma-card" type="button" onclick="selecionarForma('Dinheiro', 2009802)">💵 Dinheiro</button>
          </div>
        </div>
        <button class="btn btn-success w-100 btn-lg" id="btnConcluir" disabled onclick="concluirVenda()">
          ✅ Concluir Venda
        </button>
      </section>
    </div>
  </main>

  <div id="reciboContainer" class="container"></div>

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
    window.VENDEDOR_ID = <?= json_encode($vendedorId) ?>;
  </script>
  <script>
    const fmt = (n) => "R$ " + (Number(n)||0).toFixed(2);
    const toNum = (v) => parseFloat((v ?? 0)) || 0;

    const vendedorId = window.VENDEDOR_ID || null;
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
      const lista = document.getElementById("listaPagamentos");
      const badgeQtd = document.getElementById("qtdPagamentos");
      const avisoLista = document.getElementById("listaVazia");
      const statusPagamento = document.getElementById("statusPagamento");
      const valorFaltante = document.getElementById("valorFaltante");

      lista.innerHTML="";
      let totalPago=0;
      pagamentos.forEach((p,i)=>{
        totalPago+=toNum(p.valor);
        lista.innerHTML += `<li class="list-group-item d-flex justify-content-between align-items-center">
          <span class="fw-semibold">${p.forma}</span>
          <span>${fmt(p.valor)} <button class="btn btn-sm btn-outline-danger ms-2" onclick="removerPagamento(${i})">&times;</button></span>
        </li>`;
      });

      const falta = Math.max(0, totalVenda-totalPago);
      valorFaltante.textContent = fmt(falta);

      if(pagamentos.length){
        avisoLista.classList.add("d-none");
      } else {
        avisoLista.classList.remove("d-none");
      }

      badgeQtd.textContent = pagamentos.length;

      if (falta > 0.01) {
        statusPagamento.textContent = `Falta pagar ${fmt(falta)}`;
        statusPagamento.classList.remove("completo");
      } else {
        statusPagamento.textContent = "Pagamento completo!";
        statusPagamento.classList.add("completo");
      }

      document.getElementById("btnConcluir").disabled = falta>0.001;
    }

    function calcularFaltando(){ return Math.max(0,totalVenda-pagamentos.reduce((s,p)=>s+toNum(p.valor),0)); }

    async function concluirVenda(){
      if (!clienteSelecionado?.id) return alert("Cliente não selecionado.");
      if (!carrinho.length) return alert("Carrinho vazio.");

        // 🔹 Recupera também o depósito selecionado salvo no localStorage
        const usuarioLogado = window.USUARIO_LOGADO || null;
        const LEGACY_DEPOSITO_KEY = "depositoSelecionado";
        const depositoStorageKey = usuarioLogado ? `${LEGACY_DEPOSITO_KEY}:${usuarioLogado}` : LEGACY_DEPOSITO_KEY;

        function recuperarDeposito(chave) {
          const bruto = localStorage.getItem(chave);
          if (!bruto) return null;
          try {
            const parsed = JSON.parse(bruto);
            if (parsed && parsed.id) return parsed;
          } catch (err) {
            console.warn("Cache de depósito inválido ao concluir venda, ignorando.", err);
          }
          localStorage.removeItem(chave);
          return null;
        }

        let depositoSelecionado = recuperarDeposito(depositoStorageKey);
        if (!depositoSelecionado && depositoStorageKey !== LEGACY_DEPOSITO_KEY) {
          depositoSelecionado = recuperarDeposito(LEGACY_DEPOSITO_KEY);
          localStorage.removeItem(LEGACY_DEPOSITO_KEY);
        }

        const payload = {
          clienteId: clienteSelecionado.id,
          clienteNome: clienteSelecionado.nome || "",
          descontoValor,
          descontoPercentual,
          carrinho,
          pagamentos,
          vendedorId: vendedorId || null,
          usuarioLogado: usuarioLogado || null,
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
        const reciboContainer = document.getElementById("reciboContainer");
        reciboContainer.classList.add("ativo");
        reciboContainer.innerHTML = `<div class="recibo-area"><div id="recibo">${data.reciboHtml}</div></div>`;

        gerarImagemRecibo().then(()=>{
          reciboContainer.innerHTML += `
            <div class="recibo-acoes">
              <button class="btn btn-primary" onclick="imprimirRecibo()">🖨 Imprimir</button>
              <button class="btn btn-secondary" onclick="copiarRecibo()">📋 Copiar</button>
              <button class="btn btn-success" onclick="compartilharRecibo()">📤 Compartilhar</button>
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
