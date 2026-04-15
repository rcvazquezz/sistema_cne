<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 4) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$coordinacion_id = trim($_GET['coordinacion_id'] ?? '');

try {
    $db = getDB();
    if ($coordinacion_id === '') {
        $stmt = $db->query("SELECT tramite_id, tramite_nombre FROM tramite ORDER BY tramite_nombre");
    } else {
        $stmt = $db->prepare("SELECT tramite_id, tramite_nombre FROM tramite WHERE coordinacion_id = :cid ORDER BY tramite_nombre");
        $stmt->execute([':cid' => (int)$coordinacion_id]);
    }
    $tramites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'tramites' => $tramites], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("director_tramites_by_coordinacion: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'tramites' => []]);
}
