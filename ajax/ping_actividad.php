<?php
/**
 * Actualiza sesion_ultima_actividad para el usuario autenticado (AJAX periódico).
 */
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    actualizarSesionUltimaActividad($_SESSION['user_id']);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('ping_actividad: ' . $e->getMessage());
    echo json_encode(['success' => false]);
}
