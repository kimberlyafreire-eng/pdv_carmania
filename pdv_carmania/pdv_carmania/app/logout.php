<?php
session_start();
$_SESSION = [];
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Saindo...</title>
</head>
<body>
<script>
// Limpa TUDO do navegador referente ao PDV
try {
  localStorage.removeItem('carrinho');
  localStorage.removeItem('clienteSelecionado');
  // se tiver outras chaves no futuro, pode usar localStorage.clear();
} catch(e) {}
window.location.href = 'login.php';
</script>
</body>
</html>
