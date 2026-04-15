<?php
/**
 * Script de verificación del esquema de base de datos.
 * Ejecutar para validar que la BD coincide con cne_sistema.sql
 */
require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Verificación del esquema cne_sistema</h2><pre>";

$db = getDB();
$errores = [];
$ok = [];

// Estructura esperada según cne_sistema.sql (después de ALTERs)
$esperado = [
    'usuarios' => ['user_identificacion', 'user_username', 'user_password_hash', 'user_nombres', 'user_apellidos', 'user_email', 'rol_id', 'coordinacion_id', 'user_estado', 'user_ultima_conexion', 'user_created_at'],
    'tramite' => ['tramite_id', 'tramite_nombre', 'coordinacion_id', 'tramite_padre_id', 'tramite_created_at'],
    'requisitos' => ['requisito_id', 'tramite_id', 'requisito_nombre', 'requisito_activo', 'requisito_created_at'],
    'coordinacion' => ['coordinacion_id', 'coordinacion_nombre', 'coordinacion_descripcion', 'coordinacion_estado', 'coordinacion_created_at'],
    'roles' => ['rol_id', 'rol_nombre', 'rol_descripcion', 'rol_created_at'],
];

foreach ($esperado as $tabla => $columnas) {
    try {
        $stmt = $db->query("DESCRIBE `$tabla`");
        $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $faltan = array_diff($columnas, $actual);
        $sobran = array_diff($actual, $columnas);
        if (!empty($faltan)) {
            $errores[] = "Tabla $tabla: columnas faltantes: " . implode(', ', $faltan);
        } else {
            $ok[] = "Tabla $tabla: OK";
        }
        if (!empty($sobran) && $tabla === 'tramite' && in_array('tramite_duracion_estimada_dias', $sobran)) {
            $errores[] = "Tabla tramite: aún tiene tramite_duracion_estimada_dias. Ejecute: ALTER TABLE tramite DROP COLUMN tramite_duracion_estimada_dias;";
        }
    } catch (Exception $e) {
        $errores[] = "Tabla $tabla: " . $e->getMessage();
    }
}

foreach ($ok as $m) echo "[OK] $m\n";
foreach ($errores as $m) echo "[ERROR] $m\n";

if (empty($errores)) {
    echo "\nEsquema correcto. El dashboard admin debería funcionar.\n";
} else {
    echo "\nCorrija los errores anteriores. Consulte cne_sistema.sql y ejecute los ALTER necesarios.\n";
}
echo "</pre>";
?>
