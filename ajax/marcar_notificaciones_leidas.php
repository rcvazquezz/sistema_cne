<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $db = getDB();
    $usuario_id = $_SESSION['user_id'];
    $usuario = obtenerUsuario($usuario_id);
    $coordinacion_id = $usuario['coordinacion_id'] ?? null;
    if (!$coordinacion_id) {
        echo json_encode(['success' => false, 'message' => 'Coordinación no definida']);
        exit;
    }

    // Determinar si el usuario_id es numérico (según esquema actual, usuario_id en notificaciones es INT NULL)
    $uidParam = is_numeric($usuario_id) ? (int)$usuario_id : null;

    // Construir WHERE dinámico para evitar errores de tipo si usuario_id no aplica
    $where = "(usuario_id IS NULL AND coordinacion_id = :cid)";
    $params = [':cid' => $coordinacion_id];
    if ($uidParam !== null) {
        $where = "(usuario_id = :uid OR (usuario_id IS NULL AND coordinacion_id = :cid))";
        $params[':uid'] = $uidParam;
    }

    // Usar la columna correcta según el esquema: notificacion_estado
    $sql = "UPDATE notificaciones
            SET notificacion_estado = 'leido'
            WHERE notificacion_estado = 'no_leido'
              AND $where";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();

    echo json_encode(['success' => true, 'actualizadas' => $count]);
} catch (PDOException $e) {
    error_log('SQL error marcando notificaciones leídas: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('Error marcando notificaciones leídas: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 
