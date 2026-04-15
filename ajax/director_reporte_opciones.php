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

    $areas = $db->query("
        SELECT coordinacion_id, coordinacion_nombre
        FROM coordinacion
        ORDER BY coordinacion_nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            u.user_identificacion,
            TRIM(CONCAT(COALESCE(u.user_nombres, ''), ' ', COALESCE(u.user_apellidos, ''))) AS nombre_completo
        FROM usuarios u
        INNER JOIN roles r ON u.rol_id = r.rol_id
        WHERE (r.rol_id = 1 OR r.rol_nombre IN ('Atención al Ciudadano', 'Oficina de Atención al Ciudadano'))
          AND u.user_estado = 'activo'
        ORDER BY u.user_nombres ASC, u.user_apellidos ASC
    ");
    $usuarios_atencion = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'areas' => $areas,
        'usuarios_atencion' => $usuarios_atencion,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('director_reporte_opciones: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
