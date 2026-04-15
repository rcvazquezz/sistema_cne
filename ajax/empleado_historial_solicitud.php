<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$solicitud_id = $_POST['solicitud_id'] ?? '';
if (!$solicitud_id) {
    echo json_encode(['success' => false, 'message' => 'Solicitud no especificada']);
    exit;
}

/**
 * @param PDO $db
 */
function empleado_aud_fecha_column(PDO $db): string
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
    require_once __DIR__ . '/../includes/cne_admin_view_context.php';
    $usuario = cneObtenerUsuarioContextoSesion($_SESSION['user_id']);
    $mi_coordinacion_id = $usuario['coordinacion_id'] ?? null;
    if (!$mi_coordinacion_id) {
        echo json_encode(['success' => false, 'message' => 'Coordinación del usuario no definida']);
        exit;
    }
    $hasCoordActual = false;
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        $hasCoordActual = (bool) $chk->fetchColumn();
    } catch (Exception $e) {
        $hasCoordActual = false;
    }
    $coordExpr = $hasCoordActual ? 's.coordinacion_actual_id' : 't.coordinacion_id';
    $stmt = $db->prepare("
        SELECT 
            COALESCE(au.coord_destino, $coordExpr) AS coord_actual
        FROM solicitudes s
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
        WHERE s.solicitud_id = :sid
    ");
    $stmt->execute([':sid' => $solicitud_id]);
    $rowChk = $stmt->fetch();
    $coordActual = $rowChk ? (int) $rowChk['coord_actual'] : 0;
    $permitir = false;
    if ($coordActual === (int) $mi_coordinacion_id) {
        $permitir = true;
    } else {
        $stmt2 = $db->prepare("
            SELECT 1
            FROM auditoria a
            JOIN usuarios u ON u.user_identificacion = a.empleado_id
            WHERE a.solicitud_id = :sid
              AND u.coordinacion_id = :cid
            LIMIT 1
        ");
        $stmt2->execute([':sid' => $solicitud_id, ':cid' => $mi_coordinacion_id]);
        $permitir = (bool) $stmt2->fetchColumn();
    }
    if (!$permitir) {
        echo json_encode(['success' => false, 'message' => 'Acceso denegado: este trámite no pertenece a su coordinación']);
        exit;
    }

    $stMeta = $db->prepare('SELECT solicitud_numero FROM solicitudes WHERE solicitud_id = :id LIMIT 1');
    $stMeta->execute([':id' => $solicitud_id]);
    $meta = $stMeta->fetch(PDO::FETCH_ASSOC);
    if (!$meta) {
        echo json_encode(['success' => false, 'message' => 'La solicitud no existe']);
        exit;
    }

    $eventos = [];
    $audCol = empleado_aud_fecha_column($db);

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
        if ($tx === $ty) {
            return 0;
        }
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
    error_log('empleado_historial_solicitud: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al obtener el historial']);
}
