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
            co.coordinacion_nombre,
            COUNT(*) AS cantidad
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
        WHERE s.solicitud_estado = 'completada'
          AND COALESCE(DATE(s.solicitud_fecha_completada), DATE(s.solicitud_created_at)) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY co.coordinacion_id, co.coordinacion_nombre
        ORDER BY co.coordinacion_nombre ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $labels = [];
    $data = [];
    foreach ($rows as $r) {
        $labels[] = $r['coordinacion_nombre'];
        $data[] = (int)$r['cantidad'];
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'data' => $data
    ]);
} catch (Exception $e) {
    error_log("director_get_chart_area: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
