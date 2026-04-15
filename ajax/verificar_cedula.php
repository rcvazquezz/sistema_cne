<?php
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Normaliza a Y-m-d o cadena vacía si no es reconocible.
 */
function cneVerificarCedulaNormalizarFecha(?string $raw): string
{
    $s = trim((string) $raw);
    if ($s === '' || strcasecmp($s, 'N/A') === 0) {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($y >= 1900 && $y <= 2100 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    return '';
}

/** Texto comparable: trim, colapsar espacios, mayúsculas UTF-8. */
function cneVerificarCedulaNormTexto(?string $s): string
{
    $t = trim((string) ($s ?? ''));
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strtoupper')) {
        $t = mb_strtoupper($t, 'UTF-8');
    } else {
        $t = strtoupper($t);
    }
    $t = preg_replace('/\s+/u', ' ', $t);

    return $t;
}

function cneVerificarCedulaSoloDigitos(?string $s): string
{
    return preg_replace('/\D/', '', (string) ($s ?? ''));
}

/** Sin número completo (NULL, vacío o solo prefijo tipo 0412). */
function cneVerificarCedulaTelefonoIndeterminado(?string $tel): bool
{
    $d = cneVerificarCedulaSoloDigitos($tel);
    if ($d === '') {
        return true;
    }
    // Menos de 7 dígitos: típicamente solo código de área / formulario incompleto
    return strlen($d) < 7;
}

function cneVerificarCedulaTelefonosCoinciden(string $telInput, ?string $telDb): bool
{
    $dbStr = $telDb !== null ? (string) $telDb : '';
    if (cneVerificarCedulaTelefonoIndeterminado($telInput) && cneVerificarCedulaTelefonoIndeterminado($dbStr)) {
        return true;
    }

    return cneVerificarCedulaSoloDigitos($telInput) === cneVerificarCedulaSoloDigitos($dbStr);
}

if (!isset($_GET['cedula']) || !isset($_GET['nombre']) || !isset($_GET['telefono'])) {
    echo json_encode(['valido' => true]); // Si faltan datos, permitir
    exit;
}

$cedula = trim((string) ($_GET['cedula'] ?? ''));
$nombre = trim((string) ($_GET['nombre'] ?? ''));
$telefono = trim((string) ($_GET['telefono'] ?? ''));
$fecha_nacimiento = isset($_GET['fecha_nacimiento']) ? trim((string) $_GET['fecha_nacimiento']) : '';
$fecha_input_norm = cneVerificarCedulaNormalizarFecha($fecha_nacimiento);
$genero_input = isset($_GET['genero']) ? trim((string) $_GET['genero']) : '';
$nIn = trim((string) ($_GET['nombres'] ?? ''));
$apIn = trim((string) ($_GET['apellidos'] ?? ''));
if ($nIn === '' && $apIn === '' && $nombre !== '') {
    if (preg_match('/^(.+?)\s+(\S.*)$/', $nombre, $mm)) {
        $nIn = trim($mm[1]);
        $apIn = trim($mm[2]);
    } else {
        $nIn = $nombre;
    }
}

try {
    $db = getDB();
    
    // Buscar si ya existe la cédula
    $stmt = $db->prepare("
        SELECT 
            ciudadano_nombres,
            ciudadano_apellidos,
            ciudadano_telefono,
            ciudadano_fecha_nacimiento,
            ciudadano_genero,
            ciudadano_direccion,
            estado_id,
            municipio_id
        FROM ciudadanos 
        WHERE ciudadano_identificacion = :cedula
    ");
    
    $stmt->execute([':cedula' => $cedula]);
    $existente = $stmt->fetch();
    
    if ($existente) {
        // Verificar si los datos coinciden
        $nomDb = trim((string) ($existente['ciudadano_nombres'] ?? ''));
        $apDb = trim((string) ($existente['ciudadano_apellidos'] ?? ''));
        $nombre_existente = trim($nomDb . ' ' . $apDb);
        $telefono_existente = $existente['ciudadano_telefono'];
        $db_fecha_raw = $existente['ciudadano_fecha_nacimiento'] ?? null;
        $db_fecha_norm = cneVerificarCedulaNormalizarFecha($db_fecha_raw !== null ? (string) $db_fecha_raw : '');
        $db_genero = trim((string) ($existente['ciudadano_genero'] ?? ''));

        $nombres_coinciden = (cneCiudadanoCampoEsNA($nomDb) || cneCiudadanoNormalizarTextoCmp($nIn) === cneCiudadanoNormalizarTextoCmp($nomDb))
            && (cneCiudadanoCampoEsNA($apDb) || cneCiudadanoNormalizarTextoCmp($apIn) === cneCiudadanoNormalizarTextoCmp($apDb));

        $dbTelStr = $telefono_existente !== null ? (string) $telefono_existente : '';
        $telefonos_coinciden = cneCiudadanoCampoEsNA($dbTelStr)
            || cneVerificarCedulaTelefonosCoinciden($telefono, $dbTelStr);

        // Si en BD no hay fecha, no exigir coincidencia; si ambos sin fecha, coinciden
        $fechas_coinciden = ($db_fecha_norm === '' && $fecha_input_norm === '')
            || ($db_fecha_norm === '')
            || ($fecha_input_norm === $db_fecha_norm);

        $generos_coinciden = cneCiudadanoCampoEsNA($db_genero)
            || (cneCiudadanoNormalizarTextoCmp($genero_input) === cneCiudadanoNormalizarTextoCmp($db_genero));

        $estados_coinciden = true;
        if (isset($_GET['estado_id'])) {
            $inEst = cneCiudadanoEstadoMunicipioIdParaGuardar($_GET['estado_id']);
            $dbEst = $existente['estado_id'] ?? null;
            $estados_coinciden = cneCiudadanoFkUbicacionEsNA($dbEst)
                || ($inEst !== null && (int) $dbEst === (int) $inEst);
        }
        $municipios_coinciden = true;
        if (isset($_GET['municipio_id'])) {
            $inMun = cneCiudadanoEstadoMunicipioIdParaGuardar($_GET['municipio_id']);
            $dbMun = $existente['municipio_id'] ?? null;
            $municipios_coinciden = cneCiudadanoFkUbicacionEsNA($dbMun)
                || ($inMun !== null && (int) $dbMun === (int) $inMun);
        }
        $direcciones_coinciden = true;
        if (isset($_GET['direccion'])) {
            $inDir = cneCiudadanoDireccionParaGuardar((string) $_GET['direccion']);
            $dbDir = trim((string) ($existente['ciudadano_direccion'] ?? ''));
            $direcciones_coinciden = cneCiudadanoCampoEsNA($dbDir)
                || cneCiudadanoNormalizarTextoCmp($dbDir) === cneCiudadanoNormalizarTextoCmp($inDir);
        }

        if ($nombres_coinciden && $telefonos_coinciden && $fechas_coinciden && $generos_coinciden
            && $estados_coinciden && $municipios_coinciden && $direcciones_coinciden) {
            echo json_encode(['valido' => true, 'message' => 'Datos coinciden con registro existente']);
        } else {
            echo json_encode([
                'valido' => false,
                'message' => 'Esta cédula ya está registrada con datos diferentes',
                'datos_existentes' => [
                    'nombre' => $nombre_existente,
                    'nombres' => $nomDb,
                    'apellidos' => $apDb,
                    'telefono' => $telefono_existente,
                    'fecha_nacimiento' => $db_fecha_norm,
                    'genero' => $db_genero,
                    'estado_id' => $existente['estado_id'] ?? null,
                    'municipio_id' => $existente['municipio_id'] ?? null,
                    'direccion' => $existente['ciudadano_direccion'] ?? '',
                ],
            ]);
        }
    } else {
        echo json_encode(['valido' => true]);
    }
    
} catch (Exception $e) {
    error_log("Error verificando cédula: " . $e->getMessage());
    echo json_encode(['valido' => true]); // En caso de error, permitir
}
?>