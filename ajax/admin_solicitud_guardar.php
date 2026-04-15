<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$solicitud_id = isset($_POST['solicitud_id']) ? (int) $_POST['solicitud_id'] : 0;
$solicitud_estado = trim($_POST['solicitud_estado'] ?? '');
$tramite_id = isset($_POST['tramite_id']) ? (int) $_POST['tramite_id'] : 0;

$estados_validos = ['pendiente', 'en_revision', 'aprobada', 'rechazada', 'completada', 'redirigida'];

if ($solicitud_id < 1 || !in_array($solicitud_estado, $estados_validos, true) || $tramite_id < 1) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos o no válidos']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT s.ciudadano_identificacion, s.solicitud_numero FROM solicitudes s WHERE s.solicitud_id = :id");
    $stmt->execute([':id' => $solicitud_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }
    $ciudadano_id = $row['ciudadano_identificacion'];
    $numero = $row['solicitud_numero'] ?? '';

    $stmt = $db->prepare("SELECT coordinacion_id FROM tramite WHERE tramite_id = :tid");
    $stmt->execute([':tid' => $tramite_id]);
    $trow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trow) {
        echo json_encode(['success' => false, 'message' => 'Tipo de trámite no válido']);
        exit;
    }

    $db->beginTransaction();

    $hasCoordAct = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordAct = (bool) $chk->fetchColumn();
    } catch (Exception $e) {
    }

    if ($hasCoordAct) {
        $updS = $db->prepare("
            UPDATE solicitudes SET
                tramite_id = :tid,
                solicitud_estado = :est,
                coordinacion_actual_id = :caid
            WHERE solicitud_id = :sid
        ");
        $updS->execute([
            ':tid' => $tramite_id,
            ':est' => $solicitud_estado,
            ':caid' => (int) $trow['coordinacion_id'],
            ':sid' => $solicitud_id,
        ]);
    } else {
        $updS = $db->prepare("
            UPDATE solicitudes SET tramite_id = :tid, solicitud_estado = :est WHERE solicitud_id = :sid
        ");
        $updS->execute([
            ':tid' => $tramite_id,
            ':est' => $solicitud_estado,
            ':sid' => $solicitud_id,
        ]);
    }

    $detalle = 'Actualización administrativa del trámite / estado de la solicitud ' . $numero . ' (datos personales no modificados).';
    $stmt = $db->prepare("
        INSERT INTO detalles_solicitud (solicitud_id, detalle_texto, creado_por)
        VALUES (:sid, :txt, :uid)
    ");
    $stmt->execute([
        ':sid' => $solicitud_id,
        ':txt' => $detalle,
        ':uid' => $_SESSION['user_id'],
    ]);

    $db->commit();

    registrarAuditoria(
        $_SESSION['user_id'],
        $solicitud_id,
        'DATOS_ACTUALIZADOS',
        'Estado del trámite y tipo de trámite actualizados por administración.',
        [
            'solicitud_numero' => $numero,
            'ciudadano_identificacion' => $ciudadano_id,
            'solicitud_estado' => $solicitud_estado,
            'tramite_id' => $tramite_id,
        ]
    );

    echo json_encode(['success' => true, 'message' => 'Cambios guardados correctamente'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('admin_solicitud_guardar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
