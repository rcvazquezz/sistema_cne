<?php
/**
 * Inyecta window.CNE_REALTIME + script cliente (omitido si realtime deshabilitado).
 * Antes del include definir: $CNE_RT = ['dashboard' => 'admin|empleado|director|entrada', 'coord' => int];
 */
if (!isset($_SESSION['user_id'])) {
    return;
}
$base = dirname(__DIR__) . '/config';
$cfg = is_file($base . '/realtime.php') ? require $base . '/realtime.php' : ['enabled' => false];
if (is_file($base . '/realtime.local.php')) {
    $loc = require $base . '/realtime.local.php';
    if (is_array($loc)) {
        $cfg = array_replace($cfg, $loc);
    }
}
if (empty($cfg['enabled'])) {
    echo '<script>window.CNE_REALTIME={enabled:false};</script>';
    return;
}
$rt = is_array($CNE_RT ?? null) ? $CNE_RT : [];
$streamPath = isset($cfg['sse_path']) ? trim((string) $cfg['sse_path']) : 'stream.php';
if ($streamPath === '') {
    $streamPath = 'stream.php';
}
/** Base URL del proyecto respecto al host (p. ej. /sistema_cne) para rutas absolutas y subcarpetas */
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$appBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($streamPath !== '' && $streamPath[0] === '/') {
    $streamUrl = $streamPath;
} else {
    $streamUrl = ($appBase === '' ? '' : $appBase) . '/' . ltrim($streamPath, '/');
}
$jsSrc = ($appBase === '' ? '' : $appBase) . '/recursos/js/cne_realtime.js?v=1.1';
$payload = [
    'enabled' => true,
    'streamUrl' => $streamUrl,
    'user' => (string) $_SESSION['user_id'],
    'rol' => (int) ($_SESSION['rol_id'] ?? 0),
    'coord' => (int) ($rt['coord'] ?? 0),
    'dashboard' => (string) ($rt['dashboard'] ?? 'app'),
];
echo '<script>window.CNE_REALTIME=' . json_encode($payload, JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
echo '<script src="' . htmlspecialchars($jsSrc, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
