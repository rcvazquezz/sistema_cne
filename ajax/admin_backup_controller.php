<?php
date_default_timezone_set('America/Caracas');
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/backup_respaldo_lib.php';
require_once __DIR__ . '/../includes/telegram_backup.php';

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
            $tgUlt = backup_obtenerEstadoTelegram($db);
            $tgUlt['configurado'] = telegram_esta_configurado();
            echo json_encode([
                'success' => true,
                'config' => [
                    'hora' => $cfg['hora'],
                    'activado' => $cfg['activado'],
                    'ultimo_respaldo_fecha' => $cfg['ultimo_respaldo_fecha'],
                    'ultimo_auto_slot' => $cfg['ultimo_auto_slot'] ?? null,
                ],
                'historial' => is_array($historial) ? $historial : [],
                'telegram_ultimo' => $tgUlt,
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
            $tgUlt = backup_obtenerEstadoTelegram($db);
            $tgUlt['configurado'] = telegram_esta_configurado();
            echo json_encode([
                'success' => true,
                'historial' => is_array($historial) ? $historial : [],
                'telegram_ultimo' => $tgUlt,
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

            $tgEstado = null;
            $tgMsg = '';
            if ($exportado) {
                if (telegram_esta_configurado()) {
                    $up = enviarRespaldoTelegram($filepath);
                    if ($up['success']) {
                        $tgEstado = 'ok';
                        backup_guardarEstadoTelegram($db, [
                            'ultimo_intento_fecha' => $fechaStr,
                            'ultimo_exito' => true,
                            'ultimo_mensaje' => 'Enviado a Telegram',
                            'ultimo_archivo' => $filename,
                        ]);
                    } else {
                        $tgEstado = 'error';
                        $tgMsg = (string) ($up['message'] ?? 'Error desconocido');
                        backup_guardarEstadoTelegram($db, [
                            'ultimo_intento_fecha' => $fechaStr,
                            'ultimo_exito' => false,
                            'ultimo_mensaje' => $tgMsg,
                            'ultimo_archivo' => $filename,
                        ]);
                    }
                } else {
                    $tgEstado = 'omitido';
                    backup_guardarEstadoTelegram($db, [
                        'ultimo_intento_fecha' => $fechaStr,
                        'ultimo_exito' => null,
                        'ultimo_mensaje' => 'Telegram no configurado (falta bot_token o chat_id)',
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
                $tgEstado,
                $tgEstado === 'error' ? $tgMsg : null
            );

            if (isset($_GET['descargar']) && $_GET['descargar'] === '1') {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . $tamanio);
                readfile($filepath);
                exit;
            }

            $mensajeCliente = 'Error al generar respaldo';
            if ($exportado) {
                if ($tgEstado === 'ok') {
                    $mensajeCliente = 'Respaldo generado localmente y enviado a Telegram';
                } elseif ($tgEstado === 'error') {
                    $mensajeCliente = 'Respaldo generado localmente. No se pudo enviar a Telegram: ' . $tgMsg;
                } elseif ($tgEstado === 'omitido') {
                    $mensajeCliente = 'Respaldo generado localmente. Telegram no está configurado.';
                } else {
                    $mensajeCliente = 'Respaldo generado correctamente';
                }
            }

            echo json_encode([
                'success' => $exportado,
                'message' => $mensajeCliente,
                'archivo' => $filename,
                'tamanio' => $tamanioStr,
                'fecha' => $fechaStr,
                'estado' => $estado,
                'telegram_sync' => $tgEstado,
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
