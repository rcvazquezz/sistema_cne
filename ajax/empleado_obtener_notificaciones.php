<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
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

    // Contador de no leídas
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM notificaciones WHERE coordinacion_id = :cid AND usuario_id IS NULL AND notificacion_estado = 'no_leido'");
    $stmt->execute([':cid' => $coordinacion_id]);
    $unread = (int)($stmt->fetch()['cnt'] ?? 0);

    // Últimas notificaciones
    $stmt = $db->prepare("
        SELECT 
            n.notificacion_id,
            n.notificacion_titulo,
            n.mensaje,
            n.notificacion_estado,
            DATE_FORMAT(n.notificacion_created_at, '%d/%m/%Y %h:%i %p') AS fecha,
            s.solicitud_id,
            s.solicitud_numero,
            t.tramite_nombre
        FROM notificaciones n
        LEFT JOIN solicitudes s ON n.solicitud_id = s.solicitud_id
        LEFT JOIN tramite t ON s.tramite_id = t.tramite_id
        WHERE n.coordinacion_id = :cid AND n.usuario_id IS NULL
        ORDER BY n.notificacion_created_at DESC
        LIMIT 10
    ");
    $stmt->execute([':cid' => $coordinacion_id]);
    $list = $stmt->fetchAll();

    echo json_encode(['success' => true, 'unread' => $unread, 'notificaciones' => $list]);
} catch (Exception $e) {
    error_log("Error obteniendo notificaciones: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al obtener notificaciones']);
}
?> 
