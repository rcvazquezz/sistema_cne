<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 3) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();
    limpiarSesionesExpiradas();
    actualizarSesionUltimaActividad($_SESSION['user_id']);

    require_once __DIR__ . '/../includes/cne_admin_view_context.php';
    $usuario = cneObtenerUsuarioContextoSesion($_SESSION['user_id']);
    $cid = (int)($usuario['coordinacion_id'] ?? 0);
    if (!$cid) {
        echo json_encode(['success' => false, 'message' => 'Coordinación no definida']);
        exit;
    }

    asegurarColumnaUserUltimaConexion($db);

    $minAct = (int)minutosUmbralActivoMonitor();
    // Última actividad mostrada: siempre user_ultima_conexion. En línea: fila en sesiones_activas y actividad < umbral.
    $stmt = $db->prepare("
        SELECT u.user_identificacion, CONCAT(u.user_nombres,' ',u.user_apellidos) AS nombre_completo,
               r.rol_nombre,
               u.user_ultima_conexion,
               s.sesion_ultima_actividad,
               s.sesion_created_at,
               CASE
                   WHEN s.sesion_ultima_actividad IS NOT NULL
                        AND s.sesion_ultima_actividad >= DATE_SUB(NOW(), INTERVAL {$minAct} MINUTE)
                   THEN 'activo'
                   ELSE 'inactivo'
               END AS estado_tiempo_real
        FROM usuarios u
        LEFT JOIN sesiones_activas s ON s.usuario_id = u.user_identificacion
        LEFT JOIN roles r ON u.rol_id = r.rol_id
        WHERE u.coordinacion_id = :cid AND u.user_estado = 'activo'
    ");
    $stmt->execute([':cid' => $cid]);
    $usuarios = $stmt->fetchAll();

    $lista = [];
    $activos = 0;
    $inactivos = 0;
    foreach ($usuarios as $u) {
        $estado = (($u['estado_tiempo_real'] ?? '') === 'activo') ? 'activo' : 'inactivo';
        $lista[] = [
                'user_identificacion' => $u['user_identificacion'] ?? '',
                'nombre_completo' => $u['nombre_completo'],
                'rol' => $u['rol_nombre'] ?? '',
                'ultima_actividad' => !empty($u['user_ultima_conexion'])
                    ? date('d/m/Y h:i a', strtotime($u['user_ultima_conexion']))
                    : '-',
                'tiempo_conectado' => $u['sesion_created_at'] ? $u['sesion_created_at'] : '-',
                'estado' => $estado
        ];
        if ($estado === 'activo') {
            $activos++;
        } else {
            $inactivos++;
        }
    }
    $total = count($lista);

    echo json_encode([
        'success' => true,
        'total_conectados' => $total,
        'activos' => $activos,
        'inactivos' => $inactivos,
        'usuarios' => $lista
    ]);
} catch (Exception $e) {
    error_log("coordinador_conexiones: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
