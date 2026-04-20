<?php
/**
 * Respaldo SQL por cron (sin sesión web). Ejemplo en Windows (Programador de tareas):
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\sistema_cne\cron\backup_automatico.php
 *
 * Genera backup_YYYY-mm-dd_HH-ii-ss.sql y envía el archivo a Telegram si está configurado.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Solo ejecución por línea de comandos (CLI).';
    exit(1);
}

date_default_timezone_set('America/Caracas');

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/ajax/backup_respaldo_lib.php';
require_once dirname(__DIR__) . '/includes/telegram_backup.php';

try {
    $db = getDB();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error de base de datos: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$result = backup_generarArchivoSql($db);
if (!$result['success']) {
    fwrite(STDERR, 'No se pudo generar el archivo SQL.' . PHP_EOL);
    exit(1);
}

$fechaStr = date('Y-m-d H:i:s');
$filename = $result['filename'];
$filepath = $result['filepath'];
$tamanioStr = backup_formatoTamano($result['tamanio']);

$tgEstado = 'omitido';
$msg = null;
if (telegram_esta_configurado()) {
    $up = enviarRespaldoTelegram($filepath);
    if ($up['success']) {
        $tgEstado = 'ok';
        backup_guardarEstadoTelegram($db, [
            'ultimo_intento_fecha' => $fechaStr,
            'ultimo_exito' => true,
            'ultimo_mensaje' => 'Enviado a Telegram (cron)',
            'ultimo_archivo' => $filename,
        ]);
        fwrite(STDOUT, "Telegram: OK — {$filename}" . PHP_EOL);
    } else {
        $tgEstado = 'error';
        $msg = (string) ($up['message'] ?? 'Error desconocido');
        backup_guardarEstadoTelegram($db, [
            'ultimo_intento_fecha' => $fechaStr,
            'ultimo_exito' => false,
            'ultimo_mensaje' => $msg,
            'ultimo_archivo' => $filename,
        ]);
        fwrite(STDERR, 'Telegram: error — ' . $msg . PHP_EOL);
        telegram_log_respaldo('cron: ' . $msg);
    }
} else {
    backup_guardarEstadoTelegram($db, [
        'ultimo_intento_fecha' => $fechaStr,
        'ultimo_exito' => null,
        'ultimo_mensaje' => 'Telegram no configurado (cron)',
        'ultimo_archivo' => $filename,
    ]);
    fwrite(STDOUT, 'Telegram: omitido (sin configuración)' . PHP_EOL);
}

backup_addToHistorial($db, $fechaStr, $tamanioStr, 'Completado', $filename, $tgEstado, $tgEstado === 'error' ? $msg : null);

fwrite(STDOUT, "Respaldo local: {$filename} ({$tamanioStr})" . PHP_EOL);
exit(0);
