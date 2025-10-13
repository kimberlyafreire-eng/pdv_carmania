<?php
require 'auth-check.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>PDV Carmania</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Bem-vindo, <?php echo htmlspecialchars($_SESSION['nome']); ?>!</h3>
            <a href="logout.php" class="btn btn-outline-danger">Sair</a>
        </div>
        <p>Aqui vai a interface do PDV (produtos, carrinho, etc.)</p>
    </div>
</body>
</html>