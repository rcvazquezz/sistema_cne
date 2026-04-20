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
$institucion_id_raw = trim((string) ($_POST['institucion_id'] ?? ''));
$requisitos_raw = $_POST['requisitos'] ?? '';

$estados_validos = ['pendiente', 'en_revision', 'aprobada', 'rechazada', 'completada', 'redirigida', 'vencida', 'invalidada'];

$seleccionados_req = [];
if ($requisitos_raw !== '' && $requisitos_raw !== null) {
    $tmp = json_decode((string) $requisitos_raw, true);
    if (is_array($tmp)) {
        $seleccionados_req = array_values(array_unique(array_filter(array_map('intval', $tmp))));
    }
}

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

    $institucion_id_val = null;
    if ($institucion_id_raw !== '') {
        $iid = (int) $institucion_id_raw;
        if ($iid < 1) {
            throw new Exception('Institución no válida');
        }
        $chkInst = $db->prepare('SELECT 1 FROM institucion WHERE institucion_id = ? LIMIT 1');
        $chkInst->execute([$iid]);
        if (!$chkInst->fetchColumn()) {
            throw new Exception('La institución indicada no existe en el catálogo');
        }
        $institucion_id_val = $iid;
    }

    $updCiu = $db->prepare('UPDATE ciudadanos SET institucion_id = :iid WHERE ciudadano_identificacion = :cid');
    $updCiu->execute([
        ':iid' => $institucion_id_val,
        ':cid' => $ciudadano_id,
    ]);

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

    $permiteEditarRequisitos = in_array(
        $solicitud_estado,
        ['en_revision', 'completada', 'redirigida', 'invalidada'],
        true
    );
    if ($permiteEditarRequisitos && $requisitos_raw !== '' && $requisitos_raw !== null) {
        $stmt = $db->prepare('SELECT requisito_id FROM requisitos WHERE tramite_id = :tid AND requisito_activo = 1');
        $stmt->execute([':tid' => $tramite_id]);
        $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($seleccionados_req as $rid) {
            if (!in_array($rid, $validIds, true)) {
                throw new Exception('Hay requisitos que no corresponden al tipo de trámite seleccionado');
            }
        }
        $db->prepare('DELETE FROM requisitos_solicitud WHERE solicitud_id = :sid')->execute([':sid' => $solicitud_id]);
        if ($validIds) {
            $ins = $db->prepare('
                INSERT INTO requisitos_solicitud (solicitud_id, requisito_id, requisitos_solicitud_status)
                VALUES (:sid, :rid, :st)
            ');
            foreach ($validIds as $rid) {
                $st = in_array($rid, $seleccionados_req, true) ? 'aprobado' : 'pendiente';
                $ins->execute([':sid' => $solicitud_id, ':rid' => $rid, ':st' => $st]);
            }
        }
    }

    $detalle = 'Actualización administrativa del trámite / estado de la solicitud ' . $numero . ' (institución del ciudadano actualizada si aplica).';
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
            'institucion_id' => $institucion_id_val,
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
