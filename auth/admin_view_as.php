<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard_admin.php');
    exit;
}

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['is_admin_viewing'])) {
    header('Location: ../dashboard_admin.php');
    exit;
}

$roleId = (int) ($_POST['role_id'] ?? 0);
$coordId = isset($_POST['coordinacion_id']) ? (int) $_POST['coordinacion_id'] : 0;

if ($roleId < 1 || $roleId > 4) {
    header('Location: ../dashboard_admin.php');
    exit;
}

if (in_array($roleId, [2, 3], true) && $coordId < 1) {
    header('Location: ../dashboard_admin.php');
    exit;
}

$db = getDB();

$_SESSION['admin_view_backup'] = [
    'rol_id' => (int) $_SESSION['rol_id'],
    'rol' => $_SESSION['rol'] ?? '',
    'coordinacion_id' => $_SESSION['coordinacion_id'] ?? null,
    'acoordinacion_id' => $_SESSION['acoordinacion_id'] ?? null,
    'coordinacion_nombre' => $_SESSION['coordinacion_nombre'] ?? '',
];

$_SESSION['is_admin_viewing'] = true;
$_SESSION['admin_view_target_rol_id'] = $roleId;

$stmt = $db->prepare('SELECT rol_nombre FROM roles WHERE rol_id = ?');
$stmt->execute([$roleId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$_SESSION['rol_id'] = $roleId;
$_SESSION['rol'] = $row['rol_nombre'] ?? '';

$_SESSION['admin_view_coordinacion_id'] = null;
$_SESSION['admin_view_coordinacion_nombre'] = null;

if ($roleId === 2 || $roleId === 3) {
    $st = $db->prepare("SELECT coordinacion_id, coordinacion_nombre FROM coordinacion WHERE coordinacion_id = ? AND coordinacion_estado = 'activo'");
    $st->execute([$coordId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        unset($_SESSION['is_admin_viewing'], $_SESSION['admin_view_backup'], $_SESSION['admin_view_target_rol_id']);
        header('Location: ../dashboard_admin.php');
        exit;
    }
    $cid = (int) $c['coordinacion_id'];
    $_SESSION['coordinacion_id'] = $cid;
    $_SESSION['acoordinacion_id'] = $cid;
    $_SESSION['coordinacion_nombre'] = $c['coordinacion_nombre'];
    $_SESSION['admin_view_coordinacion_id'] = $cid;
    $_SESSION['admin_view_coordinacion_nombre'] = $c['coordinacion_nombre'];
} elseif ($roleId === 1) {
    $st = $db->query("SELECT coordinacion_id, coordinacion_nombre FROM coordinacion WHERE coordinacion_estado = 'activo' AND coordinacion_nombre LIKE '%Atención al Ciudadano%' ORDER BY coordinacion_id ASC LIMIT 1");
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c) {
        $cid = (int) $c['coordinacion_id'];
        $_SESSION['coordinacion_id'] = $cid;
        $_SESSION['acoordinacion_id'] = $cid;
        $_SESSION['coordinacion_nombre'] = $c['coordinacion_nombre'];
        $_SESSION['admin_view_coordinacion_id'] = $cid;
        $_SESSION['admin_view_coordinacion_nombre'] = $c['coordinacion_nombre'];
    }
} else {
    $_SESSION['coordinacion_id'] = null;
    $_SESSION['acoordinacion_id'] = null;
    $_SESSION['coordinacion_nombre'] = '';
}

$redirects = [
    1 => 'dashboard_entrada.php',
    2 => 'dashboard_empleado.php',
    3 => 'dashboard_coordinador.php',
    4 => 'dashboard_director.php',
];

header('Location: ../' . $redirects[$roleId]);
exit;
