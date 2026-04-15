<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$cid = isset($_GET['coordinacion_id']) ? (int) $_GET['coordinacion_id'] : 0;
if ($cid < 1) {
    echo json_encode(['success' => false, 'message' => 'Coordinación no válida']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT tramite_id, tramite_nombre, tramite_padre_id
        FROM tramite
        WHERE coordinacion_id = :cid
        ORDER BY (tramite_padre_id IS NULL) DESC, tramite_nombre ASC
    ");
    $stmt->execute([':cid' => $cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'tramites' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('admin_tramites_por_coordinacion: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar trámites']);
}
