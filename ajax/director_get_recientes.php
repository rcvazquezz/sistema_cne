<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 4) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            s.solicitud_numero,
            CONCAT(c.ciudadano_nombres, ' ', c.ciudadano_apellidos) AS ciudadano_nombre,
            co.coordinacion_nombre AS area_nombre,
            t.tramite_nombre AS tipo_tramite,
            CASE
                WHEN au.solicitud_id IS NOT NULL THEN 'redirigida'
                ELSE s.solicitud_estado
            END AS solicitud_estado,
            DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        JOIN tramite t ON s.tramite_id = t.tramite_id
        JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
        LEFT JOIN (
            SELECT a.solicitud_id FROM auditoria a
            JOIN (SELECT solicitud_id, MAX(auditoria_id) AS max_id FROM auditoria GROUP BY solicitud_id) last
              ON a.solicitud_id = last.solicitud_id AND a.auditoria_id = last.max_id
            WHERE a.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . "
               OR (a.accion_descripcion IS NOT NULL AND a.accion_descripcion LIKE '%redirigido%')
        ) au ON au.solicitud_id = s.solicitud_id
        ORDER BY s.solicitud_created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'recientes' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("director_get_recientes: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
