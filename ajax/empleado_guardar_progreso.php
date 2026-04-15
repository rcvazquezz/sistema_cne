<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
$solicitud_id = isset($_POST['solicitud_id']) ? (int)$_POST['solicitud_id'] : 0;
$codigo_interno = isset($_POST['codigo_interno']) ? trim($_POST['codigo_interno']) : null;
$requisitos_raw = $_POST['requisitos'] ?? '[]';
if ($solicitud_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Solicitud inválida']);
    exit;
}
$seleccionados = [];
try { 
    $tmp = json_decode($requisitos_raw, true); 
    if (is_array($tmp)) $seleccionados = array_map('intval', $tmp);
} catch (Exception $e) {}
if (!$seleccionados && is_string($requisitos_raw)) {
    $parts = array_filter(array_map('trim', explode(',', $requisitos_raw)));
    $seleccionados = array_map('intval', $parts);
}
try {
    $db = getDB();
    $usuario = obtenerUsuario($_SESSION['user_id']);
    $mi_coordinacion_id = $usuario['coordinacion_id'] ?? null;
    if (!$mi_coordinacion_id) {
        echo json_encode(['success' => false, 'message' => 'Coordinación del usuario no definida']);
        exit;
    }
    $stmt = $db->prepare("SELECT tramite_id, solicitud_estado FROM solicitudes WHERE solicitud_id = :sid");
    $stmt->execute([':sid' => $solicitud_id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Trámite no encontrado']);
        exit;
    }
    $tramite_id = (int)$row['tramite_id'];
    $estado_actual = $row['solicitud_estado'] ?? null;
    $stmt = $db->prepare("
        SELECT COALESCE(au.coord_destino, t.coordinacion_id) AS coord_actual
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN (
            SELECT a.solicitud_id, c2.coordinacion_id AS coord_destino
            FROM auditoria a
            LEFT JOIN coordinacion c2 ON c2.coordinacion_nombre = JSON_UNQUOTE(JSON_EXTRACT(a.detalles, '$.coordinacion_destino'))
            WHERE a.auditoria_id IN (
                SELECT MAX(a2.auditoria_id)
                FROM auditoria a2
                WHERE a2.solicitud_id = a.solicitud_id
                  AND (a2.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . " OR a2.accion_descripcion LIKE '%redirigido%')
            )
        ) au ON au.solicitud_id = s.solicitud_id
        WHERE s.solicitud_id = :sid
    ");
    $stmt->execute([':sid' => $solicitud_id]);
    $own = $stmt->fetch();
    if (!$own || (int)$own['coord_actual'] !== (int)$mi_coordinacion_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso Denegado: Este trámite ya fue redirigido y solo puede ser gestionado por la coordinación actual.']);
        exit;
    }
    $bloqueo = cneEmpleadoMensajeSiSolicitudNoGestionable($db, $solicitud_id);
    if ($bloqueo !== null) {
        echo json_encode(['success' => false, 'message' => $bloqueo], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $db->prepare("DELETE FROM requisitos_solicitud WHERE solicitud_id = :sid");
    $stmt->execute([':sid' => $solicitud_id]);
    $stmt = $db->prepare("
        SELECT requisito_id 
        FROM requisitos 
        WHERE tramite_id = :tid AND requisito_activo = 1
    ");
    $stmt->execute([':tid' => $tramite_id]);
    $allReqs = array_map(function($r){ return (int)$r['requisito_id']; }, $stmt->fetchAll());
    if ($allReqs) {
        $ins = $db->prepare("
            INSERT INTO requisitos_solicitud (solicitud_id, requisito_id, requisitos_solicitud_status)
            VALUES (:sid, :rid, :st)
        ");
        foreach ($allReqs as $rid) {
            $st = in_array($rid, $seleccionados, true) ? 'aprobado' : 'pendiente';
            $ins->execute([':sid' => $solicitud_id, ':rid' => $rid, ':st' => $st]);
        }
    }
    if ($codigo_interno !== null && $codigo_interno !== '') {
        $stmt = $db->prepare("UPDATE solicitudes SET codigo_interno = :cod WHERE solicitud_id = :sid");
        $stmt->execute([':cod' => $codigo_interno, ':sid' => $solicitud_id]);
    }
    $startFlag = isset($_POST['start']) && $_POST['start'] === '1';
    if ($startFlag || $estado_actual === 'pendiente') {
        $stmt = $db->prepare("UPDATE solicitudes SET solicitud_estado = 'en_revision', empleado_asignado_id = :emp_id WHERE solicitud_id = :sid");
        $stmt->execute([':emp_id' => $_SESSION['user_id'], ':sid' => $solicitud_id]);
        registrarAuditoria(
            $_SESSION['user_id'],
            $solicitud_id,
            'INICIO_TRAMITE',
            'Trámite iniciado por el funcionario',
            ['codigo_interno' => $codigo_interno, 'aprobados' => $seleccionados]
        );
    }
    $caracas_action = isset($_POST['caracas_action']) ? $_POST['caracas_action'] : '';
    $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : null;
    registrarAuditoria(
        $_SESSION['user_id'],
        $solicitud_id,
        'GUARDAR_PROGRESO',
        'Requisitos actualizados por el funcionario',
        ['aprobados' => $seleccionados, 'codigo_interno' => $codigo_interno, 'observaciones' => $observaciones]
    );
    if ($caracas_action === 'enviar') {
        registrarAuditoria(
            $_SESSION['user_id'],
            $solicitud_id,
            'ENVIADO_CARACAS',
            'Trámite enviado a Caracas',
            ['codigo_interno' => $codigo_interno, 'observaciones' => $observaciones]
        );
    } elseif ($caracas_action === 'recibir') {
        registrarAuditoria(
            $_SESSION['user_id'],
            $solicitud_id,
            'RECIBIDO_CARACAS',
            'Trámite recibido de Caracas',
            ['codigo_interno' => $codigo_interno, 'observaciones' => $observaciones]
        );
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar progreso: ' . $e->getMessage()]);
}
?> 
