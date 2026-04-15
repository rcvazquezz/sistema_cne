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
            $nombre = trim($_GET['nombre'] ?? '');
            $rol_id = trim($_GET['rol_id'] ?? '');
            $coordinacion_id = trim($_GET['coordinacion_id'] ?? '');

            // Usar LEFT JOIN coordinacion para incluir usuarios sin coordinación (ej: Admin en Sede Central)
            $sql = "
                SELECT u.user_identificacion, u.user_username, u.user_nombres, u.user_apellidos,
                       u.rol_id, u.coordinacion_id, u.user_estado, u.user_created_at,
                       r.rol_nombre,
                       c.coordinacion_nombre
                FROM usuarios u
                INNER JOIN roles r ON u.rol_id = r.rol_id
                LEFT JOIN coordinacion c ON u.coordinacion_id = c.coordinacion_id
                WHERE 1=1
            ";
            $params = [];

            if ($nombre !== '') {
                $sql .= " AND (u.user_identificacion LIKE :n1 OR u.user_nombres LIKE :n2 OR u.user_apellidos LIKE :n3 OR u.user_username LIKE :n4)";
                $p = '%' . $nombre . '%';
                $params[':n1'] = $p;
                $params[':n2'] = $p;
                $params[':n3'] = $p;
                $params[':n4'] = $p;
            }
            if ($rol_id !== '') {
                $sql .= " AND u.rol_id = :rid";
                $params[':rid'] = (int)$rol_id;
            }
            if ($coordinacion_id !== '') {
                if ($coordinacion_id === '_null_') {
                    $sql .= " AND u.coordinacion_id IS NULL";
                } else {
                    $sql .= " AND u.coordinacion_id = :cid";
                    $params[':cid'] = (int)$coordinacion_id;
                }
            }

            $sql .= " ORDER BY u.user_nombres ASC, u.user_apellidos ASC";

            if ($params) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $db->query($sql);
            }
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'usuarios' => $usuarios], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            // user_identificacion es la PK (no autoincremental) - debe venir del formulario
            $id = trim($_POST['user_identificacion'] ?? '');
            $username = trim($_POST['user_username'] ?? '');
            $nombres = trim($_POST['user_nombres'] ?? '');
            $apellidos = trim($_POST['user_apellidos'] ?? '');
            $password = $_POST['user_password'] ?? '';
            $rol_id = (int)($_POST['rol_id'] ?? 0);
            $coordinacion_id = $_POST['coordinacion_id'] ?? '';
            $estado = trim($_POST['user_estado'] ?? 'activo') ?: 'activo';

            if (!$id || !$username || !$nombres || !$apellidos || !$password || !$rol_id) {
                echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos (Cédula, Usuario, Nombres, Apellidos, Contraseña, Rol)']);
                exit;
            }

            $coor = ($coordinacion_id === '' || $coordinacion_id === 'null' || $coordinacion_id === '_none_') ? null : (int)$coordinacion_id;

            $chk = $db->prepare("SELECT COUNT(*) AS c FROM usuarios WHERE user_identificacion = ? OR user_username = ?");
            $chk->execute([$id, $username]);
            if ((int)$chk->fetch()['c'] > 0) {
                echo json_encode(['success' => false, 'message' => 'La cédula o usuario ya existe']);
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO usuarios (user_identificacion, user_username, user_password_hash, user_nombres, user_apellidos, rol_id, coordinacion_id, user_estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $username, $hash, $nombres, $apellidos, $rol_id, $coor, $estado]);
            echo json_encode(['success' => true, 'message' => 'Usuario creado correctamente']);
            break;

        case 'update':
            $id = trim($_POST['user_identificacion'] ?? '');
            $username = trim($_POST['user_username'] ?? '');
            $nombres = trim($_POST['user_nombres'] ?? '');
            $apellidos = trim($_POST['user_apellidos'] ?? '');
            $rol_id = (int)($_POST['rol_id'] ?? 0);
            $coordinacion_id = $_POST['coordinacion_id'] ?? '';
            $estado = $_POST['user_estado'] ?? 'activo';
            $nueva_password = trim($_POST['nueva_password'] ?? '');

            if (!$id || !$username || !$nombres || !$apellidos || !$rol_id) {
                echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos']);
                exit;
            }

            if ($nueva_password !== '' && strlen($nueva_password) < 6) {
                echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 6 caracteres']);
                exit;
            }

            $coor = ($coordinacion_id === '' || $coordinacion_id === 'null' || $coordinacion_id === '_none_') ? null : (int)$coordinacion_id;

            $chk = $db->prepare("SELECT COUNT(*) AS c FROM usuarios WHERE user_username = ? AND user_identificacion != ?");
            $chk->execute([$username, $id]);
            if ((int)$chk->fetch()['c'] > 0) {
                echo json_encode(['success' => false, 'message' => 'El usuario ya está en uso']);
                exit;
            }

            if ($nueva_password !== '') {
                $hash = password_hash($nueva_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE usuarios SET user_username=?, user_nombres=?, user_apellidos=?, rol_id=?, coordinacion_id=?, user_estado=?, user_password_hash=? WHERE user_identificacion=?");
                $stmt->execute([$username, $nombres, $apellidos, $rol_id, $coor, $estado, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE usuarios SET user_username=?, user_nombres=?, user_apellidos=?, rol_id=?, coordinacion_id=?, user_estado=? WHERE user_identificacion=?");
                $stmt->execute([$username, $nombres, $apellidos, $rol_id, $coor, $estado, $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Usuario actualizado' . ($nueva_password !== '' ? ' (contraseña cambiada)' : '')]);
            break;

        case 'delete':
            $id = trim($_POST['user_identificacion'] ?? $_GET['user_identificacion'] ?? '');
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'ID requerido']);
                exit;
            }
            if ($id === $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'No puede eliminarse a sí mismo']);
                exit;
            }
            $stmt = $db->prepare("DELETE FROM usuarios WHERE user_identificacion = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Usuario eliminado']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    $errMsg = $e->getMessage();
    error_log("admin_usuarios_controller: " . $errMsg);
    // Devolver error específico de PDO para depuración en consola
    echo json_encode([
        'success' => false,
        'message' => $errMsg,
        'error' => $errMsg
    ], JSON_UNESCAPED_UNICODE);
}
