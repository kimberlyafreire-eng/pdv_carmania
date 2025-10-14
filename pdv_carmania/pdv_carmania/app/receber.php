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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    let clientesLista = [];
    let clienteSelecionado = null;

    // Carregar clientes do cache
    fetch("../cache/clientes-cache.json?nocache=" + Date.now())
      .then(res => res.json())
      .then(json => { clientesLista = json.data || json; });

    // Autocomplete
    const inputBusca = document.getElementById("clienteBusca");
    const listaEl = document.getElementById("listaClientes");

    inputBusca.addEventListener("input", function() {
      const busca = this.value.toLowerCase();
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
      if (!clienteSelecionado?.id) return alert("Selecione um cliente válido.");

      const resultDiv = document.getElementById("resultadoSaldo");
      resultDiv.innerHTML = "<div class='alert alert-info'>Consultando saldo...</div>";

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

        if (!data.titulos?.length) {
          resultDiv.innerHTML = `
            <div class="card-saldo text-center">
              <h5>${clienteSelecionado.nome}</h5>
              <p class="text-success mt-2 mb-1">Nenhum débito em aberto no crediário.</p>
            </div>`;
          return;
        }

        // Montar lista de títulos
        let titulosHtml = data.titulos.map(t => `
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <b>#${t.id}</b><br>
              <small>Vencimento: ${t.vencimento}</small>
            </div>
            <span class="fw-bold">R$ ${t.restante.toFixed(2)}</span>
          </li>`).join("");

        resultDiv.innerHTML = `
          <div class="card-saldo">
            <h5 class="text-danger">${clienteSelecionado.nome}</h5>
            <p class="saldo-valor mb-3">Saldo atual: R$ ${data.saldoAtual.toFixed(2)}</p>
            <ul class="list-group mb-3">${titulosHtml}</ul>
            <button class="btn btn-success w-100 btn-lg" onclick="pagarDebito()">💳 Pagar Débito</button>
          </div>`;

        // Guardar dados localmente
        localStorage.setItem("clienteRecebimento", JSON.stringify(clienteSelecionado));
        localStorage.setItem("saldoCrediario", JSON.stringify(data));

      } catch (err) {
        console.error(err);
        resultDiv.innerHTML = "<div class='alert alert-danger'>Erro de comunicação com o servidor.</div>";
      }
    });

    function pagarDebito() {
      window.location.href = "pagamento-crediario.php";
    }
  </script>
</body>
</html>
