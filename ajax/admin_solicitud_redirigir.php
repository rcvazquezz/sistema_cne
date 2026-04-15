<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$solicitud_id = isset($_POST['solicitud_id']) ? (int) $_POST['solicitud_id'] : 0;
$destino = isset($_POST['destino_coordinacion_id']) ? (int) $_POST['destino_coordinacion_id'] : 0;
$motivo = trim($_POST['motivo'] ?? '');

if ($solicitud_id < 1 || $destino < 1) {
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos']);
    exit;
}

/**
 * @return int|null tramite_id en la coordinación destino
 */
function admin_resolver_tramite_destino(PDO $db, int $solicitud_id, int $destCoordId): ?int
{
    $st = $db->prepare("
        SELECT t.tramite_nombre
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        WHERE s.solicitud_id = :sid
    ");
    $st->execute([':sid' => $solicitud_id]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) {
        return null;
    }
    $nombre = $cur['tramite_nombre'];
    $st2 = $db->prepare("
        SELECT tramite_id FROM tramite
        WHERE coordinacion_id = :cid AND tramite_nombre = :nom
        ORDER BY (tramite_padre_id IS NULL) DESC
        LIMIT 1
    ");
    $st2->execute([':cid' => $destCoordId, ':nom' => $nombre]);
    $r = $st2->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        return (int) $r['tramite_id'];
    }
    $st3 = $db->prepare("
        SELECT tramite_id FROM tramite
        WHERE coordinacion_id = :cid AND tramite_padre_id IS NULL
        ORDER BY tramite_nombre ASC
        LIMIT 1
    ");
    $st3->execute([':cid' => $destCoordId]);
    $r3 = $st3->fetch(PDO::FETCH_ASSOC);

    return $r3 ? (int) $r3['tramite_id'] : null;
}

try {
    $db = getDB();

    if (coordinacionEsOficinaAtencionAlCiudadano($db, $destino)) {
        echo json_encode(['success' => false, 'message' => mensajeRedireccionAtencionCiudadanoProhibida()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare("
        SELECT s.solicitud_numero, t.tramite_nombre, t.coordinacion_id AS coord_origen
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        WHERE s.solicitud_id = :sid
    ");
    $stmt->execute([':sid' => $solicitud_id]);
    $meta = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meta) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    $coordOrigen = (int) $meta['coord_origen'];
    if ($coordOrigen === $destino) {
        echo json_encode(['success' => false, 'message' => 'Seleccione una coordinación distinta a la actual del trámite']);
        exit;
    }

    $nuevoTid = admin_resolver_tramite_destino($db, $solicitud_id, $destino);
    if (!$nuevoTid) {
        echo json_encode(['success' => false, 'message' => 'La coordinación destino no tiene trámites configurados']);
        exit;
    }

    $hasCoordAct = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordAct = (bool) $chk->fetchColumn();
    } catch (Exception $e) {
    }

    $db->beginTransaction();

    if ($hasCoordAct) {
        $up = $db->prepare("
            UPDATE solicitudes SET
                tramite_id = :tid,
                solicitud_estado = 'pendiente',
                empleado_asignado_id = NULL,
                coordinacion_actual_id = :caid
            WHERE solicitud_id = :sid
        ");
        $up->execute([':tid' => $nuevoTid, ':caid' => $destino, ':sid' => $solicitud_id]);
    } else {
        $up = $db->prepare("
            UPDATE solicitudes SET
                tramite_id = :tid,
                solicitud_estado = 'pendiente',
                empleado_asignado_id = NULL
            WHERE solicitud_id = :sid
        ");
        $up->execute([':tid' => $nuevoTid, ':sid' => $solicitud_id]);
    }

    $stNom = $db->prepare('SELECT coordinacion_nombre FROM coordinacion WHERE coordinacion_id = :cid');
    $stNom->execute([':cid' => $coordOrigen]);
    $origenNombre = ($stNom->fetch(PDO::FETCH_ASSOC) ?: [])['coordinacion_nombre'] ?? '';
    $stNom->execute([':cid' => $destino]);
    $destinoNombre = ($stNom->fetch(PDO::FETCH_ASSOC) ?: [])['coordinacion_nombre'] ?? '';
    $origenUi = presentarNombreCoordinacionUi($origenNombre !== '' ? $origenNombre : null);
    if ($origenUi === '') {
        $origenUi = $origenNombre;
    }
    $destinoUi = presentarNombreCoordinacionUi($destinoNombre !== '' ? $destinoNombre : null);
    if ($destinoUi === '') {
        $destinoUi = $destinoNombre;
    }

    $numero = $meta['solicitud_numero'] ?? '';
    $tramiteNom = $meta['tramite_nombre'] ?? '';
    $textoMotivo = $motivo !== '' ? $motivo : 'Redirección administrativa';
    $mensaje = "Trámite redirigido: {$numero} - {$tramiteNom}. Proveniente de: {$origenUi}. Motivo: {$textoMotivo}";

    $stmt = $db->prepare("
        INSERT INTO notificaciones (
            usuario_id, coordinacion_id, solicitud_id, notificacion_titulo, mensaje, notificacion_estado
        ) VALUES (NULL, :coord_id, :sol_id, 'Trámite redirigido', :mensaje, 'no_leido')
    ");
    $stmt->execute([
        ':coord_id' => $destino,
        ':sol_id' => $solicitud_id,
        ':mensaje' => $mensaje,
    ]);

    $detalle = "Redirección administrativa de {$origenUi} a {$destinoUi}. {$textoMotivo}";
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

    pushRealtimeEnvelope([
        'targets' => ['coordinacion_ids' => [(int) $destino]],
        'event' => ['type' => 'notification_hint'],
    ]);

    $accion_desc = "Trámite redirigido desde {$origenUi} hacia {$destinoUi}. {$textoMotivo}";
    $detalle_extra = [
        'coordinacion_origen' => $origenNombre,
        'coordinacion_destino' => $destinoNombre,
        'observaciones' => $textoMotivo,
        'solicitud_numero' => $numero,
        'tramite_nombre' => $tramiteNom,
    ];
    registrarAuditoria(
        $_SESSION['user_id'],
        $solicitud_id,
        'REDIRECCION',
        $accion_desc,
        $detalle_extra
    );

    echo json_encode(['success' => true, 'message' => 'Trámite redirigido correctamente'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('admin_solicitud_redirigir: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al redirigir: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
