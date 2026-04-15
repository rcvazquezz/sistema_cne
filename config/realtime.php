<?php
/**
 * Configuración tiempo real vía SSE (stream.php en el mismo host/puerto HTTP).
 * Copie a realtime.local.php para overrides (no versionar credenciales).
 */
return [
    'enabled' => true,
    /** Ruta relativa al documento (misma carpeta que los dashboards) */
    'sse_path' => 'stream.php',
];
