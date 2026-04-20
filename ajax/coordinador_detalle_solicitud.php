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

    $tieneTramiteIdInicial = cneSolicitudesTieneTramiteIdInicial($db);
    $tidEtqSql = cneSqlExpresionTramiteIdEtiqueta($tieneTramiteIdInicial);

    $coordWhere = cneSqlWhereCoordinacionVinculaSolicitud($hasCoordActual);
    $coordSelect = $hasCoordActual ? "s.coordinacion_actual_id" : "t.coordinacion_id";

    $stmt = $db->prepare("
        SELECT s.*, c.ciudadano_identificacion, c.ciudadano_nombres, c.ciudadano_apellidos, c.ciudadano_telefono,
               CASE
                   WHEN t_etq.tramite_padre_id IS NOT NULL AND tp_etq.tramite_id IS NOT NULL 
                       THEN CONCAT(tp_etq.tramite_nombre, ' — ', t_etq.tramite_nombre)
                   WHEN t_etq.tramite_id IS NOT NULL THEN t_etq.tramite_nombre
                   WHEN t.tramite_padre_id IS NOT NULL AND tp_op.tramite_id IS NOT NULL 
                       THEN CONCAT(tp_op.tramite_nombre, ' — ', t.tramite_nombre)
                   ELSE t.tramite_nombre
               END AS tramite_nombre,
               ue.user_nombres as emp_nombres, ue.user_apellidos as emp_apellidos,
               ue.rol_id as emp_rol_id,
               uc.user_nombres as creador_nombres, uc.user_apellidos as creador_apellidos,
               uc.rol_id as creador_rol_id,
               ur.user_nombres as redir_nombres, ur.user_apellidos as redir_apellidos,
               CASE
                   WHEN au.coord_destino IS NOT NULL AND (
                       au.coord_origen_id = :cid
                       OR (au.coord_origen_id IS NULL AND COALESCE(au.coord_destino, $coordSelect) <> :cid)
                   ) THEN 'redirigida'
                   ELSE s.solicitud_estado
               END AS solicitud_estado
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        " . trim(cneSqlJoinAuditoriaTramiteIdCreacion()) . "
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN tramite t_etq ON t_etq.tramite_id = $tidEtqSql
        LEFT JOIN tramite tp_etq ON tp_etq.tramite_id = t_etq.tramite_padre_id
        LEFT JOIN tramite tp_op ON tp_op.tramite_id = t.tramite_padre_id
        LEFT JOIN usuarios ue ON s.empleado_asignado_id = ue.user_identificacion
        LEFT JOIN usuarios uc ON s.created_by = uc.user_identificacion
        " . trim(cneSqlLeftJoinAuditoriaUltimaRedireccion()) . "
        LEFT JOIN usuarios ur ON au.redirigido_por_id = ur.user_identificacion
        WHERE s.solicitud_id = :sid AND $coordWhere
    ");
    $stmt->execute([':sid' => $solicitud_id, ':cid' => $cid]);
    $s = $stmt->fetch();
    if (!$s) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    $tidReqCatalogo = (int) cneResolverTramiteIdParaRequisitos($db, $solicitud_id);
    if ($tidReqCatalogo < 1) {
        $tidReqCatalogo = (int) ($s['tramite_id'] ?? 0);
    }
    $stmt2 = $db->prepare('
        SELECT r.requisito_nombre, rs.requisitos_solicitud_status
        FROM requisitos_solicitud rs
        INNER JOIN requisitos r ON rs.requisito_id = r.requisito_id AND r.tramite_id = :tid_req
        WHERE rs.solicitud_id = :sid
        ORDER BY r.requisito_nombre
    ');
    $stmt2->execute([':sid' => $solicitud_id, ':tid_req' => $tidReqCatalogo]);
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
