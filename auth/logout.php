<?php
session_start();

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
    actualizarUsuarioUltimaConexion($_SESSION['user_id']);
    eliminarSesionActivaUsuario($_SESSION['user_id']);
}

session_destroy();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cerrando sesión…</title>
</head>
<body>
<script>
try { localStorage.removeItem('admin_active_tab'); } catch (e) {}
window.location.replace('login.php');
</script>
<noscript><p style="font-family:sans-serif;text-align:center;margin-top:2rem"><a href="login.php">Continuar al inicio de sesión</a></p></noscript>
</body>
</html>
<?php
exit();
