<?php
session_start();

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
    actualizarUsuarioUltimaConexion($_SESSION['user_id']);
    eliminarSesionActivaUsuario($_SESSION['user_id']);
}

session_destroy();

header('Location: login.php');
exit();
