<?php
/**
 * Reporte de desempeño por funcionario: conteos por estado alineados con coordinador_metricas.php
 */
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
    } catch (Exception $e) {
    }

    $coordWhere = cneSqlWhereCoordinacionVinculaSolicitud($hasCoordActual);
    $coordSelect = $hasCoordActual ? "s.coordinacion_actual_id" : "t.coordinacion_id";
    $tieneTramiteIdInicial = cneSolicitudesTieneTramiteIdInicial($db);
    $tidEtqSql = cneSqlExpresionTramiteIdEtiqueta($tieneTramiteIdInicial);
    $joinTramEtq = "
        LEFT JOIN tramite t_etq ON t_etq.tramite_id = $tidEtqSql
        LEFT JOIN tramite tp_etq ON tp_etq.tramite_id = t_etq.tramite_padre_id
        LEFT JOIN tramite tp_op ON tp_op.tramite_id = t.tramite_padre_id
    ";
    $fragFromInner = '
FROM solicitudes s
    ' . trim(cneSqlJoinAuditoriaTramiteIdCreacion()) . '
    JOIN tramite t ON s.tramite_id = t.tramite_id
    ' . $joinTramEtq . '
    ' . trim(cneSqlLeftJoinAuditoriaUltimaRedireccion()) . '
';
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
    if ($estado) {
        if ($estado === 'redirigida') {
            $baseWhere .= " AND au.coord_destino IS NOT NULL AND (au.coord_origen_id = :cid OR (au.coord_origen_id IS NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid))";
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
            if ($rowOf = $stmtOf->fetch()) {
                $oficinaCoordId = (int)$rowOf['coordinacion_id'];
            }
        } catch (Exception $e) {
        }
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

    $cidInt = (int)$cid;
    $joinRecRep = cneSqlJoinRecibidoCaracasPorSolicitud($db);
    $dPlazoRep = (int) cneEmpleadoDiasPlazoVencimientoTramite();

    $sql = "
        SELECT
            TRIM(
                COALESCE(
                    NULLIF(CONCAT(COALESCE(u.user_nombres, ''), ' ', COALESCE(u.user_apellidos, '')), ''),
                    u.user_username
                )
            ) AS funcionario,
            SUM(CASE WHEN sol.categoria = 'pendientes' THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN sol.categoria = 'en_proceso' THEN 1 ELSE 0 END) AS en_proceso,
            SUM(CASE WHEN sol.categoria = 'completados' THEN 1 ELSE 0 END) AS completados,
            SUM(CASE WHEN sol.categoria = 'vencidos' THEN 1 ELSE 0 END) AS vencidos,
            SUM(CASE WHEN sol.categoria = 'redirigidos' THEN 1 ELSE 0 END) AS redirigidos,
            SUM(CASE WHEN sol.categoria = 'invalidados' THEN 1 ELSE 0 END) AS invalidados,
            COUNT(sol.solicitud_id) AS total
        FROM usuarios u
        LEFT JOIN (
            SELECT
                s.solicitud_id,
                s.empleado_asignado_id,
                s.created_by,
                au.redirigido_por_id,
                CASE
                    WHEN s.solicitud_estado = 'invalidada' THEN 'invalidados'
                    WHEN au.coord_destino IS NOT NULL AND (au.coord_origen_id = {$cidInt} OR (au.coord_origen_id IS NULL AND COALESCE(au.coord_destino, $coordSelect) <> {$cidInt})) THEN 'redirigidos'
                    WHEN s.solicitud_estado = 'vencida' THEN 'vencidos'
                    WHEN s.solicitud_estado = 'rechazada' THEN 'vencidos'
                    WHEN cne_rec_car.cne_recibido_caracas_dt IS NOT NULL
                        AND TIMESTAMPDIFF(DAY, cne_rec_car.cne_recibido_caracas_dt, NOW()) > {$dPlazoRep}
                        AND s.solicitud_estado NOT IN ('completada','vencida','rechazada','invalidada')
                        AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = {$cidInt}) THEN 'vencidos'
                    WHEN s.solicitud_estado = 'pendiente'
                        AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = {$cidInt}) THEN 'pendientes'
                    WHEN s.solicitud_estado IN ('en_revision', 'aprobada')
                        AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = {$cidInt}) THEN 'en_proceso'
                    WHEN s.solicitud_estado = 'completada' THEN 'completados'
                    ELSE 'en_proceso'
                END AS categoria
            $fragFromInner
            $joinRecRep
            $baseWhere
        ) sol ON (
            COALESCE(sol.empleado_asignado_id, sol.created_by) = u.user_identificacion
            OR (sol.redirigido_por_id IS NOT NULL AND sol.redirigido_por_id = u.user_identificacion)
        )
        WHERE u.coordinacion_id = {$cidInt} AND u.user_estado = 'activo'
        GROUP BY u.user_identificacion, u.user_username, u.user_nombres, u.user_apellidos
        ORDER BY u.user_nombres, u.user_apellidos, u.user_username
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $filas = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $filas[] = [
            'funcionario' => $row['funcionario'] ?? '',
            'pendientes' => (int)($row['pendientes'] ?? 0),
            'en_proceso' => (int)($row['en_proceso'] ?? 0),
            'completados' => (int)($row['completados'] ?? 0),
            'vencidos' => (int)($row['vencidos'] ?? 0),
            'redirigidos' => (int)($row['redirigidos'] ?? 0),
            'invalidados' => (int)($row['invalidados'] ?? 0),
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    echo json_encode([
        'success' => true,
        'filas' => $filas,
    ]);
} catch (Exception $e) {
    error_log('coordinador_reporte_desempeno: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
