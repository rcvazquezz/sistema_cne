<?php
session_start();

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin_viewing']) || empty($_SESSION['admin_view_backup']) || !is_array($_SESSION['admin_view_backup'])) {
    header('Location: ../dashboard_admin.php');
    exit;
}

$b = $_SESSION['admin_view_backup'];

$_SESSION['rol_id'] = (int) ($b['rol_id'] ?? 5);
$_SESSION['rol'] = $b['rol'] ?? 'Admin';
$_SESSION['coordinacion_id'] = $b['coordinacion_id'] ?? null;
$_SESSION['acoordinacion_id'] = $b['acoordinacion_id'] ?? null;
$_SESSION['coordinacion_nombre'] = $b['coordinacion_nombre'] ?? '';

unset(
    $_SESSION['is_admin_viewing'],
    $_SESSION['admin_view_backup'],
    $_SESSION['admin_view_target_rol_id'],
    $_SESSION['admin_view_coordinacion_id'],
    $_SESSION['admin_view_coordinacion_nombre']
);

header('Location: ../dashboard_admin.php');
exit;
