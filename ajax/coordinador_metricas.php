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
    require_once __DIR__ . '/../includes/cne_admin_view_context.php';
    $usuario = cneObtenerUsuarioContextoSesion($_SESSION['user_id']);
    $cid = (int)($usuario['coordinacion_id'] ?? 0);
    if (!$cid) {
        echo json_encode(['success' => false, 'message' => 'Coordinación no definida']);
        exit;
    }

    $funcionario = trim($_GET['funcionario'] ?? '');
    $nacionalidad = trim($_GET['nacionalidad'] ?? '');
    $cedula = trim($_GET['cedula'] ?? '');
    $estado = $_GET['estado'] ?? '';
    $fecha_desde = $_GET['fecha_desde'] ?? '';
    $fecha_hasta = $_GET['fecha_hasta'] ?? '';

    $hasCoordActual = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordActual = (bool)$chk->fetchColumn();
    } catch (Exception $e) {}

    $coordWhere = $hasCoordActual
        ? "(t.coordinacion_id = :cid OR s.coordinacion_actual_id = :cid OR au.coord_destino = :cid)"
        : "(t.coordinacion_id = :cid OR au.coord_destino = :cid)";
    $coordSelect = $hasCoordActual ? "s.coordinacion_actual_id" : "t.coordinacion_id";
    $auSubquery = "
        LEFT JOIN (
            SELECT a.solicitud_id, c2.coordinacion_id AS coord_destino
            FROM auditoria a
            LEFT JOIN coordinacion c2 ON c2.coordinacion_nombre = JSON_UNQUOTE(JSON_EXTRACT(a.detalles, '$.coordinacion_destino'))
            WHERE a.auditoria_id IN (
                SELECT MAX(a2.auditoria_id) FROM auditoria a2
                WHERE a2.solicitud_id = a.solicitud_id
                AND (a2.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . " OR a2.accion_descripcion LIKE '%redirigido%')
            )
        ) au ON au.solicitud_id = s.solicitud_id
    ";
    $baseWhere = " WHERE $coordWhere";
    $params = [':cid' => $cid];

    if ($funcionario && $funcionario !== 'oficina_entrada') {
        $baseWhere .= " AND (s.empleado_asignado_id = :funcionario OR s.created_by = :funcionario2)";
        $params[':funcionario'] = $funcionario;
        $params[':funcionario2'] = $funcionario;
    }
    if ($cedula) {
        $cedulaBuscar = $cedula;
        if ($nacionalidad && in_array(strtoupper($nacionalidad), ['V', 'E'])) {
            $cedulaBuscar = strtoupper($nacionalidad) . '-' . preg_replace('/^[VE]-?/i', '', $cedula);
        }
        $baseWhere .= " AND s.ciudadano_identificacion LIKE :cedula";
        $params[':cedula'] = '%' . $cedulaBuscar . '%';
    }
    $estadoNormM = strtolower(trim((string) $estado));
    $esFiltroVencidaM = in_array($estadoNormM, ['vencida', 'vencido'], true);
    if ($estado) {
        if ($esFiltroVencidaM) {
            $baseWhere .= ' AND ' . cneSqlCondicionVencidaEfectiva();
        } elseif ($estado === 'redirigida') {
            $baseWhere .= " AND au.coord_destino IS NOT NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid";
        } elseif ($estado === 'en_revision') {
            $baseWhere .= " AND s.solicitud_estado = 'en_revision' AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = :cid)";
        } else {
            $baseWhere .= " AND s.solicitud_estado = :estado";
            $params[':estado'] = $estado;
        }
    }
    if ($fecha_desde) {
        $baseWhere .= " AND DATE(s.solicitud_fecha_solicitud) >= :fd";
        $params[':fd'] = $fecha_desde;
    }
    if ($fecha_hasta) {
        $baseWhere .= " AND DATE(s.solicitud_fecha_solicitud) <= :fh";
        $params[':fh'] = $fecha_hasta;
    }

    if ($funcionario === 'oficina_entrada') {
        $oficinaCoordId = null;
        try {
            $stmtOf = $db->query("SELECT coordinacion_id FROM coordinacion WHERE coordinacion_nombre LIKE 'Oficina de Atención%' LIMIT 1");
            if ($rowOf = $stmtOf->fetch()) $oficinaCoordId = (int)$rowOf['coordinacion_id'];
        } catch (Exception $e) {}
        if ($oficinaCoordId) {
            $baseWhere .= " AND (
                (s.solicitud_estado = 'pendiente' AND (s.empleado_asignado_id IS NULL OR s.empleado_asignado_id = s.created_by))
                OR (s.solicitud_estado = 'completada' AND EXISTS (
                    SELECT 1 FROM usuarios uo
                    WHERE uo.user_identificacion = COALESCE(s.empleado_asignado_id, s.created_by)
                    AND uo.coordinacion_id = :oficina_cid
                ))
            )";
            $params[':oficina_cid'] = $oficinaCoordId;
        }
    }

    $joinRecCoord = cneSqlJoinRecibidoCaracasPorSolicitud($db);
    $baseSql = "FROM solicitudes s JOIN tramite t ON s.tramite_id = t.tramite_id $auSubquery $joinRecCoord $baseWhere";

    $kpis = [];
    $stmt = $db->prepare("SELECT COUNT(*) as total $baseSql");
    $stmt->execute($params);
    $kpis['total'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado = 'pendiente' AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = :cid)");
    $stmt->execute($params);
    $kpis['pendientes'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado IN ('en_revision','aprobada') AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = :cid)");
    $stmt->execute($params);
    $kpis['en_proceso'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado = 'completada'");
    $stmt->execute($params);
    $kpis['completados'] = (int)$stmt->fetchColumn();

    $dPlazo = (int) cneEmpleadoDiasPlazoVencimientoTramite();
    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND (
        s.solicitud_estado IN ('vencida','rechazada')
        OR (
            cne_rec_car.cne_recibido_caracas_dt IS NOT NULL
            AND TIMESTAMPDIFF(DAY, cne_rec_car.cne_recibido_caracas_dt, NOW()) > {$dPlazo}
            AND s.solicitud_estado NOT IN ('completada','vencida','rechazada','invalidada')
        )
    )");
    $stmt->execute($params);
    $kpis['vencidos'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND au.coord_destino IS NOT NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid");
    $stmt->execute($params);
    $kpis['redirigidos'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as c $baseSql AND s.solicitud_estado = 'invalidada'");
    $stmt->execute($params);
    $kpis['invalidados'] = (int) $stmt->fetchColumn();

    $condRedCoord = "au.coord_destino IS NOT NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid";
    $caseCoord = cneSqlCaseEstadoParaReporte($condRedCoord);
    $stmt = $db->prepare("
        SELECT
            ({$caseCoord}) AS estado,
            COUNT(*) as cantidad
        $baseSql
        GROUP BY estado
    ");
    $stmt->execute($params);
    $porEstadoRaw = $stmt->fetchAll();
    $porEstadoMap = [];
    foreach ($porEstadoRaw as $r) {
        $porEstadoMap[$r['estado']] = (int)$r['cantidad'];
    }
    $enProcChart = ($porEstadoMap['en_revision'] ?? 0) + ($porEstadoMap['aprobada'] ?? 0);
    $vencChart = ($porEstadoMap['vencida'] ?? 0) + ($porEstadoMap['rechazada'] ?? 0);
    $chartBar = ['labels' => [], 'data' => [], 'colors' => []];
    $pushBar = static function (array &$chart, string $label, int $n, string $color): void {
        if ($n > 0) {
            $chart['labels'][] = $label;
            $chart['data'][] = $n;
            $chart['colors'][] = $color;
        }
    };
    $pushBar($chartBar, 'Pendiente', (int)($porEstadoMap['pendiente'] ?? 0), '#f59e0b');
    $pushBar($chartBar, 'En Proceso', $enProcChart, '#3b82f6');
    $pushBar($chartBar, 'Completada', (int)($porEstadoMap['completada'] ?? 0), '#10b981');
    $pushBar($chartBar, 'Invalidada', (int)($porEstadoMap['invalidada'] ?? 0), '#dc2626');
    $pushBar($chartBar, 'Redirigida', (int)($porEstadoMap['redirigida'] ?? 0), '#8b5cf6');
    $pushBar($chartBar, 'Vencidos', $vencChart, '#6c757d');

    $stmt = $db->prepare("
        SELECT t.tramite_nombre as nombre, COUNT(*) as cantidad
        $baseSql
        GROUP BY t.tramite_id
        ORDER BY cantidad DESC
    ");
    $stmt->execute($params);
    $porTipo = $stmt->fetchAll();
    $chartPie = [
        'labels' => [],
        'data' => []
    ];
    foreach ($porTipo as $r) {
        $chartPie['labels'][] = $r['nombre'];
        $chartPie['data'][] = (int)$r['cantidad'];
    }

    // Carga de trabajo por empleado: agrupar Oficina de Atención al Ciudadano; individual para el resto
    $stmt = $db->prepare("
        SELECT
            CASE
                WHEN coord.coordinacion_nombre LIKE '%Oficina de Atención%' OR coord.coordinacion_nombre LIKE '%Atención al Ciudadano%'
                THEN 'Oficina de Atención al Ciudadano'
                ELSE COALESCE(CONCAT(u.user_nombres, ' ', u.user_apellidos), 'Sin asignar')
            END AS identificador,
            COUNT(*) as cantidad
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        $auSubquery
        LEFT JOIN usuarios u ON COALESCE(s.empleado_asignado_id, s.created_by) = u.user_identificacion
        LEFT JOIN coordinacion coord ON u.coordinacion_id = coord.coordinacion_id
        $baseWhere
        GROUP BY
            CASE
                WHEN coord.coordinacion_nombre LIKE '%Oficina de Atención%' OR coord.coordinacion_nombre LIKE '%Atención al Ciudadano%'
                THEN 'Oficina de Atención al Ciudadano'
                ELSE COALESCE(CONCAT(u.user_nombres, ' ', u.user_apellidos), 'Sin asignar')
            END
        ORDER BY cantidad DESC
    ");
    try {
        $stmt->execute($params);
        $cargaEmpleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cargaEmpleados = [];
    }
    $chartCargaEmpleados = [
        'labels' => array_map(fn($x) => $x['identificador'], $cargaEmpleados),
        'data' => array_map(fn($x) => (int)$x['cantidad'], $cargaEmpleados)
    ];

    echo json_encode([
        'success' => true,
        'kpis' => $kpis,
        'chart_bar' => $chartBar,
        'chart_pie' => $chartPie,
        'chart_carga_empleados' => $chartCargaEmpleados
    ]);
} catch (Exception $e) {
    error_log("coordinador_metricas: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
