<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    $db = getDB();

    switch ($action) {
        case 'get_tramites':
            $coordinacion_id = $_GET['coordinacion_id'] ?? '';
            // Estructura según cne_sistema.sql: tramite_id, tramite_nombre, coordinacion_id, tramite_padre_id, tramite_created_at (tramite_duracion_estimada_dias fue eliminado por ALTER)
            $sql = "
                SELECT t.tramite_id, t.tramite_nombre, t.coordinacion_id, t.tramite_padre_id, t.tramite_created_at,
                       c.coordinacion_nombre
                FROM tramite t
                JOIN coordinacion c ON t.coordinacion_id = c.coordinacion_id
            ";
            $params = [];
            if ($coordinacion_id !== '') {
                $sql .= " WHERE t.coordinacion_id = :cid";
                $params[':cid'] = (int)$coordinacion_id;
            }
            $sql .= " ORDER BY c.coordinacion_nombre, t.tramite_nombre";
            $stmt = $params ? $db->prepare($sql) : $db->query($sql);
            if ($params) $stmt->execute($params);
            $tramites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'tramites' => $tramites], JSON_UNESCAPED_UNICODE);
            break;

        case 'get_requisitos':
            $tramite_id = (int)($_GET['tramite_id'] ?? 0);
            if (!$tramite_id) {
                echo json_encode(['success' => false, 'message' => 'tramite_id requerido']);
                exit;
            }
            $stmt = $db->prepare("
                SELECT requisito_id, tramite_id, requisito_nombre, requisito_activo
                FROM requisitos
                WHERE tramite_id = ?
                ORDER BY CASE
                    WHEN TRIM(requisito_nombre) = 'Asesoría'
                      OR LOWER(TRIM(requisito_nombre)) IN ('asesoria', 'asesoría')
                    THEN 0 ELSE 1 END,
                    requisito_nombre
            ");
            $stmt->execute([$tramite_id]);
            $requisitos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'requisitos' => $requisitos], JSON_UNESCAPED_UNICODE);
            break;

        case 'create_tramite':
            $nombre = trim($_POST['tramite_nombre'] ?? '');
            $coordinacion_id = (int)($_POST['coordinacion_id'] ?? 0);
            if (!$nombre || !$coordinacion_id) {
                echo json_encode(['success' => false, 'message' => 'Nombre y coordinación requeridos']);
                exit;
            }
            $chk = $db->prepare("SELECT COUNT(*) AS c FROM tramite WHERE tramite_nombre = ? AND coordinacion_id = ?");
            $chk->execute([$nombre, $coordinacion_id]);
            if ((int)$chk->fetch()['c'] > 0) {
                echo json_encode(['success' => false, 'message' => 'El trámite ya existe en esta coordinación']);
                exit;
            }
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO tramite (tramite_nombre, coordinacion_id) VALUES (?, ?)");
                $stmt->execute([$nombre, $coordinacion_id]);
                $tramite_id = (int)$db->lastInsertId();
                $reqBase = $db->prepare("INSERT INTO requisitos (tramite_id, requisito_nombre, requisito_activo) VALUES (?, 'Asesoría', 1)");
                $reqBase->execute([$tramite_id]);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            echo json_encode(['success' => true, 'message' => 'Trámite creado']);
            break;

        case 'update_tramite':
            $tramite_id = (int)($_POST['tramite_id'] ?? 0);
            $nombre = trim($_POST['tramite_nombre'] ?? '');
            $coordinacion_id = (int)($_POST['coordinacion_id'] ?? 0);
            if (!$tramite_id || !$nombre || !$coordinacion_id) {
                echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                exit;
            }
            $stmt = $db->prepare("UPDATE tramite SET tramite_nombre = ?, coordinacion_id = ? WHERE tramite_id = ?");
            $stmt->execute([$nombre, $coordinacion_id, $tramite_id]);
            echo json_encode(['success' => true, 'message' => 'Trámite actualizado']);
            break;

        case 'delete_tramite':
            $tramite_id = (int)($_POST['tramite_id'] ?? $_GET['tramite_id'] ?? 0);
            if (!$tramite_id) {
                echo json_encode(['success' => false, 'message' => 'tramite_id requerido']);
                exit;
            }
            $chk = $db->prepare("SELECT COUNT(*) AS c FROM solicitudes WHERE tramite_id = ?");
            $chk->execute([$tramite_id]);
            if ((int)$chk->fetch()['c'] > 0) {
                echo json_encode(['success' => false, 'message' => 'No se puede eliminar: hay solicitudes asociadas']);
                exit;
            }
            $db->prepare("DELETE FROM requisitos WHERE tramite_id = ?")->execute([$tramite_id]);
            $stmt = $db->prepare("DELETE FROM tramite WHERE tramite_id = ?");
            $stmt->execute([$tramite_id]);
            echo json_encode(['success' => true, 'message' => 'Trámite eliminado']);
            break;

        case 'create_requisito':
            $tramite_id = (int)($_POST['tramite_id'] ?? 0);
            $nombre = trim($_POST['requisito_nombre'] ?? '');
            $activo = isset($_POST['requisito_activo']) ? (int)$_POST['requisito_activo'] : 1;
            if (!$tramite_id || !$nombre) {
                echo json_encode(['success' => false, 'message' => 'Trámite y nombre requeridos']);
                exit;
            }
            $chk = $db->prepare("SELECT COUNT(*) AS c FROM requisitos WHERE tramite_id = ? AND requisito_nombre = ?");
            $chk->execute([$tramite_id, $nombre]);
            if ((int)$chk->fetch()['c'] > 0) {
                echo json_encode(['success' => false, 'message' => 'El requisito ya existe']);
                exit;
            }
            $stmt = $db->prepare("INSERT INTO requisitos (tramite_id, requisito_nombre, requisito_activo) VALUES (?, ?, ?)");
            $stmt->execute([$tramite_id, $nombre, $activo]);
            echo json_encode(['success' => true, 'message' => 'Requisito creado']);
            break;

        case 'update_requisito':
            $requisito_id = (int)($_POST['requisito_id'] ?? 0);
            $nombre = trim($_POST['requisito_nombre'] ?? '');
            $activo = isset($_POST['requisito_activo']) ? (int)$_POST['requisito_activo'] : 1;
            if (!$requisito_id || !$nombre) {
                echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
                exit;
            }
            $stmt = $db->prepare("UPDATE requisitos SET requisito_nombre = ?, requisito_activo = ? WHERE requisito_id = ?");
            $stmt->execute([$nombre, $activo, $requisito_id]);
            echo json_encode(['success' => true, 'message' => 'Requisito actualizado']);
            break;

        case 'delete_requisito':
            $requisito_id = (int)($_POST['requisito_id'] ?? $_GET['requisito_id'] ?? 0);
            if (!$requisito_id) {
                echo json_encode(['success' => false, 'message' => 'requisito_id requerido']);
                exit;
            }
            $stmt = $db->prepare("DELETE FROM requisitos WHERE requisito_id = ?");
            $stmt->execute([$requisito_id]);
            echo json_encode(['success' => true, 'message' => 'Requisito eliminado']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    error_log("admin_catalogos_controller: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
