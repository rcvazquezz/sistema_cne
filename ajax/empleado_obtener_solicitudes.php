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
    $usuario = obtenerUsuario($usuario_id);
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
    $coordSelect = $hasCoordActual ? "s.coordinacion_actual_id" : "t.coordinacion_id";
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
                WHEN COALESCE(au.coord_destino, $coordSelect) <> :coord_id AND au.coord_destino IS NOT NULL 
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
            t.tramite_nombre,
            t.tramite_padre_id,
            COALESCE(au.coord_destino, $coordSelect) AS coordinacion_id,
            CASE 
                WHEN env.last_env_id IS NOT NULL AND (rec.last_rec_id IS NULL OR env.last_env_id > rec.last_rec_id) THEN 1
                ELSE 0
            END AS en_caracas,
            DATE_FORMAT(recdate.$audFechaCol, '%Y-%m-%d %H:%i:%s') AS recibido_fecha
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        JOIN tramite t ON s.tramite_id = t.tramite_id
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
    
    $params = [':coord_id' => $coordinacion_id];
    
    if ($filtro_estado) {
        $sql .= " WHERE COALESCE(au.coord_destino, $coordSelect) = :coord_id";
        if ($esFiltroVencida) {
            $sql .= ' AND ' . cneSqlCondicionVencidaEfectiva();
        } else {
            $sql .= " AND s.solicitud_estado = :estado";
            $params[':estado'] = $filtro_estado;
        }
    } else {
        $sql .= " WHERE (COALESCE(au.coord_destino, $coordSelect) = :coord_id";
        $sql .= " OR EXISTS (";
        $sql .= "     SELECT 1";
        $sql .= "     FROM auditoria a";
        $sql .= "     JOIN usuarios u ON u.user_identificacion = a.empleado_id";
        $sql .= "     WHERE a.solicitud_id = s.solicitud_id";
        $sql .= "       AND u.coordinacion_id = :coord_id";
        $sql .= " ))";
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
