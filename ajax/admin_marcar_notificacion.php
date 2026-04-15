<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$notificacion_id = $_POST['notificacion_id'] ?? '';
if (!$notificacion_id) {
    echo json_encode(['success' => false, 'message' => 'ID de notificación requerido']);
    exit;
}

try {
    $db = getDB();

    $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones' AND COLUMN_NAME = 'destinatario_rol_id'");
    if (!$chk || !$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Sistema de notificaciones no configurado']);
        exit;
    }

    $stmt = $db->prepare("SELECT notificacion_id, solicitud_id FROM notificaciones WHERE notificacion_id = :nid AND destinatario_rol_id = 5");
    $stmt->execute([':nid' => $notificacion_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Notificación no encontrada']);
        exit;
    }

    $stmt = $db->prepare("UPDATE notificaciones SET notificacion_estado = 'leida' WHERE notificacion_id = :nid");
    $stmt->execute([':nid' => $notificacion_id]);

    $stmt = $db->prepare("
        SELECT s.solicitud_numero, s.ciudadano_identificacion 
        FROM notificaciones n 
        LEFT JOIN solicitudes s ON n.solicitud_id = s.solicitud_id
        WHERE n.notificacion_id = :nid
    ");
    $stmt->execute([':nid' => $notificacion_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'solicitud_numero' => $row['solicitud_numero'] ?? null,
        'ciudadano_identificacion' => $row['ciudadano_identificacion'] ?? null
    ]);
} catch (Exception $e) {
    error_log("Error admin_marcar_notificacion: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al marcar notificación']);
}
