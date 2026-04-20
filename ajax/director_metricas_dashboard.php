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

    $fecha_desde = trim($_GET['fecha_desde'] ?? '');
    $fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
    $coordinacion_id = trim($_GET['coordinacion_id'] ?? '');
    $tramite_id = trim($_GET['tramite_id'] ?? '');

    $fd = $fecha_desde ?: date('Y-m-d', strtotime('-30 days'));
    $fh = $fecha_hasta ?: date('Y-m-d');

    $where = " WHERE DATE(s.solicitud_created_at) >= :fd AND DATE(s.solicitud_created_at) <= :fh";
    $params = [':fd' => $fd, ':fh' => $fh];

    if ($coordinacion_id !== '') {
        $where .= " AND t.coordinacion_id = :coord";
        $params[':coord'] = (int)$coordinacion_id;
    }
    if ($tramite_id !== '') {
        $where .= " AND s.tramite_id = :tramite";
        $params[':tramite'] = (int)$tramite_id;
    }

    $baseSql = "FROM solicitudes s JOIN tramite t ON s.tramite_id = t.tramite_id $where";

    // KPI 1: Total Solicitudes Hoy
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM solicitudes s JOIN tramite t ON s.tramite_id = t.tramite_id WHERE DATE(s.solicitud_created_at) = CURDATE()" .
        ($coordinacion_id ? " AND t.coordinacion_id = :coord1" : "") .
        ($tramite_id ? " AND s.tramite_id = :tramite1" : ""));
    $p1 = [];
    if ($coordinacion_id) $p1[':coord1'] = (int)$coordinacion_id;
    if ($tramite_id) $p1[':tramite1'] = (int)$tramite_id;
    $stmt->execute($p1);
    $total_hoy = (int)$stmt->fetchColumn();

    // KPI 2: Trámites Pendientes (en el rango de fechas, estados pendiente + en_revision)
    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado IN ('pendiente', 'en_revision', 'aprobada')");
    $stmt->execute($params);
    $pendientes = (int)$stmt->fetchColumn();

    // KPI 3: % Eficiencia Global (completadas / total en rango * 100)
    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql");
    $stmt->execute($params);
    $total_rango = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado = 'completada'");
    $stmt->execute($params);
    $completadas = (int)$stmt->fetchColumn();

    $eficiencia = ($total_rango > 0) ? round(($completadas / $total_rango) * 100, 1) : 0;

    // KPI 4: Tiempo Promedio de Respuesta (días entre creación y completada)
    $stmt = $db->prepare("
        SELECT AVG(DATEDIFF(s.solicitud_fecha_completada, s.solicitud_created_at)) as avg_dias
        $baseSql AND s.solicitud_estado = 'completada' AND s.solicitud_fecha_completada IS NOT NULL
    ");
    $stmt->execute($params);
    $avgRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $tiempo_promedio = $avgRow && $avgRow['avg_dias'] !== null ? round((float)$avgRow['avg_dias'], 1) : 0;

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado = 'invalidada'");
    $stmt->execute($params);
    $tramites_invalidados = (int) $stmt->fetchColumn();

    $kpis = [
        'total_hoy' => $total_hoy,
        'pendientes' => $pendientes,
        'eficiencia' => $eficiencia,
        'tiempo_promedio' => $tiempo_promedio,
        'tramites_invalidados' => $tramites_invalidados,
    ];

    // Embudo: Entrada -> En Proceso -> Firma -> Listos para Entrega
    $stmt = $db->prepare("
        SELECT
            CASE
                WHEN s.solicitud_estado = 'pendiente' THEN 'entrada'
                WHEN s.solicitud_estado = 'en_revision' THEN 'en_revision'
                WHEN s.solicitud_estado = 'aprobada' THEN 'firma'
                WHEN s.solicitud_estado = 'completada' THEN 'listos'
                ELSE 'otros'
            END AS etapa,
            COUNT(*) as cantidad
        $baseSql AND s.solicitud_estado IN ('pendiente', 'en_revision', 'aprobada', 'completada')
        GROUP BY etapa
    ");
    $stmt->execute($params);
    $funnelRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $funnelMap = ['entrada' => 0, 'en_revision' => 0, 'firma' => 0, 'listos' => 0];
    foreach ($funnelRaw as $r) {
        if (isset($funnelMap[$r['etapa']])) {
            $funnelMap[$r['etapa']] = (int)$r['cantidad'];
        }
    }
    $funnel = [
        'labels' => ['Entrada', 'En Proceso', 'Firma', 'Listos para Entrega'],
        'data' => [$funnelMap['entrada'], $funnelMap['en_revision'], $funnelMap['firma'], $funnelMap['listos']]
    ];

    // Doughnut: Distribución por estado (incluye gestión Caracas)
    $stmt = $db->prepare("
        SELECT
            CASE
                WHEN s.solicitud_estado = 'invalidada' THEN 'invalidada'
                WHEN env.last_env_id IS NOT NULL AND (rec.last_rec_id IS NULL OR env.last_env_id > rec.last_rec_id) THEN 'enviada_caracas'
                WHEN rec.last_rec_id IS NOT NULL AND (env.last_env_id IS NULL OR rec.last_rec_id > env.last_env_id) THEN 'recibida_caracas'
                ELSE s.solicitud_estado
            END AS estado_computado,
            COUNT(*) as cantidad
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN (
            SELECT a.solicitud_id, MAX(a.auditoria_id) AS last_env_id
            FROM auditoria a
            WHERE a.accion_codigo = 'ENVIADO_CARACAS'
            GROUP BY a.solicitud_id
        ) env ON env.solicitud_id = s.solicitud_id
        LEFT JOIN (
            SELECT a.solicitud_id, MAX(a.auditoria_id) AS last_rec_id
            FROM auditoria a
            WHERE a.accion_codigo = 'RECIBIDO_CARACAS'
            GROUP BY a.solicitud_id
        ) rec ON rec.solicitud_id = s.solicitud_id
        $where
        GROUP BY estado_computado
    ");
    $stmt->execute($params);
    $estadosRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $estadosMap = [
        'pendiente' => 0, 'en_revision' => 0, 'aprobada' => 0, 'completada' => 0,
        'redirigida' => 0, 'rechazada' => 0, 'vencida' => 0, 'invalidada' => 0,
        'enviada_caracas' => 0, 'recibida_caracas' => 0
    ];
    foreach ($estadosRaw as $r) {
        $est = $r['estado_computado'] ?? '';
        if (isset($estadosMap[$est])) $estadosMap[$est] = (int)$r['cantidad'];
    }
    $ordenLabels = ['Pendiente', 'En Proceso', 'Completado', 'Redirigida', 'Invalidadas', 'Vencida', 'Enviada a Caracas', 'Recibida de Caracas'];
    $ordenData = [
        $estadosMap['pendiente'],
        $estadosMap['en_revision'] + $estadosMap['aprobada'],
        $estadosMap['completada'],
        $estadosMap['redirigida'],
        $estadosMap['invalidada'],
        $estadosMap['vencida'] + $estadosMap['rechazada'],
        $estadosMap['enviada_caracas'],
        $estadosMap['recibida_caracas']
    ];
    $chart_estados = [
        'labels' => $ordenLabels,
        'data' => $ordenData,
        'colors' => ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#dc2626', '#f97316', '#06b6d4', '#14b8a6']
    ];

    // Barras: Rendimiento por Coordinación (solicitudes en el período)
    $joinExtra = " AND DATE(s.solicitud_created_at) >= :fd AND DATE(s.solicitud_created_at) <= :fh";
    if ($tramite_id) $joinExtra .= " AND s.tramite_id = :tramite";
    $whereCoord = "";
    if ($coordinacion_id) $whereCoord .= " AND c.coordinacion_id = :coord";
    $stmt = $db->prepare("
        SELECT c.coordinacion_nombre, COUNT(s.solicitud_id) as total
        FROM coordinacion c
        LEFT JOIN tramite t ON t.coordinacion_id = c.coordinacion_id
        LEFT JOIN solicitudes s ON s.tramite_id = t.tramite_id $joinExtra
        WHERE 1=1 $whereCoord
        GROUP BY c.coordinacion_id, c.coordinacion_nombre
        HAVING total > 0
        ORDER BY total DESC
    ");
    $paramsBar = [':fd' => $fd, ':fh' => $fh];
    if ($coordinacion_id) $paramsBar[':coord'] = (int)$coordinacion_id;
    if ($tramite_id) $paramsBar[':tramite'] = (int)$tramite_id;
    $stmt->execute($paramsBar);
    $coords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bar_coordinaciones = [
        'labels' => array_map(fn($x) => $x['coordinacion_nombre'], $coords),
        'data' => array_map(fn($x) => (int)$x['total'], $coords)
    ];

    // Rendimiento por Usuario (Top 10): agrupar Oficina de Atención al Ciudadano; individual para el resto
    $stmt = $db->prepare("
        SELECT
            CASE
                WHEN coord.coordinacion_nombre LIKE '%Oficina de Atención%' OR coord.coordinacion_nombre LIKE '%Atención al Ciudadano%'
                THEN 'Oficina de Atención al Ciudadano'
                ELSE COALESCE(CONCAT(u.user_nombres, ' ', u.user_apellidos), 'Sin asignar')
            END AS identificador,
            COUNT(*) AS total
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN usuarios u ON COALESCE(s.empleado_asignado_id, s.created_by) = u.user_identificacion
        LEFT JOIN coordinacion coord ON u.coordinacion_id = coord.coordinacion_id
        $where
        GROUP BY
            CASE
                WHEN coord.coordinacion_nombre LIKE '%Oficina de Atención%' OR coord.coordinacion_nombre LIKE '%Atención al Ciudadano%'
                THEN 'Oficina de Atención al Ciudadano'
                ELSE COALESCE(CONCAT(u.user_nombres, ' ', u.user_apellidos), 'Sin asignar')
            END
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $rendimientoUsuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $chart_rendimiento_usuarios = [
        'labels' => array_map(fn($x) => $x['identificador'], $rendimientoUsuarios),
        'data' => array_map(fn($x) => (int)$x['total'], $rendimientoUsuarios)
    ];

    // Coordinaciones para filtro (excluir Oficina de Atención al Ciudadano - solo coordinaciones técnicas)
    try {
        $coordinaciones = $db->query("
            SELECT coordinacion_id, coordinacion_nombre 
            FROM coordinacion 
            WHERE coordinacion_nombre NOT LIKE '%Oficina de Atención%' 
            AND coordinacion_nombre NOT LIKE '%Atención al Ciudadano%'
            ORDER BY coordinacion_nombre
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $coordinaciones = [];
    }
    $resp = [
        'success' => true,
        'kpis' => $kpis,
        'funnel' => $funnel,
        'chart_estados' => $chart_estados,
        'bar_coordinaciones' => $bar_coordinaciones,
        'chart_rendimiento_usuarios' => $chart_rendimiento_usuarios,
        'coordinaciones' => $coordinaciones
    ];
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("director_metricas_dashboard: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
