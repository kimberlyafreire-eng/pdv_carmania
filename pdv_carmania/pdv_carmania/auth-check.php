<?php
require_once __DIR__ . '/session.php';
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}
?>