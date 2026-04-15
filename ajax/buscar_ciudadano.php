<?php
/**
 * Búsqueda simple de ciudadano por cédula.
 * GET: cedula_tipo + cedula_numero, o "cedula" (ej. V-1234567, V 12.345.678).
 * Quita puntos y espacios; consulta preparada por ciudadano_identificacion.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$tipoParam = isset($_GET['cedula_tipo']) && is_string($_GET['cedula_tipo']) ? $_GET['cedula_tipo'] : null;
$numParam = isset($_GET['cedula_numero']) && is_string($_GET['cedula_numero']) ? $_GET['cedula_numero'] : null;
$cedulaUnica = isset($_GET['cedula']) && is_string($_GET['cedula']) ? $_GET['cedula'] : null;

$tipo = '';
$numero = '';

if ($cedulaUnica !== null && trim($cedulaUnica) !== '') {
    $s = trim($cedulaUnica);
    $s = str_replace([' ', '.'], '', $s);
    if (preg_match('/^([VEJG])\s*[\-]?\s*(.+)$/iu', $s, $m)) {
        $tipo = strtoupper($m[1]);
        $numero = $m[2];
    } elseif (preg_match('/^([VEJG])(.+)$/iu', $s, $m)) {
        $tipo = strtoupper($m[1]);
        $numero = $m[2];
    }
} else {
    $tipo = strtoupper(trim((string) ($tipoParam ?? '')));
    $numero = (string) ($numParam ?? '');
}

$numero = preg_replace('/[\s.\-]/', '', $numero);
if ($tipo === 'V' || $tipo === 'E') {
    $numero = preg_replace('/\D/', '', $numero);
} else {
    $numero = preg_replace('/[^0-9A-Za-z]/', '', $numero);
}

if (!preg_match('/^[VEJG]$/', $tipo) || $numero === '') {
    echo json_encode([
        'success' => false,
        'encontrado' => false,
        'data' => null,
        'message' => 'Parámetros de cédula inválidos o incompletos',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cedula_completa = $tipo . '-' . $numero;

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT
            ciudadano_nombres,
            ciudadano_apellidos,
            ciudadano_telefono,
            ciudadano_email,
            ciudadano_genero,
            ciudadano_direccion,
            ciudadano_fecha_nacimiento,
            c.estado_id,
            e.estado_nombre,
            c.municipio_id,
            m.municipio_nombre,
            i.institucion_id,
            i.institucion_nombre
        FROM ciudadanos c
        LEFT JOIN estados e ON c.estado_id = e.estado_id
        LEFT JOIN municipios m ON c.municipio_id = m.municipio_id
        LEFT JOIN institucion i ON c.institucion_id = i.institucion_id
        WHERE ciudadano_identificacion = :cedula
        LIMIT 1'
    );
    $stmt->execute([':cedula' => $cedula_completa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'success' => false,
            'encontrado' => false,
            'data' => null,
            'message' => 'No se encontraron datos para esta cédula',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'ciudadano_identificacion' => $cedula_completa,
        'ciudadano_nombres' => $row['ciudadano_nombres'],
        'ciudadano_apellidos' => $row['ciudadano_apellidos'],
        'ciudadano_telefono' => $row['ciudadano_telefono'],
        'ciudadano_email' => $row['ciudadano_email'] ?? null,
        'ciudadano_genero' => $row['ciudadano_genero'],
        'ciudadano_direccion' => $row['ciudadano_direccion'],
        'ciudadano_fecha_nacimiento' => $row['ciudadano_fecha_nacimiento'],
        'estado_id' => $row['estado_id'],
        'estado_nombre' => $row['estado_nombre'],
        'municipio_id' => $row['municipio_id'],
        'municipio_nombre' => $row['municipio_nombre'],
        'institucion_id' => $row['institucion_id'] ?? null,
        'institucion_nombre' => $row['institucion_nombre'],
    ];

    echo json_encode([
        'success' => true,
        'encontrado' => true,
        'ciudadano_identificacion' => $cedula_completa,
        'data' => $payload,
        'ciudadano_nombres' => $row['ciudadano_nombres'],
        'ciudadano_apellidos' => $row['ciudadano_apellidos'],
        'ciudadano_telefono' => $row['ciudadano_telefono'],
        'ciudadano_email' => $row['ciudadano_email'] ?? null,
        'ciudadano_genero' => $row['ciudadano_genero'],
        'ciudadano_direccion' => $row['ciudadano_direccion'],
        'ciudadano_fecha_nacimiento' => $row['ciudadano_fecha_nacimiento'],
        'estado_id' => $row['estado_id'],
        'estado_nombre' => $row['estado_nombre'],
        'municipio_id' => $row['municipio_id'],
        'municipio_nombre' => $row['municipio_nombre'],
        'institucion_id' => $row['institucion_id'] ?? null,
        'institucion_nombre' => $row['institucion_nombre'],
        'message' => 'Ciudadano encontrado en el sistema',
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('buscar_ciudadano: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'encontrado' => false,
        'data' => null,
        'message' => 'Error en la búsqueda',
    ], JSON_UNESCAPED_UNICODE);
}
