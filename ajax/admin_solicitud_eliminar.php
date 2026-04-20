<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$solicitud_id = isset($_POST['solicitud_id']) ? (int) $_POST['solicitud_id'] : 0;
if ($solicitud_id < 1) {
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT solicitud_numero FROM solicitudes WHERE solicitud_id = :id LIMIT 1');
    $stmt->execute([':id' => $solicitud_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $numero = (string) ($row['solicitud_numero'] ?? '');

    $db->beginTransaction();
    // auditoría referencia solicitudes con ON DELETE RESTRICT
    $db->prepare('DELETE FROM auditoria WHERE solicitud_id = :sid')->execute([':sid' => $solicitud_id]);
    $del = $db->prepare('DELETE FROM solicitudes WHERE solicitud_id = :sid');
    $del->execute([':sid' => $solicitud_id]);
    if ($del->rowCount() < 1) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'No se pudo eliminar la solicitud'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Solicitud eliminada',
        'solicitud_id' => $solicitud_id,
        'solicitud_numero' => $numero,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('admin_solicitud_eliminar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
