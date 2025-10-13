<?php
session_start();

// ⚙️ Caminho do banco
$dbFile = __DIR__ . '/../data/pdv_users.sqlite';
if (!file_exists($dbFile)) {
    die("❌ Banco de usuários não encontrado: $dbFile");
}
$db = new SQLite3($dbFile);

// 🧱 Garante que tabela exista
$db->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT UNIQUE NOT NULL,
    senha TEXT NOT NULL,
    nome TEXT,
    estoque_padrao TEXT,
    vendedor_id TEXT
)");

// 🔍 Garante coluna de vendedor para bases antigas
$colunaVendedorExiste = false;
$infoColunas = $db->query("PRAGMA table_info(usuarios)");
while ($infoColunas && ($coluna = $infoColunas->fetchArray(SQLITE3_ASSOC))) {
    if (($coluna['name'] ?? '') === 'vendedor_id') {
        $colunaVendedorExiste = true;
        break;
    }
}
if (!$colunaVendedorExiste) {
    $db->exec("ALTER TABLE usuarios ADD COLUMN vendedor_id TEXT");
}

// ⚙️ Ações de atualização
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id      = (int)$_POST['id'];
    $usuario = trim($_POST['usuario'] ?? '');
    $senha   = trim($_POST['senha'] ?? '');
    $nome    = trim($_POST['nome'] ?? '');
    $estoque = trim($_POST['estoque_padrao'] ?? '');
    $vendedorId = trim($_POST['vendedor_id'] ?? '');

    if ($usuario && $senha) {
        $stmt = $db->prepare("UPDATE usuarios
                              SET usuario = ?, senha = ?, nome = ?, estoque_padrao = ?, vendedor_id = ?
                              WHERE id = ?");
        $stmt->bindValue(1, $usuario, SQLITE3_TEXT);
        $stmt->bindValue(2, $senha, SQLITE3_TEXT);
        $stmt->bindValue(3, $nome, SQLITE3_TEXT);
        $stmt->bindValue(4, $estoque, SQLITE3_TEXT);
        if ($vendedorId === '') {
            $stmt->bindValue(5, null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(5, $vendedorId, SQLITE3_TEXT);
        }
        $stmt->bindValue(6, $id, SQLITE3_INTEGER);
        $stmt->execute();

        $msg = "<div class='alert alert-success'>✅ Usuário atualizado com sucesso!</div>";
    } else {
        $msg = "<div class='alert alert-warning'>⚠️ Usuário e senha são obrigatórios.</div>";
    }
}

// 🔍 Se for edição, busca o usuário
$editando = null;
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $editando = $result->fetchArray(SQLITE3_ASSOC);
}

// 🔍 Lista de todos
$usuarios = $db->query("SELECT * FROM usuarios ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Editar Usuários - PDV Carmania</title>
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
    <h3 class="text-danger mb-3">✏️ Editar Usuários</h3>
    <?= $msg ?>

    <?php if ($editando): ?>
      <form method="POST" class="mb-4">
        <input type="hidden" name="id" value="<?= $editando['id'] ?>">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Usuário</label>
            <input type="text" name="usuario" class="form-control" required value="<?= htmlspecialchars($editando['usuario']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Senha</label>
            <input type="text" name="senha" class="form-control" required value="<?= htmlspecialchars($editando['senha']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Nome</label>
            <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editando['nome']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Estoque Padrão</label>
            <input type="text" name="estoque_padrao" class="form-control" value="<?= htmlspecialchars($editando['estoque_padrao']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">ID Vendedor (Bling)</label>
            <input type="text" name="vendedor_id" class="form-control" value="<?= htmlspecialchars($editando['vendedor_id'] ?? '') ?>">
          </div>
          <div class="col-12 d-flex gap-2 mt-3">
            <button class="btn btn-danger w-50">💾 Salvar Alterações</button>
            <a href="editar-usuario.php" class="btn btn-secondary w-50">Cancelar</a>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <h5 class="mb-2">👥 Usuários Cadastrados</h5>
    <table class="table table-bordered table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Usuário</th>
          <th>Nome</th>
          <th>Estoque Padrão</th>
          <th>ID Vendedor</th>
          <th style="width:100px">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php while ($u = $usuarios->fetchArray(SQLITE3_ASSOC)): ?>
        <tr>
          <td><?= $u['id'] ?></td>
          <td><?= htmlspecialchars($u['usuario']) ?></td>
          <td><?= htmlspecialchars($u['nome']) ?></td>
          <td><?= htmlspecialchars($u['estoque_padrao'] ?: '-') ?></td>
          <td><?= htmlspecialchars(($u['vendedor_id'] ?? '') !== '' ? $u['vendedor_id'] : '-') ?></td>
          <td class="text-center">
            <a href="?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger">✏️ Editar</a>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
