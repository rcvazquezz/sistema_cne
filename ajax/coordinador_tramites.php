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

    $numero = trim($_GET['numero'] ?? '');
    $funcionario = trim($_GET['funcionario'] ?? '');
    $nacionalidad = trim($_GET['nacionalidad'] ?? '');
    $cedula = trim($_GET['cedula'] ?? '');
    $estado = $_GET['estado'] ?? '';
    $fecha_desde = $_GET['fecha_desde'] ?? '';
    $fecha_hasta = $_GET['fecha_hasta'] ?? '';
    $estadoNorm = strtolower(trim((string) $estado));
    $esFiltroVencida = in_array($estadoNorm, ['vencida', 'vencido'], true);

    $hasCoordActual = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordActual = (bool)$chk->fetchColumn();
    } catch (Exception $e) {}

    $coordWhere = $hasCoordActual
        ? "(t.coordinacion_id = :cid OR s.coordinacion_actual_id = :cid OR au.coord_destino = :cid)"
        : "(t.coordinacion_id = :cid OR au.coord_destino = :cid)";
    $coordSelect = $hasCoordActual ? "s.coordinacion_actual_id" : "t.coordinacion_id";
    $sql = "
        SELECT s.solicitud_id, s.solicitud_numero, s.solicitud_fecha_solicitud as fecha_registro,
               CASE
                   WHEN COALESCE(au.coord_destino, $coordSelect) <> :cid_redir AND au.coord_destino IS NOT NULL
                   THEN 'redirigida'
                   ELSE s.solicitud_estado
               END AS solicitud_estado,
               c.ciudadano_identificacion, CONCAT(c.ciudadano_nombres,' ',c.ciudadano_apellidos) as ciudadano_nombre,
               t.tramite_nombre,
               u_asig.user_nombres as emp_nombres, u_asig.user_apellidos as emp_apellidos,
               u_asig.rol_id as emp_rol_id,
               u_crea.user_nombres as creador_nombres, u_crea.user_apellidos as creador_apellidos,
               u_crea.rol_id as creador_rol_id,
               u_redir.user_nombres as redir_nombres, u_redir.user_apellidos as redir_apellidos,
               au.redirigido_por_id
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN usuarios u_asig ON s.empleado_asignado_id = u_asig.user_identificacion
        LEFT JOIN usuarios u_crea ON s.created_by = u_crea.user_identificacion
        LEFT JOIN (
            SELECT a.solicitud_id, c2.coordinacion_id AS coord_destino, a.empleado_id AS redirigido_por_id
            FROM auditoria a
            LEFT JOIN coordinacion c2 ON c2.coordinacion_nombre = JSON_UNQUOTE(JSON_EXTRACT(a.detalles, '$.coordinacion_destino'))
            WHERE a.auditoria_id IN (
                SELECT MAX(a2.auditoria_id)
                FROM auditoria a2
                WHERE a2.solicitud_id = a.solicitud_id
                  AND (a2.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . " OR a2.accion_descripcion LIKE '%redirigido%')
            )
        ) au ON au.solicitud_id = s.solicitud_id
        LEFT JOIN usuarios u_redir ON au.redirigido_por_id = u_redir.user_identificacion
        " . ($esFiltroVencida ? trim(cneSqlJoinRecibidoCaracasPorSolicitud($db)) : '') . "
        WHERE $coordWhere
    ";
    $params = [':cid' => $cid, ':cid_redir' => $cid];

    if ($numero) {
        $sql .= " AND s.solicitud_numero LIKE :numero";
        $params[':numero'] = '%' . $numero . '%';
    }
    if ($funcionario) {
        if ($funcionario === 'oficina_entrada') {
            $oficinaCoordId = null;
            try {
                $stmtOf = $db->query("SELECT coordinacion_id FROM coordinacion WHERE coordinacion_nombre LIKE 'Oficina de Atención%' LIMIT 1");
                if ($rowOf = $stmtOf->fetch()) $oficinaCoordId = (int)$rowOf['coordinacion_id'];
            } catch (Exception $e) {}
            if ($oficinaCoordId) {
                $sql .= " AND (
                    (s.solicitud_estado = 'pendiente' AND (s.empleado_asignado_id IS NULL OR (u_asig.rol_id = 1)))
                    OR (s.solicitud_estado = 'completada' AND EXISTS (
                        SELECT 1 FROM usuarios uo
                        WHERE uo.user_identificacion = COALESCE(s.empleado_asignado_id, s.created_by)
                        AND uo.coordinacion_id = :oficina_cid
                    ))
                )";
                $params[':oficina_cid'] = $oficinaCoordId;
            }
        } else {
            // Incluir: empleado_asignado_id = func O trámites redirigidos donde func ejecutó la redirección
            $sql .= " AND (
                s.empleado_asignado_id = :func
                OR (au.coord_destino IS NOT NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid AND au.redirigido_por_id = :func2)
            )";
            $params[':func'] = $funcionario;
            $params[':func2'] = $funcionario;
        }
    }
    if ($cedula) {
        $cedulaBuscar = $cedula;
        if ($nacionalidad && in_array(strtoupper($nacionalidad), ['V', 'E'])) {
            $cedulaBuscar = strtoupper($nacionalidad) . '-' . preg_replace('/^[VE]-?/i', '', $cedula);
        }
        $sql .= " AND c.ciudadano_identificacion LIKE :cedula";
        $params[':cedula'] = '%' . $cedulaBuscar . '%';
    }
    if ($estado) {
        if ($esFiltroVencida) {
            $sql .= ' AND ' . cneSqlCondicionVencidaEfectiva();
        } elseif ($estado === 'redirigida') {
            $sql .= " AND au.coord_destino IS NOT NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid";
        } elseif ($estado === 'en_revision') {
            $sql .= " AND s.solicitud_estado = 'en_revision' AND (au.coord_destino IS NULL OR COALESCE(au.coord_destino, $coordSelect) = :cid)";
        } else {
            $sql .= " AND s.solicitud_estado = :estado";
            $params[':estado'] = $estado;
        }
    }
    if ($fecha_desde) {
        $sql .= " AND DATE(s.solicitud_fecha_solicitud) >= :fd";
        $params[':fd'] = $fecha_desde;
    }
    if ($fecha_hasta) {
        $sql .= " AND DATE(s.solicitud_fecha_solicitud) <= :fh";
        $params[':fh'] = $fecha_hasta;
    }
    $sql .= " ORDER BY s.solicitud_fecha_solicitud DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $solicitudes = $stmt->fetchAll();
    foreach ($solicitudes as &$s) {
        $s['fecha_registro'] = date('d/m/Y h:i a', strtotime($s['fecha_registro'] ?? ''));
        $estado = $s['solicitud_estado'] ?? '';
        $esRedirigida = ($estado === 'redirigida');
        $redirNom = trim(($s['redir_nombres'] ?? '') . ' ' . ($s['redir_apellidos'] ?? ''));
        $asigNom = trim(($s['emp_nombres'] ?? '') . ' ' . ($s['emp_apellidos'] ?? ''));
        $asigRolId = (int)($s['emp_rol_id'] ?? 0);
        $creadorRolId = (int)($s['creador_rol_id'] ?? 0);
        $creadorNom = trim(($s['creador_nombres'] ?? '') . ' ' . ($s['creador_apellidos'] ?? ''));
        $sufijoOac = ' (Atención al Cliente)';
        // Coordinación de Origen (quien envía): si es redirigida, mostrar el usuario que ejecutó la redirección
        if ($esRedirigida && !empty($redirNom)) {
            $s['empleado_nombre'] = $redirNom;
        } elseif (!$esRedirigida) {
            if (!empty($asigNom) && $asigRolId !== 1) {
                $s['empleado_nombre'] = $asigNom;
            } elseif (!empty($asigNom) && $asigRolId === 1) {
                $s['empleado_nombre'] = $asigNom . $sufijoOac;
            } elseif (empty($asigNom) && $creadorRolId === 1 && $creadorNom !== '') {
                $s['empleado_nombre'] = $creadorNom . $sufijoOac;
            } elseif (empty($asigNom) && $creadorNom !== '') {
                $s['empleado_nombre'] = $creadorNom;
            } else {
                $s['empleado_nombre'] = 'Sin asignar';
            }
        } else {
            // Redirigida pero sin nombre del que redirigió (Coordinación destino recién recibido, o datos faltantes)
            $s['empleado_nombre'] = 'Sin asignar';
        }
    }
    echo json_encode(['success' => true, 'solicitudes' => $solicitudes]);
} catch (Exception $e) {
    error_log("coordinador_tramites: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
