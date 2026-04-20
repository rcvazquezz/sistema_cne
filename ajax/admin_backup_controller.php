<?php
date_default_timezone_set('America/Caracas');
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/backup_respaldo_lib.php';
require_once __DIR__ . '/../includes/email_backup.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    $db = getDB();

    switch ($action) {
        case 'get_config':
            $cfg = backup_getRespaldoAutomatico($db);
            $historial = backup_getOrCreateConfig($db, 'backup_historial', []);
            $mailUlt = backup_obtenerEstadoCorreo($db);
            $mailUlt['configurado'] = email_esta_configurado();
            echo json_encode([
                'success' => true,
                'config' => [
                    'hora' => $cfg['hora'],
                    'activado' => $cfg['activado'],
                    'ultimo_respaldo_fecha' => $cfg['ultimo_respaldo_fecha'],
                    'ultimo_auto_slot' => $cfg['ultimo_auto_slot'] ?? null,
                ],
                'historial' => is_array($historial) ? $historial : [],
                'email_ultimo' => $mailUlt,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_config':
            $hora = backup_normalizar_hora_24h(trim($_POST['hora'] ?? '02:00'));
            $activado = isset($_POST['activo']) && ($_POST['activo'] === '1' || $_POST['activo'] === 'true');
            backup_saveRespaldoAutomatico($db, [
                'activado' => $activado,
                'hora' => $hora,
                'config_actualizado_en' => date('Y-m-d H:i:s'),
            ]);
            echo json_encode(['success' => true, 'message' => 'Configuración guardada en configuracion_sistema (clave respaldo_automatico)']);
            break;

        case 'get_historial':
            $historial = backup_getOrCreateConfig($db, 'backup_historial', []);
            $mailUlt = backup_obtenerEstadoCorreo($db);
            $mailUlt['configurado'] = email_esta_configurado();
            echo json_encode([
                'success' => true,
                'historial' => is_array($historial) ? $historial : [],
                'email_ultimo' => $mailUlt,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'generar':
            $result = backup_generarArchivoSql($db);
            $exportado = $result['success'];
            $filename = $result['filename'];
            $filepath = $result['filepath'];
            $tamanio = $result['tamanio'];

            $fechaStr = date('Y-m-d H:i:s');
            $tamanioStr = backup_formatoTamano($tamanio);
            $estado = $exportado ? 'Completado' : 'Error';

            $soloDescarga = isset($_GET['descargar']) && $_GET['descargar'] === '1';
            if ($soloDescarga && $exportado && is_readable($filepath)) {
                backup_addToHistorial($db, $fechaStr, $tamanioStr, $estado, $filename, 'omitido', null);
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . (string) filesize($filepath));
                readfile($filepath);
                exit;
            }

            $mailEstado = null;
            $mailMsg = '';
            if ($exportado) {
                $dbName = backup_obtenerNombreBaseDatos($db);
                if (email_esta_configurado()) {
                    $up = enviarRespaldoPorEmail($filepath, $dbName);
                    if ($up['success']) {
                        $mailEstado = 'ok';
                        backup_guardarEstadoCorreo($db, [
                            'ultimo_intento_fecha' => $fechaStr,
                            'ultimo_exito' => true,
                            'ultimo_mensaje' => 'Enviado por correo electrónico',
                            'ultimo_archivo' => $filename,
                        ]);
                    } else {
                        $mailEstado = 'error';
                        $mailMsg = (string) ($up['message'] ?? 'Error desconocido');
                        backup_guardarEstadoCorreo($db, [
                            'ultimo_intento_fecha' => $fechaStr,
                            'ultimo_exito' => false,
                            'ultimo_mensaje' => $mailMsg,
                            'ultimo_archivo' => $filename,
                        ]);
                    }
                } else {
                    $mailEstado = 'omitido';
                    backup_guardarEstadoCorreo($db, [
                        'ultimo_intento_fecha' => $fechaStr,
                        'ultimo_exito' => null,
                        'ultimo_mensaje' => 'Correo no configurado (config/email_config.php)',
                        'ultimo_archivo' => $filename,
                    ]);
                }
            }

            backup_addToHistorial(
                $db,
                $fechaStr,
                $tamanioStr,
                $estado,
                $exportado ? $filename : null,
                $mailEstado,
                $mailEstado === 'error' ? $mailMsg : null
            );

            $mensajeCliente = 'Error al generar respaldo';
            if ($exportado) {
                if ($mailEstado === 'ok') {
                    $mensajeCliente = 'Respaldo generado y enviado por correo (archivo local eliminado tras el envío)';
                } elseif ($mailEstado === 'error') {
                    $mensajeCliente = 'Respaldo generado localmente. No se pudo enviar por correo: ' . $mailMsg;
                } elseif ($mailEstado === 'omitido') {
                    $mensajeCliente = 'Respaldo generado localmente. Configure SMTP en config/email_config.php para envío automático.';
                } else {
                    $mensajeCliente = 'Respaldo generado correctamente';
                }
            }

            $archivoRespuesta = null;
            if ($exportado && $mailEstado !== 'ok') {
                $archivoRespuesta = $filename;
            }

            echo json_encode([
                'success' => $exportado,
                'message' => $mensajeCliente,
                'archivo' => $archivoRespuesta,
                'tamanio' => $tamanioStr,
                'fecha' => $fechaStr,
                'estado' => $estado,
                'email_sync' => $mailEstado,
                'ruta_descarga' => 'ajax/admin_backup_controller.php?action=generar&descargar=1&ts=' . time(),
            ]);
            break;

        case 'descargar':
            $archivo = basename($_GET['archivo'] ?? '');
            if (!preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $archivo)) {
                echo json_encode(['success' => false, 'message' => 'Archivo no válido']);
                exit;
            }
            $filepath = dirname(__DIR__) . '/backups/' . $archivo;
            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'message' => 'Archivo no encontrado']);
                exit;
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $archivo . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    $errMsg = $e->getMessage();
    error_log("admin_backup_controller: " . $errMsg);
    echo json_encode(['success' => false, 'message' => $errMsg, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
}
