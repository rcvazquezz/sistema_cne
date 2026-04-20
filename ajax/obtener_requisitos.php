<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['rol_id'] ?? 0), [1, 2])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!isset($_GET['tramite_id']) || !is_numeric($_GET['tramite_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de trámite inválido']);
    exit;
}

$tramite_id = intval($_GET['tramite_id']);

try {
    $db = getDB();
    // Solo catálogo por tramite_id; sin filtrar por coordinación del usuario ni auditoría.
    $stmt = $db->prepare("
        SELECT requisito_id as id, requisito_nombre as nombre
        FROM requisitos
        WHERE tramite_id = :tramite_id AND requisito_activo = 1
        ORDER BY CASE
            WHEN TRIM(requisito_nombre) = 'Asesoría'
              OR LOWER(TRIM(requisito_nombre)) IN ('asesoria', 'asesoría')
            THEN 0 ELSE 1 END,
            requisito_nombre
    ");
    $stmt->execute([':tramite_id' => $tramite_id]);
    $requisitos = $stmt->fetchAll();
    echo json_encode(['success' => true, 'requisitos' => $requisitos]);
} catch (Exception $e) {
    error_log("Error obteniendo requisitos: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar requisitos']);
}
