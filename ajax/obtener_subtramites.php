<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Permitir rol 1 (Atención al Ciudadano) y rol 2 (Funcionario)
if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['rol_id'] ?? 0), [1, 2])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!isset($_GET['padre_id']) || !is_numeric($_GET['padre_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de trámite padre inválido']);
    exit;
}

$padre_id = intval($_GET['padre_id']);

try {
    $subtramites = obtenerSubtramites($padre_id);
    echo json_encode(['success' => true, 'subtramites' => $subtramites]);
} catch (Exception $e) {
    error_log("Error obteniendo subtrámites: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar subtrámites']);
}
