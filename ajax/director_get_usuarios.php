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
    actualizarSesionUltimaActividad($_SESSION['user_id']);
    asegurarColumnaUserUltimaConexion($db);

    $buscar = trim($_GET['buscar'] ?? '');
    $minAct = (int)minutosUmbralActivoMonitor();

    $sql = "
        SELECT
            u.user_identificacion,
            CONCAT(u.user_nombres, ' ', u.user_apellidos) AS nombre_completo,
            r.rol_nombre,
            co.coordinacion_nombre AS area_nombre,
            u.user_estado,
            u.user_ultima_conexion,
            sa.sesion_ultima_actividad,
            CASE
                WHEN LOWER(TRIM(COALESCE(u.user_estado, ''))) IN ('suspendido', 'suspendida', 'suspended') THEN 'Suspendido'
                WHEN sa.sesion_ultima_actividad IS NOT NULL
                     AND sa.sesion_ultima_actividad >= DATE_SUB(NOW(), INTERVAL {$minAct} MINUTE) THEN 'En Línea'
                ELSE 'Desconectado'
            END AS estado_conexion
        FROM usuarios u
        JOIN roles r ON u.rol_id = r.rol_id
        LEFT JOIN coordinacion co ON u.coordinacion_id = co.coordinacion_id
        LEFT JOIN sesiones_activas sa ON sa.usuario_id = u.user_identificacion
        WHERE u.rol_id IN (1, 2, 3)
    ";
    $params = [];

    if ($buscar !== '') {
        $sql .= " AND (
            u.user_identificacion LIKE :buscar
            OR u.user_nombres LIKE :buscar2
            OR u.user_apellidos LIKE :buscar3
            OR r.rol_nombre LIKE :buscar4
            OR co.coordinacion_nombre LIKE :buscar5
        )";
        $b = '%' . $buscar . '%';
        $params[':buscar'] = $b;
        $params[':buscar2'] = $b;
        $params[':buscar3'] = $b;
        $params[':buscar4'] = $b;
        $params[':buscar5'] = $b;
    }

    $sql .= " ORDER BY co.coordinacion_nombre ASC, r.rol_nombre ASC, u.user_nombres ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['ultima_actividad'] = !empty($r['user_ultima_conexion'])
            ? date('d/m/Y h:i a', strtotime($r['user_ultima_conexion']))
            : '-';
        unset($r['sesion_ultima_actividad'], $r['user_ultima_conexion']);
    }
    unset($r);

    echo json_encode(['success' => true, 'usuarios' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("director_get_usuarios: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
