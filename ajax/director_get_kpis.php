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
    limpiarSesionesExpiradas();
    actualizarSesionUltimaActividad($_SESSION['user_id']);
    asegurarColumnaUltimaActividadUsuarios($db);

    // Total de Áreas
    $total_areas = (int)$db->query("SELECT COUNT(*) AS c FROM coordinacion")->fetch()['c'];

    // Total Usuarios (roles 1, 2, 3)
    $total_usuarios = (int)$db->query("SELECT COUNT(*) AS c FROM usuarios WHERE rol_id IN (1, 2, 3)")->fetch()['c'];

    // Catálogo de Trámites
    $total_tramites = (int)$db->query("SELECT COUNT(*) AS c FROM tramite")->fetch()['c'];

    // Conectados: usuarios operativos (mismo universo que "Usuarios") con actividad reciente
    $minAct = (int) minutosUmbralActivoMonitor();
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM usuarios u
        WHERE u.user_estado = 'activo'
          AND u.rol_id IN (1, 2, 3)
          AND u.ultima_actividad IS NOT NULL
          AND u.ultima_actividad > DATE_SUB(NOW(), INTERVAL {$minAct} MINUTE)
    ");
    $stmt->execute();
    $total_conectados = (int) ($stmt->fetch()['c'] ?? 0);

    echo json_encode([
        'success' => true,
        'total_areas' => $total_areas,
        'total_usuarios' => $total_usuarios,
        'total_tramites' => $total_tramites,
        'total_conectados' => $total_conectados
    ]);
} catch (Exception $e) {
    error_log("director_get_kpis: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
