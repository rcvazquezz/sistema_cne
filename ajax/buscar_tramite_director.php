<?php
/**
 * Búsqueda de trámites para el Dashboard Director (rol 4).
 * Une solicitudes con ciudadanos, tramite, coordinación, usuarios y detalles_solicitud (LEFT JOIN).
 */
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 4) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$termino = trim($_GET['q'] ?? $_GET['numero_seguimiento'] ?? '');
if ($termino === '') {
    echo json_encode(['success' => false, 'message' => 'Ingrese un número de seguimiento o una cédula para buscar']);
    exit;
}

function like_escape_dir(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function es_busqueda_probable_cedula_dir(string $q): bool
{
    $t = trim($q);
    if ($t === '') {
        return false;
    }
    if (preg_match('/CNE/i', $t)) {
        return false;
    }
    $norm = preg_replace('/[\s\-.]/', '', $t);
    return (bool) preg_match('/^(?:[VE])?\d{6,12}$/i', $norm);
}

try {
    $db = getDB();
    $patron = '%' . like_escape_dir($termino) . '%';

    /*
     * Sin SELECT DISTINCT + ORDER BY por columnas fuera del SELECT (error en MySQL).
     * LEFT JOIN a detalles_solicitud puede duplicar filas: se agrupa por solicitud.
     * Tabla real del catálogo: tramite (no tramites).
     */
    $sql = "
        SELECT 
            s.solicitud_id,
            s.solicitud_numero,
            s.solicitud_estado,
            DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro,
            TRIM(CONCAT(TRIM(COALESCE(c.ciudadano_nombres, '')), ' ', TRIM(COALESCE(c.ciudadano_apellidos, '')))) AS ciudadano_nombre,
            COALESCE(c.ciudadano_identificacion, s.ciudadano_identificacion) AS ciudadano_identificacion,
            c.ciudadano_genero,
            COALESCE(t.tramite_nombre, '') AS tipo_tramite_nombre,
            COALESCE(co.coordinacion_nombre, '') AS area_nombre,
            TRIM(CONCAT(TRIM(COALESCE(u.user_nombres, '')), ' ', TRIM(COALESCE(u.user_apellidos, '')))) AS creado_por_nombre
        FROM solicitudes s
        LEFT JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        LEFT JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
        LEFT JOIN usuarios u ON s.created_by = u.user_identificacion
        LEFT JOIN detalles_solicitud ds ON ds.solicitud_id = s.solicitud_id
        WHERE s.solicitud_numero LIKE :pat1
           OR COALESCE(c.ciudadano_identificacion, '') LIKE :pat2
           OR s.ciudadano_identificacion LIKE :pat2
        GROUP BY 
            s.solicitud_id,
            s.solicitud_numero,
            s.solicitud_estado,
            s.solicitud_created_at,
            c.ciudadano_nombres,
            c.ciudadano_apellidos,
            c.ciudadano_identificacion,
            c.ciudadano_genero,
            t.tramite_nombre,
            co.coordinacion_nombre,
            u.user_nombres,
            u.user_apellidos
        ORDER BY s.solicitud_created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':pat1' => $patron, ':pat2' => $patron]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $genero_labels = [
        'masculino' => 'Masculino',
        'femenino' => 'Femenino',
        'otro' => 'Otro',
    ];

    if (!$filas) {
        $msgCedula = 'No se encontraron trámites asociados a esta identificación';
        $msgSeg = 'No se encontró ningún trámite con ese número de seguimiento';
        echo json_encode([
            'success' => false,
            'message' => es_busqueda_probable_cedula_dir($termino) ? $msgCedula : $msgSeg,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tramites = [];
    foreach ($filas as $row) {
        $estadoKey = $row['solicitud_estado'];
        $gen = $row['ciudadano_genero'] ?? '';
        $nombre = trim((string) ($row['ciudadano_nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = '—';
        }
        $tramites[] = [
            'solicitud_id' => (int) $row['solicitud_id'],
            'numero_seguimiento' => $row['solicitud_numero'],
            'estado' => $estadoKey,
            'ciudadano_nombre' => $nombre,
            'ciudadano_identificacion' => $row['ciudadano_identificacion'] ?? '',
            'ciudadano_genero' => $genero_labels[$gen] ?? ($gen !== '' ? $gen : 'No especificado'),
            'tipo_tramite_nombre' => $row['tipo_tramite_nombre'] !== '' ? $row['tipo_tramite_nombre'] : '—',
            'area_nombre' => $row['area_nombre'] !== '' ? $row['area_nombre'] : '—',
            'creado_por' => trim((string) ($row['creado_por_nombre'] ?? '')) !== '' ? trim($row['creado_por_nombre']) : '—',
            'fecha_registro' => $row['fecha_registro'],
        ];
    }

    echo json_encode([
        'success' => true,
        'tramites' => $tramites,
        'total' => count($tramites),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('buscar_tramite_director: ' . $e->getMessage());
    $pdoInfo = '';
    if ($e instanceof PDOException) {
        $pdoInfo = $e->errorInfo[2] ?? $e->getMessage();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error en la búsqueda',
        'debug_sql' => $e->getMessage(),
        'debug_pdo' => $pdoInfo !== '' ? $pdoInfo : null,
    ], JSON_UNESCAPED_UNICODE);
}
