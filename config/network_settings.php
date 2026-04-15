<?php
/**
 * Configuración de red — intranet CNE (un solo lugar para el host del servidor).
 *
 * CNE_MANUAL_SERVER_HOST:
 *   Deje vacío ('') para detección automática según la URL con la que abren la app.
 *   Si necesita forzar la IP del servidor (acceso mixto o proxy), escríbala aquí:
 *   Ejemplo: '192.168.1.50'
 */
$CNE_MANUAL_SERVER_HOST = '';

if (!function_exists('cne_intranet_public_host')) {
    /**
     * Host público del servidor (misma máquina que sirve la aplicación por HTTP/HTTPS).
     */
    function cne_intranet_public_host(): string
    {
        $manual = trim($GLOBALS['CNE_MANUAL_SERVER_HOST'] ?? '');
        if ($manual !== '') {
            return $manual;
        }

        $raw = $_SERVER['HTTP_HOST'] ?? '';
        if ($raw === '') {
            $raw = $_SERVER['SERVER_NAME'] ?? 'localhost';
        }

        $host = preg_replace('/:\d+$/', '', $raw);

        $lower = strtolower($host);
        $localMarkers = ['localhost', '127.0.0.1', '[::1]', '::1'];
        if (in_array($lower, $localMarkers, true)) {
            return 'localhost';
        }

        return $host;
    }
}
