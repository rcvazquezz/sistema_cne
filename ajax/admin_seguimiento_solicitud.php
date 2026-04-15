<?php
/**
 * Línea de tiempo (auditoría + observaciones) para administrador — misma lógica que director.
 */
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$solicitud_id = isset($_GET['solicitud_id']) ? (int) $_GET['solicitud_id'] : 0;
if ($solicitud_id < 1) {
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida']);
    exit;
}

function admin_seg_aud_fecha_column(PDO $db): string
{
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'fecha_creacion'");
        if ($chk && $chk->fetchColumn()) {
            return 'fecha_creacion';
        }
    } catch (Exception $e) {
    }
    return 'auditoria_created_at';
}

try {
    $db = getDB();

    $stMeta = $db->prepare('SELECT solicitud_numero FROM solicitudes WHERE solicitud_id = :id LIMIT 1');
    $stMeta->execute([':id' => $solicitud_id]);
    $meta = $stMeta->fetch(PDO::FETCH_ASSOC);
    if (!$meta) {
        echo json_encode(['success' => false, 'message' => 'La solicitud no existe']);
        exit;
    }

    $audCol = admin_seg_aud_fecha_column($db);
    $eventos = [];

    $sqlAud = "
        SELECT 
            a.accion_codigo,
            a.accion_descripcion,
            a.detalles,
            a.{$audCol} AS fecha_raw,
            DATE_FORMAT(a.{$audCol}, '%d/%m/%Y %h:%i %p') AS fecha_hora,
            TRIM(CONCAT(TRIM(COALESCE(u.user_nombres, '')), ' ', TRIM(COALESCE(u.user_apellidos, '')))) AS funcionario_nombre,
            a.empleado_id
        FROM auditoria a
        LEFT JOIN usuarios u ON u.user_identificacion = a.empleado_id
        WHERE a.solicitud_id = :sid
        ORDER BY a.{$audCol} ASC
    ";
    $stAud = $db->prepare($sqlAud);
    $stAud->execute([':sid' => $solicitud_id]);
    foreach ($stAud->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $accionUi = presentarAccionAuditoriaUi($a['accion_codigo'] ?? '');
        if ($accionUi === '') {
            $accionUi = 'REGISTRO';
        }
        $desc = presentarDescripcionAuditoriaUi($a['accion_descripcion'] ?? '');
        $desc = enriquecerDescripcionAuditoriaCoordinacionLegacy($db, $desc, $a['detalles'] ?? null);
        if ($desc === '') {
            $desc = ($accionUi !== '' && $accionUi !== 'REGISTRO') ? $accionUi : 'Registro de auditoría';
        }
        $fn = trim((string) ($a['funcionario_nombre'] ?? ''));
        if ($fn === '' && !empty($a['empleado_id'])) {
            $fn = $a['empleado_id'];
        }
        if ($fn === '') {
            $fn = 'No registrado';
        }
        $eventos[] = [
            'tipo' => 'auditoria',
            'accion' => $accionUi,
            'descripcion' => $desc,
            'funcionario' => $fn,
            'fecha_hora' => $a['fecha_hora'],
            'orden' => $a['fecha_raw'],
        ];
    }

    $stDet = $db->prepare("
        SELECT 
            d.detalle_texto,
            d.detalle_created_at AS fecha_raw,
            DATE_FORMAT(d.detalle_created_at, '%d/%m/%Y %h:%i %p') AS fecha_hora,
            TRIM(CONCAT(TRIM(COALESCE(u.user_nombres, '')), ' ', TRIM(COALESCE(u.user_apellidos, '')))) AS funcionario_nombre,
            d.creado_por
        FROM detalles_solicitud d
        LEFT JOIN usuarios u ON u.user_identificacion = d.creado_por
        WHERE d.solicitud_id = :sid
        ORDER BY d.detalle_created_at ASC
    ");
    $stDet->execute([':sid' => $solicitud_id]);
    foreach ($stDet->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $texto = trim((string) ($d['detalle_texto'] ?? ''));
        $fn = trim((string) ($d['funcionario_nombre'] ?? ''));
        if ($fn === '' && !empty($d['creado_por'])) {
            $fn = $d['creado_por'];
        }
        if ($fn === '') {
            $fn = 'No registrado';
        }
        $eventos[] = [
            'tipo' => 'detalle',
            'accion' => presentarAccionAuditoriaUi('OBSERVACION'),
            'descripcion' => $texto !== '' ? $texto : 'Observación registrada',
            'funcionario' => $fn,
            'fecha_hora' => $d['fecha_hora'],
            'orden' => $d['fecha_raw'],
        ];
    }

    usort($eventos, function ($x, $y) {
        $tx = strtotime((string) ($x['orden'] ?? '')) ?: 0;
        $ty = strtotime((string) ($y['orden'] ?? '')) ?: 0;
        return $tx <=> $ty;
    });

    foreach ($eventos as &$ev) {
        unset($ev['orden']);
    }
    unset($ev);

    echo json_encode([
        'success' => true,
        'solicitud_numero' => $meta['solicitud_numero'],
        'eventos' => $eventos,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('admin_seguimiento_solicitud: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar el seguimiento']);
}
