<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$usuario_id = $_SESSION['user_id'];

try {
    $db = getDB();
    require_once __DIR__ . '/../includes/cne_admin_view_context.php';
    $usuario = cneObtenerUsuarioContextoSesion($usuario_id);
    $coordinacion_id = $usuario['coordinacion_id'] ?? null;
    if (!$coordinacion_id) {
        echo json_encode(['success' => false, 'message' => 'Coordinación no definida para el usuario']);
        exit;
    }
    
    $filtro_estado = $_GET['estado'] ?? '';
    $filtro_cedula = $_GET['cedula'] ?? '';
    $filtro_tipo_tramite = $_GET['tipo_tramite'] ?? '';
    $filtro_subtramite = $_GET['subtramite'] ?? '';
    $filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
    $filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';

    $filtroNorm = strtolower(trim($filtro_estado));
    $esFiltroVencida = in_array($filtroNorm, ['vencida', 'vencido'], true);
    
    $hasCoordActual = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordActual = (bool)$chk->fetchColumn();
    } catch (Exception $e) {
        $hasCoordActual = false;
    }
    $coordSql = cneSqlCoordinacionEfectivaSolicitud($hasCoordActual);
    $tieneTramiteIdInicial = cneSolicitudesTieneTramiteIdInicial($db);
    $tidEtqSql = cneSqlExpresionTramiteIdEtiqueta($tieneTramiteIdInicial);
    $audFechaCol = 'auditoria_created_at';
    try {
        $chk2 = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'fecha_creacion'");
        if ((bool)$chk2->fetchColumn()) {
            $audFechaCol = 'fecha_creacion';
        }
    } catch (Exception $e) {}
    
    $sql = "
        SELECT 
            s.solicitud_id,
            s.solicitud_numero,
            CASE 
                WHEN ($coordSql) <> :coord_id AND au.coord_destino IS NOT NULL 
                    THEN 'redirigida'
                ELSE s.solicitud_estado
            END AS solicitud_estado,
            s.solicitud_estado AS solicitud_estado_tabla,
            DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro,
            s.solicitud_fecha_completada,
            c.ciudadano_identificacion,
            c.ciudadano_nombres,
            c.ciudadano_apellidos,
            CONCAT(c.ciudadano_nombres, ' ', c.ciudadano_apellidos) as ciudadano_nombre,
            c.ciudadano_genero,
            t.tramite_id,
            COALESCE(t_etq.tramite_id, t.tramite_id) AS tramite_id_requisitos,
            CASE 
                WHEN t_etq.tramite_padre_id IS NOT NULL AND tp_etq.tramite_id IS NOT NULL 
                    THEN CONCAT(tp_etq.tramite_nombre, ' — ', t_etq.tramite_nombre)
                WHEN t_etq.tramite_id IS NOT NULL THEN t_etq.tramite_nombre
                WHEN t.tramite_padre_id IS NOT NULL AND tp_op.tramite_id IS NOT NULL 
                    THEN CONCAT(tp_op.tramite_nombre, ' — ', t.tramite_nombre)
                ELSE t.tramite_nombre
            END AS tramite_nombre,
            t.tramite_padre_id,
            t_etq.coordinacion_id AS coordinacion_origen,
            ($coordSql) AS coordinacion_id,
            CASE 
                WHEN env.last_env_id IS NOT NULL AND (rec.last_rec_id IS NULL OR env.last_env_id > rec.last_rec_id) THEN 1
                ELSE 0
            END AS en_caracas,
            DATE_FORMAT(recdate.$audFechaCol, '%Y-%m-%d %H:%i:%s') AS recibido_fecha
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        " . trim(cneSqlJoinAuditoriaTramiteIdCreacion()) . "
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN tramite t_etq ON t_etq.tramite_id = $tidEtqSql
        LEFT JOIN tramite tp_etq ON tp_etq.tramite_id = t_etq.tramite_padre_id
        LEFT JOIN tramite tp_op ON tp_op.tramite_id = t.tramite_padre_id
        LEFT JOIN (
            SELECT a.solicitud_id,
                   c2.coordinacion_id AS coord_destino
            FROM auditoria a
            LEFT JOIN coordinacion c2 
              ON c2.coordinacion_nombre = JSON_UNQUOTE(JSON_EXTRACT(a.detalles, '$.coordinacion_destino'))
            WHERE a.auditoria_id IN (
                SELECT MAX(a2.auditoria_id)
                FROM auditoria a2
                WHERE a2.solicitud_id = a.solicitud_id
                  AND (
                    a2.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . "
                    OR a2.accion_descripcion LIKE '%redirigido%'
                  )
            )
        ) au ON au.solicitud_id = s.solicitud_id
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
        LEFT JOIN auditoria recdate ON recdate.auditoria_id = rec.last_rec_id
    ";
    if ($esFiltroVencida) {
        $sql .= trim(cneSqlJoinRecibidoCaracasPorSolicitud($db));
    }
    
    $visibilidadSql = "(
        ($coordSql) = :coord_id
        OR EXISTS (
            SELECT 1
            FROM auditoria a
            JOIN usuarios u ON u.user_identificacion = a.empleado_id
            WHERE a.solicitud_id = s.solicitud_id
              AND u.coordinacion_id = :coord_id
        )
        OR s.created_by = :uid_m
        OR s.empleado_asignado_id = :uid_m
        OR EXISTS (
            SELECT 1 FROM auditoria ax
            WHERE ax.solicitud_id = s.solicitud_id
              AND ax.empleado_id = :uid_m
        )
    )";
    $params = [
        ':coord_id' => $coordinacion_id,
        ':uid_m' => $usuario_id,
    ];
    $esColaPendientes = $filtro_estado !== '' && strcasecmp(trim($filtro_estado), 'pendiente') === 0;

    if ($esColaPendientes) {
        $sql .= " WHERE ($coordSql) = :coord_id AND s.solicitud_estado = 'pendiente'";
    } elseif ($filtro_estado) {
        $sql .= " WHERE ($visibilidadSql)";
        if ($esFiltroVencida) {
            $sql .= ' AND ' . cneSqlCondicionVencidaEfectiva();
        } else {
            $sql .= " AND s.solicitud_estado = :estado";
            $params[':estado'] = $filtro_estado;
        }
    } else {
        $sql .= " WHERE ($visibilidadSql)";
    }
    
    if ($filtro_cedula) {
        $sql .= " AND c.ciudadano_identificacion LIKE :cedula";
        $params[':cedula'] = '%' . $filtro_cedula . '%';
    }
    
    if ($filtro_subtramite) {
        $sql .= " AND t.tramite_id = :subtramite_id";
        $params[':subtramite_id'] = $filtro_subtramite;
    } elseif ($filtro_tipo_tramite) {
        $sql .= " AND (t.tramite_id = :tipo_tramite_id OR t.tramite_padre_id = :tipo_tramite_id)";
        $params[':tipo_tramite_id'] = $filtro_tipo_tramite;
    }
    
    if ($filtro_fecha_desde) {
        $sql .= " AND DATE(s.solicitud_created_at) >= :fecha_desde";
        $params[':fecha_desde'] = $filtro_fecha_desde;
    }
    
    if ($filtro_fecha_hasta) {
        $sql .= " AND DATE(s.solicitud_created_at) <= :fecha_hasta";
        $params[':fecha_hasta'] = $filtro_fecha_hasta;
    }
    
    $sql .= " ORDER BY s.solicitud_created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $solicitudes = $stmt->fetchAll();

    foreach ($solicitudes as &$sol) {
        $tab = $sol['solicitud_estado_tabla'] ?? '';
        unset($sol['solicitud_estado_tabla']);
        $rec = $sol['recibido_fecha'] ?? null;
        $sol['estado_para_reporte'] = cneEstadoParaReporteFila(
            (string) ($sol['solicitud_estado'] ?? ''),
            (string) $tab,
            $rec !== null && $rec !== '' ? (string) $rec : null
        );
    }
    unset($sol);

    echo json_encode(['success' => true, 'solicitudes' => $solicitudes]);
    
} catch (Exception $e) {
    error_log("Error obteniendo solicitudes del empleado: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al obtener solicitudes']);
}
?> 
