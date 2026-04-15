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

    $vista = $_GET['vista'] ?? 'funcionario';
    $area_id = $_GET['area_id'] ?? '';
    $fecha_desde = $_GET['fecha_desde'] ?? '';
    $fecha_hasta = $_GET['fecha_hasta'] ?? '';

    $rolFiltro = ($vista === 'atencion') ? 1 : (($vista === 'funcionario') ? 2 : null);

    $joinRecDir = cneSqlJoinRecibidoCaracasPorSolicitud($db);
    $caseEstDir = cneSqlCaseEstadoParaReporte('au.solicitud_id IS NOT NULL');
    $sql = "
        SELECT
            ({$caseEstDir}) AS estado_computado,
            COUNT(*) AS cantidad
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        JOIN usuarios u_creator ON s.created_by = u_creator.user_identificacion
        LEFT JOIN (
            SELECT a.solicitud_id FROM auditoria a
            JOIN (SELECT solicitud_id, MAX(auditoria_id) AS max_id FROM auditoria GROUP BY solicitud_id) last
              ON a.solicitud_id = last.solicitud_id AND a.auditoria_id = last.max_id
            WHERE a.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . "
               OR (a.accion_descripcion IS NOT NULL AND a.accion_descripcion LIKE '%redirigido%')
        ) au ON au.solicitud_id = s.solicitud_id
        {$joinRecDir}
        WHERE 1=1
    ";
    $params = [];

    if ($rolFiltro !== null) {
        $sql .= " AND u_creator.rol_id = :rol";
        $params[':rol'] = $rolFiltro;
    }
    if ($area_id !== '') {
        $sql .= " AND t.coordinacion_id = :area";
        $params[':area'] = (int)$area_id;
    }
    if ($fecha_desde !== '') {
        $sql .= " AND DATE(s.solicitud_created_at) >= :fd";
        $params[':fd'] = $fecha_desde;
    }
    if ($fecha_hasta !== '') {
        $sql .= " AND DATE(s.solicitud_created_at) <= :fh";
        $params[':fh'] = $fecha_hasta;
    }

    $sql .= " GROUP BY estado_computado";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $map = [
        'pendiente' => 0,
        'en_proceso' => 0,
        'completada' => 0,
        'vencida' => 0,
        'redirigida' => 0
    ];

    foreach ($rows as $r) {
        $estado = $r['estado_computado'] ?? '';
        $cantidad = (int)($r['cantidad'] ?? 0);
        if ($estado === 'pendiente') $map['pendiente'] += $cantidad;
        elseif (in_array($estado, ['en_revision', 'aprobada'], true)) $map['en_proceso'] += $cantidad;
        elseif ($estado === 'completada') $map['completada'] += $cantidad;
        elseif ($estado === 'vencida') $map['vencida'] += $cantidad;
        elseif ($estado === 'redirigida') $map['redirigida'] += $cantidad;
    }

    $labels = ['Pendiente', 'En Proceso', 'Completada', 'Vencida', 'Redirigida'];
    $data = [
        $map['pendiente'],
        $map['en_proceso'],
        $map['completada'],
        $map['vencida'],
        $map['redirigida']
    ];

    // Áreas para dropdown
    $areas = $db->query("SELECT coordinacion_id, coordinacion_nombre FROM coordinacion ORDER BY coordinacion_nombre ASC")->fetchAll();

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'data' => $data,
        'kpis' => $map,
        'areas' => $areas
    ]);
} catch (Exception $e) {
    error_log("director_get_metricas_estados: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
