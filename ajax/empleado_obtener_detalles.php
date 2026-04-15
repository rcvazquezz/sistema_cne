<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
$solicitud_id = isset($_POST['solicitud_id']) ? (int)$_POST['solicitud_id'] : 0;
if ($solicitud_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Solicitud inválida']);
    exit;
}
try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT solicitud_id, tramite_id, codigo_interno, solicitud_estado
        FROM solicitudes
        WHERE solicitud_id = :sid
    ");
    $stmt->execute([':sid' => $solicitud_id]);
    $sol = $stmt->fetch();
    if (!$sol) {
        echo json_encode(['success' => false, 'message' => 'Trámite no encontrado']);
        exit;
    }
    $stmt = $db->prepare("
        SELECT requisito_id
        FROM requisitos_solicitud
        WHERE solicitud_id = :sid AND requisitos_solicitud_status = 'aprobado'
    ");
    $stmt->execute([':sid' => $solicitud_id]);
    $aprobados = array_map(function($r){ return (int)$r['requisito_id']; }, $stmt->fetchAll());
    echo json_encode([
        'success' => true,
        'codigo_interno' => $sol['codigo_interno'] ?? null,
        'solicitud_estado' => $sol['solicitud_estado'] ?? null,
        'requisitos_aprobados' => $aprobados
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener detalles: ' . $e->getMessage()]);
}
?> 
