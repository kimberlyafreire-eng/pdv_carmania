<?php
require_once __DIR__ . '/../session.php';

// ⚙️ Proteção simples (caso queira ativar depois)
// if (!isset($_SESSION['usuario']) || $_SESSION['nivel'] !== 'admin') {
//     die("Acesso restrito.");
// }

// 📦 Caminho do banco
$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
if (!file_exists($dbFile)) {
    die("❌ Banco de usuários não encontrado: $dbFile");
}
$db = new SQLite3($dbFile);

// 🧱 Garante a existência do campo estoque_padrao
$cols = $db->querySingle("PRAGMA table_info(usuarios)");
$hasEstoquePadrao = false;
$result = $db->query("PRAGMA table_info(usuarios)");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'estoque_padrao') {
        $hasEstoquePadrao = true;
        break;
    }
}
if (!$hasEstoquePadrao) {
    $db->exec("ALTER TABLE usuarios ADD COLUMN estoque_padrao TEXT DEFAULT ''");
}

// 🧾 Cadastrar novo usuário
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha   = trim($_POST['senha'] ?? '');
    $nome    = trim($_POST['nome'] ?? '');
    $nivel   = trim($_POST['nivel'] ?? 'vendedor');
    $estoque = trim($_POST['estoque_padrao'] ?? '');

    if ($usuario && $senha) {
        $stmt = $db->prepare("INSERT INTO usuarios (usuario, senha, nome, nivel, estoque_padrao) VALUES (?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $usuario, SQLITE3_TEXT);
        $stmt->bindValue(2, $senha, SQLITE3_TEXT);
        $stmt->bindValue(3, $nome, SQLITE3_TEXT);
        $stmt->bindValue(4, $nivel, SQLITE3_TEXT);
        $stmt->bindValue(5, $estoque, SQLITE3_TEXT);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>✅ Usuário <b>$usuario</b> cadastrado com sucesso!</div>";
        } else {
            $msg = "<div class='alert alert-danger'>❌ Erro ao cadastrar usuário (talvez já exista).</div>";
        }
    } else {
        $msg = "<div class='alert alert-warning'>⚠️ Preencha pelo menos o usuário e a senha.</div>";
    }
}

// 🔍 Busca todos os usuários
$result = $db->query("SELECT id, usuario, nome, nivel, estoque_padrao FROM usuarios ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Gerenciar Usuários - PDV Carmania</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background:#f8f9fa; }
    .container { max-width:700px; margin-top:40px; }
    .card { box-shadow:0 2px 6px rgba(0,0,0,0.1); border:none; }
  </style>
</head>
<body>
  <div class="container">
    <div class="card p-4">
      <h3 class="text-danger mb-3">👤 Cadastro de Usuários</h3>
      <?= $msg ?>
      <form method="POST" class="row g-2 mb-4">
        <div class="col-md-6">
          <label class="form-label">Usuário</label>
          <input type="text" name="usuario" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Senha</label>
          <input type="text" name="senha" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Nome Completo</label>
          <input type="text" name="nome" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Nível</label>
          <select name="nivel" class="form-select">
            <option value="vendedor">Vendedor</option>
            <option value="admin">Administrador</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Estoque Padrão</label>
          <input type="text" name="estoque_padrao" class="form-control" placeholder="Ex: 14888383522">
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <button class="btn btn-danger w-100">💾 Cadastrar Usuário</button>
        </div>
      </form>

      <h5 class="mb-2">👥 Usuários Cadastrados</h5>
      <table class="table table-bordered table-striped align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Usuário</th>
            <th>Nome</th>
            <th>Nível</th>
            <th>Estoque Padrão</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($u = $result->fetchArray(SQLITE3_ASSOC)): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['usuario']) ?></td>
            <td><?= htmlspecialchars($u['nome']) ?></td>
            <td><?= htmlspecialchars($u['nivel']) ?></td>
            <td><?= htmlspecialchars($u['estoque_padrao'] ?: '-') ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
