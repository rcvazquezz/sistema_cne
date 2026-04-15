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
        case 'get_all':
        case 'get_ciudadano':
            $nombre = trim($_GET['nombre'] ?? '');
            $id = trim($_GET['ciudadano_identificacion'] ?? $_GET['id'] ?? '');

            $sql = "
                SELECT c.ciudadano_identificacion, c.ciudadano_nombres, c.ciudadano_apellidos,
                       c.ciudadano_tipo_identificacion, c.ciudadano_nacionalidad,
                       c.ciudadano_fecha_nacimiento, c.ciudadano_genero,
                       c.ciudadano_telefono, c.ciudadano_email, c.ciudadano_direccion,
                       c.estado_id, c.municipio_id, c.institucion_id,
                       e.estado_nombre, m.municipio_nombre, i.institucion_nombre
                FROM ciudadanos c
                LEFT JOIN estados e ON c.estado_id = e.estado_id
                LEFT JOIN municipios m ON c.municipio_id = m.municipio_id
                LEFT JOIN institucion i ON c.institucion_id = i.institucion_id
                WHERE 1=1
            ";
            $params = [];

            if ($id !== '') {
                $sql .= " AND c.ciudadano_identificacion = :id";
                $params[':id'] = $id;
            }
            if ($nombre !== '') {
                $sql .= " AND (c.ciudadano_identificacion LIKE :n1 OR c.ciudadano_nombres LIKE :n2 OR c.ciudadano_apellidos LIKE :n3 OR CONCAT(c.ciudadano_nombres, ' ', c.ciudadano_apellidos) LIKE :n4)";
                $p = '%' . $nombre . '%';
                $params[':n1'] = $p;
                $params[':n2'] = $p;
                $params[':n3'] = $p;
                $params[':n4'] = $p;
            }

            $sql .= " ORDER BY c.ciudadano_apellidos ASC, c.ciudadano_nombres ASC";

            if ($params) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $db->query($sql);
            }

            if ($action === 'get_ciudadano' && $id !== '') {
                $ciudadano = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'ciudadano' => $ciudadano ?: null], JSON_UNESCAPED_UNICODE);
            } else {
                $ciudadanos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'ciudadanos' => $ciudadanos], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'get_metadata':
            $estados = $db->query("SELECT estado_id, estado_nombre FROM estados ORDER BY estado_nombre")->fetchAll(PDO::FETCH_ASSOC);
            $municipios = $db->query("SELECT municipio_id, municipio_nombre, estado_id FROM municipios ORDER BY municipio_nombre")->fetchAll(PDO::FETCH_ASSOC);
            $instituciones = $db->query("SELECT institucion_id, institucion_nombre FROM institucion ORDER BY institucion_nombre")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'estados' => $estados, 'municipios' => $municipios, 'instituciones' => $instituciones], JSON_UNESCAPED_UNICODE);
            break;

        case 'update':
            $id_original = trim($_POST['ciudadano_identificacion_original'] ?? $_POST['ciudadano_identificacion'] ?? '');
            $id_nuevo_raw = trim($_POST['ciudadano_identificacion'] ?? '');
            $nombres = trim($_POST['ciudadano_nombres'] ?? '');
            $apellidos = trim($_POST['ciudadano_apellidos'] ?? '');
            $tipo_identificacion = in_array(trim($_POST['ciudadano_tipo_identificacion'] ?? ''), ['cedula', 'pasaporte']) ? trim($_POST['ciudadano_tipo_identificacion']) : 'cedula';
            $fecha_nacimiento = trim($_POST['ciudadano_fecha_nacimiento'] ?? '') ?: null;
            $genero = in_array(trim($_POST['ciudadano_genero'] ?? ''), ['masculino', 'femenino', 'otro']) ? trim($_POST['ciudadano_genero']) : null;
            $telefono = trim($_POST['ciudadano_telefono'] ?? '') ?: null;
            $email = trim($_POST['ciudadano_email'] ?? '') ?: null;
            $direccion = trim($_POST['ciudadano_direccion'] ?? '') ?: null;
            $estado_id = trim($_POST['estado_id'] ?? '') !== '' ? (int)$_POST['estado_id'] : null;
            $municipio_id = trim($_POST['municipio_id'] ?? '') !== '' ? (int)$_POST['municipio_id'] : null;
            $institucion_id = trim($_POST['institucion_id'] ?? '') !== '' && trim($_POST['institucion_id']) !== '_none_' ? (int)$_POST['institucion_id'] : null;

            if (!$id_original || !$nombres || !$apellidos) {
                echo json_encode(['success' => false, 'message' => 'Identificación original, nombres y apellidos son requeridos']);
                exit;
            }

            // Normalizar cédula: V12345678 o V-12345678 -> V-12345678 (formato unificado del sistema)
            $id_nuevo = trim($id_nuevo_raw);
            if (preg_match('/^([VE])\s*\-?\s*(\d{6,10})$/i', $id_nuevo, $m)) {
                $id_nuevo = strtoupper($m[1]) . '-' . $m[2];
            } elseif (preg_match('/^([VE])(\d{6,10})$/i', $id_nuevo, $m)) {
                $id_nuevo = strtoupper($m[1]) . '-' . $m[2];
            }

            if (strlen($id_nuevo) < 2) {
                echo json_encode(['success' => false, 'message' => 'Formato de cédula inválido. Use V/E seguido de números (ej. V12345678)']);
                exit;
            }

            $nacionalidad = (preg_match('/^E/i', $id_nuevo)) ? 'E' : 'V';

            $cambia_pk = (strcasecmp($id_original, $id_nuevo) !== 0);

            if ($cambia_pk) {
                // Validar que la nueva cédula no exista en otro registro
                $stmt = $db->prepare("SELECT ciudadano_identificacion FROM ciudadanos WHERE ciudadano_identificacion = ?");
                $stmt->execute([$id_nuevo]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'La cédula ' . htmlspecialchars($id_nuevo) . ' ya está registrada en otro ciudadano.']);
                    exit;
                }

                $db->beginTransaction();
                try {
                    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $stmt = $db->prepare("UPDATE solicitudes SET ciudadano_identificacion = ? WHERE ciudadano_identificacion = ?");
                    $stmt->execute([$id_nuevo, $id_original]);
                    $stmt = $db->prepare("
                        UPDATE ciudadanos SET
                            ciudadano_identificacion = ?,
                            ciudadano_nombres = ?,
                            ciudadano_apellidos = ?,
                            ciudadano_tipo_identificacion = ?,
                            ciudadano_nacionalidad = ?,
                            ciudadano_fecha_nacimiento = ?,
                            ciudadano_genero = ?,
                            ciudadano_telefono = ?,
                            ciudadano_email = ?,
                            ciudadano_direccion = ?,
                            estado_id = ?,
                            municipio_id = ?,
                            institucion_id = ?
                        WHERE ciudadano_identificacion = ?
                    ");
                    $stmt->execute([
                        $id_nuevo, $nombres, $apellidos, $tipo_identificacion, $nacionalidad,
                        $fecha_nacimiento, $genero, $telefono, $email, $direccion,
                        $estado_id, $municipio_id, $institucion_id, $id_original
                    ]);
                    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $db->commit();
                } catch (Exception $ex) {
                    $db->rollBack();
                    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
                    throw $ex;
                }
            } else {
                $stmt = $db->prepare("
                    UPDATE ciudadanos SET
                        ciudadano_nombres = ?,
                        ciudadano_apellidos = ?,
                        ciudadano_tipo_identificacion = ?,
                        ciudadano_nacionalidad = ?,
                        ciudadano_fecha_nacimiento = ?,
                        ciudadano_genero = ?,
                        ciudadano_telefono = ?,
                        ciudadano_email = ?,
                        ciudadano_direccion = ?,
                        estado_id = ?,
                        municipio_id = ?,
                        institucion_id = ?
                    WHERE ciudadano_identificacion = ?
                ");
                $stmt->execute([
                    $nombres, $apellidos, $tipo_identificacion, $nacionalidad,
                    $fecha_nacimiento, $genero, $telefono, $email, $direccion,
                    $estado_id, $municipio_id, $institucion_id, $id_original
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Ciudadano actualizado correctamente']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    $errMsg = $e->getMessage();
    error_log("admin_ciudadanos_controller: " . $errMsg);
    echo json_encode(['success' => false, 'message' => $errMsg, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
}
