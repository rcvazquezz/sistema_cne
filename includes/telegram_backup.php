<?php
/**
 * Envío de respaldos .sql a Telegram (sendDocument) mediante cURL.
 */
declare(strict_types=1);

function telegram_obtener_config(): array
{
    $path = dirname(__DIR__) . '/config/telegram_config.php';
    if (!is_file($path)) {
        return ['bot_token' => '', 'chat_id' => '', 'nombre_instancia' => ''];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : ['bot_token' => '', 'chat_id' => '', 'nombre_instancia' => ''];
}

function telegram_log_respaldo(string $mensaje): void
{
    error_log('RespaldoTelegram: ' . $mensaje);
}

function telegram_esta_configurado(): bool
{
    $c = telegram_obtener_config();
    $token = trim((string) ($c['bot_token'] ?? ''));
    $chat = trim((string) ($c['chat_id'] ?? ''));
    return $token !== '' && $chat !== '';
}

/**
 * @return array{success: bool, message: string}
 */
function enviarRespaldoTelegram(string $ruta_archivo_sql): array
{
    if (!is_readable($ruta_archivo_sql)) {
        $msg = 'Archivo no legible: ' . $ruta_archivo_sql;
        telegram_log_respaldo($msg);
        return ['success' => false, 'message' => $msg];
    }

    $cfg = telegram_obtener_config();
    $token = trim((string) ($cfg['bot_token'] ?? ''));
    $chatId = trim((string) ($cfg['chat_id'] ?? ''));
    $nombreInstancia = trim((string) ($cfg['nombre_instancia'] ?? 'Sistema CNE'));

    if ($token === '' || $chatId === '') {
        $msg = 'telegram_config.php: faltan bot_token o chat_id.';
        telegram_log_respaldo($msg);
        return ['success' => false, 'message' => $msg];
    }

    date_default_timezone_set('America/Caracas');
    $fechaHora = date('Y-m-d H:i:s');
    $caption = $nombreInstancia . ' — Fecha y hora del respaldo: ' . $fechaHora;

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendDocument';

    if (!function_exists('curl_init')) {
        $msg = 'cURL no está habilitado en PHP.';
        telegram_log_respaldo($msg);
        return ['success' => false, 'message' => $msg];
    }

    $nombre = basename($ruta_archivo_sql);
    $curlFile = new CURLFile($ruta_archivo_sql, 'application/sql', $nombre);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'document' => $curlFile,
            'caption' => $caption,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        $detalle = 'cURL #' . $errno . ': ' . $err . ' (HTTP ' . $http . ')';
        telegram_log_respaldo($detalle);
        return ['success' => false, 'message' => $detalle];
    }

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        $detalle = 'Respuesta no JSON. HTTP ' . $http . ' — cuerpo: ' . substr((string) $raw, 0, 500);
        telegram_log_respaldo($detalle);
        return ['success' => false, 'message' => $detalle];
    }

    if (!empty($json['ok'])) {
        telegram_log_respaldo('Envío OK: ' . $nombre);
        return ['success' => true, 'message' => 'Enviado a Telegram'];
    }

    $desc = (string) ($json['description'] ?? 'Error API Telegram');
    $detalle = $desc . ' (HTTP ' . $http . ')';
    if ($raw !== false && $raw !== '') {
        telegram_log_respaldo('API error: ' . substr((string) $raw, 0, 800));
    } else {
        telegram_log_respaldo('API error: ' . $detalle);
    }
    return ['success' => false, 'message' => $detalle];
}
