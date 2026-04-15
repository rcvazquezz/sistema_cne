<?php

declare(strict_types=1);

/**
 * Server-Sent Events (SSE) — tiempo real sin WebSocket (mismo puerto HTTP/HTTPS).
 * Consulta periódica a BD: nuevas filas en auditoría y notificaciones acordes al rol.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/config/database.php';

$usuarioId = (string) $_SESSION['user_id'];
$rolId = (int) ($_SESSION['rol_id'] ?? 0);

$db = getDB();
$userRow = obtenerUsuario($usuarioId);
$coordId = (int) ($userRow['coordinacion_id'] ?? 0);

if (!isset($_SESSION['sse_last_auditoria_id'])) {
    $stmt = $db->query('SELECT COALESCE(MAX(auditoria_id), 0) AS m FROM auditoria');
    $_SESSION['sse_last_auditoria_id'] = (int) $stmt->fetchColumn();
}
if (!isset($_SESSION['sse_last_notificacion_id'])) {
    $stmt = $db->query('SELECT COALESCE(MAX(notificacion_id), 0) AS m FROM notificaciones');
    $_SESSION['sse_last_notificacion_id'] = (int) $stmt->fetchColumn();
}

session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

set_time_limit(0);
ignore_user_abort(false);

$tieneDestinatarioRol = false;
try {
    $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones' AND COLUMN_NAME = 'destinatario_rol_id'");
    $tieneDestinatarioRol = (bool) $chk->fetchColumn();
} catch (Exception $e) {
    $tieneDestinatarioRol = false;
}

$emitir = static function (array $payload): void {
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    echo 'data: ' . $line . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

while (!connection_aborted()) {
    session_start();
    $lastAud = (int) ($_SESSION['sse_last_auditoria_id'] ?? 0);
    $lastNotif = (int) ($_SESSION['sse_last_notificacion_id'] ?? 0);
    session_write_close();

    try {
        $stmt = $db->prepare('SELECT auditoria_id, solicitud_id, accion_codigo FROM auditoria WHERE auditoria_id > :id ORDER BY auditoria_id ASC LIMIT 50');
        $stmt->execute([':id' => $lastAud]);
        $audRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($audRows as $row) {
            $aid = (int) ($row['auditoria_id'] ?? 0);
            if ($aid > $lastAud) {
                $lastAud = $aid;
            }
            $acc = normalizarAccionAuditoria($row['accion_codigo'] ?? '');
            // Trámite inmediato al crear: mismo código que completada inicial; no emitir SSE (historial al recargar).
            if ($acc === 'SOLICITUD_COMPLETADA') {
                continue;
            }
            $emitir([
                'ok' => true,
                'event' => [
                    'type' => 'auditoria',
                    'solicitud_id' => (int) ($row['solicitud_id'] ?? 0),
                    'accion_codigo' => $acc,
                    'accion_label' => presentarAccionAuditoriaUi($acc),
                ],
            ]);
        }

        if ($rolId === 2 && $coordId > 0) {
            $stmt = $db->prepare('
                SELECT notificacion_id FROM notificaciones
                WHERE notificacion_id > :last AND coordinacion_id = :cid AND usuario_id IS NULL
                ORDER BY notificacion_id ASC LIMIT 50
            ');
            $stmt->execute([':last' => $lastNotif, ':cid' => $coordId]);
            $nrows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach ($nrows as $nid) {
                $nid = (int) $nid;
                if ($nid > $lastNotif) {
                    $lastNotif = $nid;
                }
                $emitir([
                    'ok' => true,
                    'event' => ['type' => 'notification_hint'],
                ]);
            }
        } elseif ($rolId === 5 && $tieneDestinatarioRol) {
            $stmt = $db->prepare('
                SELECT notificacion_id FROM notificaciones
                WHERE notificacion_id > :last AND destinatario_rol_id = 5
                ORDER BY notificacion_id ASC LIMIT 50
            ');
            $stmt->execute([':last' => $lastNotif]);
            $nrows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach ($nrows as $nid) {
                $nid = (int) $nid;
                if ($nid > $lastNotif) {
                    $lastNotif = $nid;
                }
                $emitir([
                    'ok' => true,
                    'event' => ['type' => 'notification_hint'],
                ]);
            }
        }
    } catch (Exception $e) {
        error_log('stream.php: ' . $e->getMessage());
    }

    session_start();
    $_SESSION['sse_last_auditoria_id'] = $lastAud;
    $_SESSION['sse_last_notificacion_id'] = $lastNotif;
    session_write_close();

    echo ': keepalive ' . time() . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();

    sleep(3);
}
