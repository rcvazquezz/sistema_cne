<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar autenticación y rol (2 = Funcionario)
if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
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
    $usuario = obtenerUsuario($_SESSION['user_id']);
    $coordinacion_id = $usuario['coordinacion_id'] ?? null;
    if (!$coordinacion_id) {
        echo json_encode(['success' => false, 'message' => 'Coordinación no definida']);
        exit;
    }

    // Verificar que la notificación pertenezca a la coordinación del usuario
    $stmt = $db->prepare("SELECT notificacion_id FROM notificaciones WHERE notificacion_id = :nid AND coordinacion_id = :cid");
    $stmt->execute([':nid' => $notificacion_id, ':cid' => $coordinacion_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Notificación no encontrada o no pertenece a su coordinación']);
        exit;
    }

    // Marcar como leída
    $stmt = $db->prepare("UPDATE notificaciones SET notificacion_estado = 'leida' WHERE notificacion_id = :nid");
    $stmt->execute([':nid' => $notificacion_id]);

    // Obtener número de solicitud para facilitar el filtrado
    $stmt = $db->prepare("
        SELECT s.solicitud_numero 
        FROM notificaciones n 
        LEFT JOIN solicitudes s ON n.solicitud_id = s.solicitud_id
        WHERE n.notificacion_id = :nid
    ");
    $stmt->execute([':nid' => $notificacion_id]);
    $row = $stmt->fetch();
    $numero = $row['solicitud_numero'] ?? null;

    echo json_encode(['success' => true, 'solicitud_numero' => $numero]);
} catch (Exception $e) {
    error_log("Error marcando notificación: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al marcar notificación']);
}
?>