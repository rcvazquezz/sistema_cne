<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$sid = isset($_GET['solicitud_id']) ? (int) $_GET['solicitud_id'] : 0;
if ($sid < 1) {
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            s.solicitud_id,
            s.solicitud_numero,
            s.solicitud_estado,
            s.tramite_id,
            s.ciudadano_identificacion,
            t.coordinacion_id,
            t.tramite_nombre
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        WHERE s.solicitud_id = :sid
    ");
    $stmt->execute([':sid' => $sid]);
    $sol = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sol) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT ciudadano_identificacion, ciudadano_nombres, ciudadano_apellidos, ciudadano_genero, estado_id, institucion_id
        FROM ciudadanos
        WHERE ciudadano_identificacion = :cid
    ");
    $stmt->execute([':cid' => $sol['ciudadano_identificacion']]);
    $ciu = $stmt->fetch(PDO::FETCH_ASSOC);

    $coordId = (int) $sol['coordinacion_id'];
    $stmt = $db->prepare("
        SELECT tramite_id, tramite_nombre, tramite_padre_id
        FROM tramite
        WHERE coordinacion_id = :c
        ORDER BY (tramite_padre_id IS NULL) DESC, tramite_nombre ASC
    ");
    $stmt->execute([':c' => $coordId]);
    $tramites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $coords = $db->query("SELECT coordinacion_id, coordinacion_nombre FROM coordinacion WHERE coordinacion_estado = 'activo' ORDER BY coordinacion_nombre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($coords as &$row) {
        $row['coordinacion_nombre'] = presentarNombreCoordinacionUi($row['coordinacion_nombre'] ?? '');
    }
    unset($row);
    $estados = $db->query("SELECT estado_id, estado_nombre FROM estados ORDER BY estado_nombre")->fetchAll(PDO::FETCH_ASSOC);

    $instituciones = $db->query("SELECT institucion_id, institucion_nombre FROM institucion ORDER BY institucion_nombre")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'solicitud' => [
            'solicitud_id' => (int) $sol['solicitud_id'],
            'solicitud_numero' => $sol['solicitud_numero'],
            'solicitud_estado' => $sol['solicitud_estado'],
            'tramite_id' => (int) $sol['tramite_id'],
            'coordinacion_id' => $coordId,
        ],
        'ciudadano' => $ciu ?: [
            'ciudadano_identificacion' => $sol['ciudadano_identificacion'],
            'ciudadano_nombres' => '',
            'ciudadano_apellidos' => '',
            'ciudadano_genero' => null,
            'estado_id' => null,
            'institucion_id' => null,
        ],
        'tramites' => $tramites,
        'coordinaciones' => $coords,
        'estados' => $estados,
        'instituciones' => $instituciones,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('admin_solicitud_edicion_datos: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar datos']);
}
