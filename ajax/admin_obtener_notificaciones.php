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
        echo json_encode(['success' => true, 'unread' => 0, 'notificaciones' => []]);
        exit;
    }

    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM notificaciones WHERE destinatario_rol_id = 5 AND notificacion_estado = 'no_leido'");
    $stmt->execute();
    $unread = (int)($stmt->fetch()['cnt'] ?? 0);

    $stmt = $db->prepare("
        SELECT 
            n.notificacion_id,
            n.notificacion_titulo,
            n.mensaje,
            n.notificacion_estado,
            DATE_FORMAT(n.notificacion_created_at, '%d/%m/%Y %h:%i %p') AS fecha,
            s.solicitud_id,
            s.solicitud_numero,
            s.ciudadano_identificacion,
            t.tramite_nombre
        FROM notificaciones n
        LEFT JOIN solicitudes s ON n.solicitud_id = s.solicitud_id
        LEFT JOIN tramite t ON s.tramite_id = t.tramite_id
        WHERE n.destinatario_rol_id = 5
        ORDER BY n.notificacion_created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'unread' => $unread, 'notificaciones' => $list], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error admin_obtener_notificaciones: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al obtener notificaciones']);
}
