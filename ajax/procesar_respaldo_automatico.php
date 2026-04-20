<?php
/**
 * Comprueba configuracion_sistema.respaldo_automatico (JSON) y ejecuta un respaldo
 * cuando activado=true, la hora coincide (zona America/Caracas) y no se ejecutó ya
 * esa misma franja horaria hoy (evita repeticiones en el mismo minuto / peticiones concurrentes).
 *
 * Usa la misma generación SQL que el botón manual: backup_generarArchivoSql().
 */
date_default_timezone_set('America/Caracas');
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/backup_respaldo_lib.php';
require_once __DIR__ . '/../includes/telegram_backup.php';

/** Segundos: ventana tras guardar en el panel para permitir prueba aunque ultimo sea hoy (legacy) */
define('RESPALDO_AUTO_VENTANA_CONFIG_SEG', 300);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();
    $cfg = backup_getRespaldoAutomatico($db);

    if (empty($cfg['activado'])) {
        echo json_encode(['success' => true, 'ejecutado' => false, 'razon' => 'desactivado']);
        exit;
    }

    $horaCfg = backup_normalizar_hora_24h($cfg['hora'] ?? '02:00');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $horaCfg, $mp)) {
        $horaCfg = '02:00';
        preg_match('/^(\d{1,2}):(\d{2})$/', $horaCfg, $mp);
    }
    $hProg = (int) $mp[1];
    $mProg = (int) $mp[2];
    $inicioVentana = mktime($hProg, $mProg, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
    $finVentana = $inicioVentana + 59;
    $ts = time();
    $dentroDelMinutoProgramado = ($ts >= $inicioVentana && $ts <= $finVentana);

    if (!$dentroDelMinutoProgramado) {
        echo json_encode([
            'success' => true,
            'ejecutado' => false,
            'razon' => 'fuera_de_hora',
            'hora_servidor_actual' => date('H:i'),
            'hora_servidor_actual_seg' => date('H:i:s'),
            'hora_programada_leida' => $horaCfg,
            'zona_servidor' => date_default_timezone_get(),
            'ventana_minuto_desde' => date('Y-m-d H:i:s', $inicioVentana),
            'ventana_minuto_hasta' => date('Y-m-d H:i:s', $finVentana),
            'timestamp_servidor' => $ts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hoy = date('Y-m-d');
    $slotKey = $hoy . '|' . $horaCfg;
    $ultimoSlot = isset($cfg['ultimo_auto_slot']) ? trim((string)$cfg['ultimo_auto_slot']) : '';

    if ($ultimoSlot !== '' && $ultimoSlot === $slotKey) {
        echo json_encode([
            'success' => true,
            'ejecutado' => false,
            'razon' => 'ya_ejecutado_esta_hora',
            'slot' => $slotKey,
        ]);
        exit;
    }

    $ultimo = isset($cfg['ultimo_respaldo_fecha']) ? trim((string)$cfg['ultimo_respaldo_fecha']) : '';
    if ($ultimoSlot === '' && $ultimo === $hoy) {
        $configReciente = false;
        if (!empty($cfg['config_actualizado_en'])) {
            $ts = strtotime($cfg['config_actualizado_en']);
            if ($ts !== false && (time() - $ts) <= RESPALDO_AUTO_VENTANA_CONFIG_SEG) {
                $configReciente = true;
            }
        }
        if (!$configReciente) {
            echo json_encode(['success' => true, 'ejecutado' => false, 'razon' => 'ya_respaldo_hoy_legacy']);
            exit;
        }
    }

    $result = backup_generarArchivoSql($db);
    if (!$result['success']) {
        echo json_encode(['success' => false, 'ejecutado' => false, 'message' => 'Error al generar respaldo automático']);
        exit;
    }

    $fechaStr = date('Y-m-d H:i:s');
    $tamanioStr = backup_formatoTamano($result['tamanio']);

    $tgEstado = null;
    $tgMsg = '';
    if (telegram_esta_configurado()) {
        $up = enviarRespaldoTelegram($result['filepath']);
        if ($up['success']) {
            $tgEstado = 'ok';
            backup_guardarEstadoTelegram($db, [
                'ultimo_intento_fecha' => $fechaStr,
                'ultimo_exito' => true,
                'ultimo_mensaje' => 'Enviado a Telegram (respaldo automático)',
                'ultimo_archivo' => $result['filename'],
            ]);
        } else {
            $tgEstado = 'error';
            $tgMsg = (string) ($up['message'] ?? '');
            backup_guardarEstadoTelegram($db, [
                'ultimo_intento_fecha' => $fechaStr,
                'ultimo_exito' => false,
                'ultimo_mensaje' => $tgMsg,
                'ultimo_archivo' => $result['filename'],
            ]);
        }
    } else {
        $tgEstado = 'omitido';
        backup_guardarEstadoTelegram($db, [
            'ultimo_intento_fecha' => $fechaStr,
            'ultimo_exito' => null,
            'ultimo_mensaje' => 'Telegram no configurado',
            'ultimo_archivo' => $result['filename'],
        ]);
    }
    backup_addToHistorial($db, $fechaStr, $tamanioStr, 'Completado', $result['filename'], $tgEstado, $tgEstado === 'error' ? $tgMsg : null);

    backup_saveRespaldoAutomatico($db, [
        'ultimo_respaldo_fecha' => $hoy,
        'ultimo_auto_slot' => $slotKey,
    ]);

    echo json_encode([
        'success' => true,
        'ejecutado' => true,
        'message' => 'Respaldo automático generado',
        'archivo' => $result['filename'],
        'fecha' => $fechaStr,
        'slot' => $slotKey,
        'telegram_sync' => $tgEstado,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('procesar_respaldo_automatico: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
