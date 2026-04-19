<?php
/**
 * Lista de requisitos activos del trámite + estado de cumplimiento en la solicitud (para el modal admin).
 */
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sid = isset($_GET['solicitud_id']) ? (int) $_GET['solicitud_id'] : 0;
$tid = isset($_GET['tramite_id']) ? (int) $_GET['tramite_id'] : 0;
if ($sid < 1 || $tid < 1) {
    echo json_encode(['success' => false, 'message' => 'Parámetros inválidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT solicitud_id FROM solicitudes WHERE solicitud_id = :sid LIMIT 1');
    $stmt->execute([':sid' => $sid]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare("
        SELECT r.requisito_id, r.requisito_nombre,
               IFNULL(rs.requisitos_solicitud_status, 'pendiente') AS status_sol
        FROM requisitos r
        LEFT JOIN requisitos_solicitud rs
          ON rs.requisito_id = r.requisito_id AND rs.solicitud_id = :sid
        WHERE r.tramite_id = :tid AND r.requisito_activo = 1
        ORDER BY r.requisito_nombre
    ");
    $stmt->execute([':sid' => $sid, ':tid' => $tid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $requisitos = [];
    foreach ($rows as $r) {
        $requisitos[] = [
            'requisito_id' => (int) $r['requisito_id'],
            'requisito_nombre' => $r['requisito_nombre'],
            'marcado' => (($r['status_sol'] ?? '') === 'aprobado'),
        ];
    }

    $esAsesoria = static function (string $nombre): bool {
        return (bool) preg_match('/^asesor[ií]a$/iu', trim($nombre));
    };
    $primero = [];
    $resto = [];
    foreach ($requisitos as $item) {
        if ($esAsesoria((string) ($item['requisito_nombre'] ?? ''))) {
            $primero[] = $item;
        } else {
            $resto[] = $item;
        }
    }
    $requisitos = array_merge($primero, $resto);

    echo json_encode(['success' => true, 'requisitos' => $requisitos], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('admin_solicitud_requisitos_ui: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al cargar requisitos'], JSON_UNESCAPED_UNICODE);
}
