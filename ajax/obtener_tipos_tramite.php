<?php
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['area_id']) || !is_numeric($_GET['area_id'])) {
    echo json_encode(['error' => 'ID de coordinación inválido']);
    exit;
}

$area_id = intval($_GET['area_id']);

try {
    $db = getDB();
    
    // Obtener los trámites padres activos de la coordinación seleccionada
    $stmt = $db->prepare("
        SELECT 
            tramite_id as id,
            tramite_nombre as nombre
        FROM tramite 
        WHERE coordinacion_id = :area_id AND tramite_padre_id IS NULL
        ORDER BY tramite_nombre
    ");
    $stmt->execute([':area_id' => $area_id]);
    $tramites = $stmt->fetchAll();
    
    echo json_encode($tramites);
    
} catch (Exception $e) {
    error_log("Error obteniendo tipos de trámite: " . $e->getMessage());
    echo json_encode(['error' => 'Error al cargar los tipos de trámite']);
}
?>
