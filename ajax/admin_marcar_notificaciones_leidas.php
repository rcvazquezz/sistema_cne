<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();

    $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones' AND COLUMN_NAME = 'destinatario_rol_id'");
    if (!$chk || !$chk->fetch()) {
        echo json_encode(['success' => true, 'actualizadas' => 0]);
        exit;
    }

    $stmt = $db->prepare("
        UPDATE notificaciones 
        SET notificacion_estado = 'leida' 
        WHERE destinatario_rol_id = 5 AND notificacion_estado = 'no_leido'
    ");
    $stmt->execute();
    $count = $stmt->rowCount();

    echo json_encode(['success' => true, 'actualizadas' => $count]);
} catch (Exception $e) {
    error_log("Error admin_marcar_notificaciones_leidas: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
