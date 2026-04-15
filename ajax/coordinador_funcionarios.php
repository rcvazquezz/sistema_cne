<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 3) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();
    require_once __DIR__ . '/../includes/cne_admin_view_context.php';
    $usuario = cneObtenerUsuarioContextoSesion($_SESSION['user_id']);
    $cid = (int)($usuario['coordinacion_id'] ?? 0);
    if (!$cid) {
        echo json_encode(['success' => false, 'funcionarios' => []]);
        exit;
    }

    $stmt = $db->prepare("
        SELECT u.user_identificacion as id, CONCAT(u.user_nombres,' ',u.user_apellidos) as nombre
        FROM usuarios u
        WHERE u.coordinacion_id = :cid AND u.user_estado = 'activo' AND u.rol_id = 2
        ORDER BY u.user_nombres, u.user_apellidos
    ");
    $stmt->execute([':cid' => $cid]);
    $funcionarios = $stmt->fetchAll();
    echo json_encode(['success' => true, 'funcionarios' => $funcionarios]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'funcionarios' => []]);
}
