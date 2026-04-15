<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$termino = trim($_GET['numero_seguimiento'] ?? $_GET['q'] ?? '');
if ($termino === '') {
    echo json_encode(['success' => false, 'message' => 'Ingrese un número de seguimiento o una cédula para buscar']);
    exit;
}

/**
 * Escapa % y _ para uso seguro en LIKE.
 */
function like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

/**
 * Heurística para mensaje vacío: búsqueda orientada a cédula vs. seguimiento.
 */
function es_busqueda_probable_cedula(string $q): bool
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

    $patron = '%' . like_escape($termino) . '%';

    $stmt = $db->prepare("
        SELECT 
            s.solicitud_id,
            s.solicitud_numero,
            s.solicitud_estado,
            DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro,
            CONCAT(TRIM(c.ciudadano_nombres), ' ', TRIM(c.ciudadano_apellidos)) AS ciudadano_nombre,
            c.ciudadano_identificacion,
            c.ciudadano_genero,
            t.tramite_nombre AS tipo_tramite_nombre,
            co.coordinacion_nombre AS area_nombre,
            TRIM(CONCAT(TRIM(u.user_nombres), ' ', TRIM(u.user_apellidos))) AS creado_por_nombre
        FROM solicitudes s
        INNER JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        INNER JOIN tramite t ON s.tramite_id = t.tramite_id
        INNER JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
        INNER JOIN usuarios u ON s.created_by = u.user_identificacion
        WHERE s.solicitud_numero LIKE :pat1
           OR c.ciudadano_identificacion LIKE :pat2
        ORDER BY s.solicitud_created_at DESC
    ");

    $stmt->execute([
        ':pat1' => $patron,
        ':pat2' => $patron,
    ]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $estados = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Proceso',
        'aprobada' => 'En Proceso',
        'rechazada' => 'VENCIDO',
        'vencida' => 'VENCIDO',
        'completada' => 'Completada',
        'redirigida' => 'Redirigida',
    ];

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
            'message' => es_busqueda_probable_cedula($termino) ? $msgCedula : $msgSeg,
        ]);
        exit;
    }

    $tramites = [];
    foreach ($filas as $row) {
        $estadoKey = $row['solicitud_estado'];
        $gen = $row['ciudadano_genero'] ?? '';
        $tramites[] = [
            'solicitud_id' => (int) $row['solicitud_id'],
            'numero_seguimiento' => $row['solicitud_numero'],
            'estado' => $estadoKey,
            'estado_display' => $estados[$estadoKey] ?? $estadoKey,
            'ciudadano_nombre' => $row['ciudadano_nombre'],
            'ciudadano_identificacion' => $row['ciudadano_identificacion'],
            'ciudadano_genero' => $genero_labels[$gen] ?? ($gen !== '' ? $gen : 'No especificado'),
            'tipo_tramite_nombre' => $row['tipo_tramite_nombre'],
            'area_nombre' => $row['area_nombre'],
            'creado_por' => $row['creado_por_nombre'] ?? '',
            'fecha_registro' => $row['fecha_registro'],
        ];
    }

    echo json_encode([
        'success' => true,
        'tramites' => $tramites,
        'total' => count($tramites),
    ]);
} catch (Exception $e) {
    error_log('Error buscando trámite: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error en la búsqueda']);
}
