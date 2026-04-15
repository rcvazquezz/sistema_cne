<?php
/**
 * Estilos y utilidad JS para mostrar datos de ciudadanos en MAYÚSCULAS (visual).
 * Incluir una vez en <head> después de charset/viewport.
 */
if (defined('CNE_CIUDADANO_MAYUS_EMITTED')) {
    return;
}
define('CNE_CIUDADANO_MAYUS_EMITTED', true);

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$appBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$prefix = ($appBase === '' || $appBase === '/') ? '' : $appBase;
$v = '1.0.0';
$css = ($prefix !== '' ? $prefix . '/' : '') . 'recursos/css/cne_ciudadano_mayus.css?v=' . $v;
$js = ($prefix !== '' ? $prefix . '/' : '') . 'recursos/js/cne_ciudadano_mayus.js?v=' . $v;

echo '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">' . "\n";
echo '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
