<?php

/**
 * Antes: empuje TCP al daemon Ratchet. Con SSE, los clientes son notificados
 * al sondear stream.php (auditoría / notificaciones en BD). Se mantiene la
 * función para no romper llamadas existentes; ya no abre sockets.
 */

if (!function_exists('pushRealtimeLogWebsocketError')) {
    function pushRealtimeLogWebsocketError(string $message, ?\Throwable $e = null): void
    {
        $logFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'error_websocket.log';
        $line = date('c') . ' ' . $message;
        if ($e !== null) {
            $line .= ' | ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        }
        $line .= "\n\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('pushRealtimeEnvelope')) {
    function pushRealtimeEnvelope(array $envelope): void
    {
        static $cfg;
        if ($cfg === null) {
            $base = dirname(__DIR__) . '/config';
            $cfg = require $base . '/realtime.php';
            if (is_file($base . '/realtime.local.php')) {
                $local = require $base . '/realtime.local.php';
                if (is_array($local)) {
                    $cfg = array_replace($cfg, $local);
                }
            }
        }
        if (empty($cfg['enabled'])) {
            return;
        }
        /* Los clientes SSE detectan cambios vía stream.php; no hay empuje activo aquí. */
    }
}
