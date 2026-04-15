<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();
    $roles = $db->query("SELECT rol_id, rol_nombre FROM roles ORDER BY rol_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $coordinaciones = $db->query("SELECT coordinacion_id, coordinacion_nombre FROM coordinacion WHERE coordinacion_estado='activo' ORDER BY coordinacion_nombre")->fetchAll(PDO::FETCH_ASSOC);
    $estados = $db->query("SELECT estado_id, estado_nombre FROM estados ORDER BY estado_nombre")->fetchAll(PDO::FETCH_ASSOC);
    $municipios = $db->query("SELECT municipio_id, municipio_nombre, estado_id FROM municipios ORDER BY municipio_nombre")->fetchAll(PDO::FETCH_ASSOC);
    $instituciones = $db->query("SELECT institucion_id, institucion_nombre FROM institucion ORDER BY institucion_nombre")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'roles' => $roles, 'coordinaciones' => $coordinaciones, 'estados' => $estados, 'municipios' => $municipios, 'instituciones' => $instituciones], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("admin_get_metadata: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
