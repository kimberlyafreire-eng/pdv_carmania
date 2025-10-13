<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = strtolower(trim($_POST['usuario']));
    $senha = trim($_POST['senha']);

    $db = new SQLite3(__DIR__ . '/../data/pdv_users.sqlite');
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE usuario = ?");
    $stmt->bindValue(1, $usuario, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if ($user && hash('sha256', $senha) === $user['senha']) {
        $_SESSION['usuario'] = $user['usuario'];
        $_SESSION['nome'] = $user['nome'];
        header("Location: index.php");
        exit();
    } else {
        $erro = "Usuário ou senha inválidos";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login PDV Carmania</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .login-form {
            width: 90%;
            max-width: 420px;
            min-width: 280px;
        }
        .login-form input {
            height: 50px;
            font-size: 1.1rem;
        }
        .login-form button {
            height: 50px;
            font-size: 1.1rem;
        }
        .logo {
            max-width: 180px;
            margin-bottom: 15px;
        }

        /* Ajustes para celular */
        @media (max-width: 576px) {
            .login-form {
                max-width: 100%;
            }
            .login-form input {
                height: 60px;
                font-size: 1.2rem;
            }
            .login-form button {
                height: 60px;
                font-size: 1.2rem;
            }
            .logo {
                max-width: 240px; /* dobra o tamanho no celular */
            }
        }
    </style>
</head>
<body class="d-flex justify-content-center align-items-center" style="height: 100vh;">
    <form method="POST" action="login.php" class="login-form p-4 bg-white border rounded shadow text-center">
        <img src="../imagens/logo-carmania.png" alt="Carmania" class="logo">
        <h4 class="mb-3">Acesso PDV</h4>
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger"><?= $erro ?></div>
        <?php endif; ?>
        <div class="mb-3">
            <input type="text" name="usuario" class="form-control form-control-lg" placeholder="Usuário" required>
        </div>
        <div class="mb-3">
            <input type="password" name="senha" class="form-control form-control-lg" placeholder="Senha" required>
        </div>
        <button type="submit" class="btn btn-danger w-100">Entrar</button>
    </form>
</body>
</html>
