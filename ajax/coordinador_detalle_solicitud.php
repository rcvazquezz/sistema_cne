<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 3) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$solicitud_id = (int)($_POST['solicitud_id'] ?? $_GET['solicitud_id'] ?? 0);
if (!$solicitud_id) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
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

    $hasCoordActual = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordActual = (bool)$chk->fetchColumn();
    } catch (Exception $e) {}

    $coordWhere = $hasCoordActual
        ? "(t.coordinacion_id = :cid OR s.coordinacion_actual_id = :cid OR au.coord_destino = :cid)"
        : "(t.coordinacion_id = :cid OR au.coord_destino = :cid)";
    $coordSelect = $hasCoordActual ? "s.coordinacion_actual_id" : "t.coordinacion_id";

    $stmt = $db->prepare("
        SELECT s.*, c.ciudadano_identificacion, c.ciudadano_nombres, c.ciudadano_apellidos, c.ciudadano_telefono,
               t.tramite_nombre, ue.user_nombres as emp_nombres, ue.user_apellidos as emp_apellidos,
               ue.rol_id as emp_rol_id,
               uc.user_nombres as creador_nombres, uc.user_apellidos as creador_apellidos,
               uc.rol_id as creador_rol_id,
               ur.user_nombres as redir_nombres, ur.user_apellidos as redir_apellidos,
               CASE
                   WHEN COALESCE(au.coord_destino, $coordSelect) <> :cid AND au.coord_destino IS NOT NULL
                   THEN 'redirigida'
                   ELSE s.solicitud_estado
               END AS solicitud_estado
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN usuarios ue ON s.empleado_asignado_id = ue.user_identificacion
        LEFT JOIN usuarios uc ON s.created_by = uc.user_identificacion
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
        LEFT JOIN usuarios ur ON au.redirigido_por_id = ur.user_identificacion
        WHERE s.solicitud_id = :sid AND $coordWhere
    ");
    $stmt->execute([':sid' => $solicitud_id, ':cid' => $cid]);
    $s = $stmt->fetch();
    if (!$s) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    $stmt2 = $db->prepare("
        SELECT r.requisito_nombre, rs.requisitos_solicitud_status
        FROM requisitos_solicitud rs
        JOIN requisitos r ON rs.requisito_id = r.requisito_id
        WHERE rs.solicitud_id = :sid
    ");
    $stmt2->execute([':sid' => $solicitud_id]);
    $s['requisitos'] = $stmt2->fetchAll();

    $estado = $s['solicitud_estado'] ?? '';
    $esRedirigida = ($estado === 'redirigida');
    $redirNom = trim(($s['redir_nombres'] ?? '') . ' ' . ($s['redir_apellidos'] ?? ''));
    $asigNom = trim(($s['emp_nombres'] ?? '') . ' ' . ($s['emp_apellidos'] ?? ''));
    $asigRolId = (int)($s['emp_rol_id'] ?? 0);
    $creaNom = trim(($s['creador_nombres'] ?? '') . ' ' . ($s['creador_apellidos'] ?? ''));
    $creaRolId = (int)($s['creador_rol_id'] ?? 0);
    $sufijoOac = ' (Atención al Cliente)';
    if ($esRedirigida && !empty($redirNom)) {
        $s['empleado_nombre'] = $redirNom;
    } elseif (!$esRedirigida) {
        if (!empty($asigNom) && $asigRolId !== 1) {
            $s['empleado_nombre'] = $asigNom;
        } elseif (!empty($asigNom) && $asigRolId === 1) {
            $s['empleado_nombre'] = $asigNom . $sufijoOac;
        } elseif (empty($asigNom) && $creaRolId === 1 && $creaNom !== '') {
            $s['empleado_nombre'] = $creaNom . $sufijoOac;
        } elseif (empty($asigNom) && $creaNom !== '') {
            $s['empleado_nombre'] = $creaNom;
        } else {
            $s['empleado_nombre'] = 'Sin asignar';
        }
    } else {
        $s['empleado_nombre'] = 'Sin asignar';
    }
    $s['fecha_registro'] = date('d/m/Y h:i a', strtotime($s['solicitud_fecha_solicitud'] ?? ''));

    echo json_encode(['success' => true, 'solicitud' => $s]);
} catch (Exception $e) {
    error_log("coordinador_detalle_solicitud: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
