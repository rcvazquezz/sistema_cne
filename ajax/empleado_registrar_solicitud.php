<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$required_fields = ['institucion', 'tipo_tramite_id'];

foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => "Falta el campo: $field"]);
        exit;
    }
}

$tipo_solicitud = $_POST['tipo_solicitud'] ?? 'normal';
if ($tipo_solicitud !== 'inmediato' && $tipo_solicitud !== 'normal') {
    $tipo_solicitud = 'normal';
}

$mi_coord_tramite_inmediato = null;
if ($tipo_solicitud === 'inmediato') {
    $usuarioRowInm = obtenerUsuario($_SESSION['user_id']);
    $mi_coord_tramite_inmediato = (int) ($usuarioRowInm['coordinacion_id'] ?? $_SESSION['coordinacion_id'] ?? 0);
    if ($mi_coord_tramite_inmediato < 1) {
        echo json_encode(['success' => false, 'message' => 'Su usuario no tiene coordinación asignada; no puede registrar trámites inmediatos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$coord_destino = (int)($_POST['id_coordinacion_destino'] ?? $_POST['area_id'] ?? 0);
if ($coord_destino <= 0) {
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar la coordinación de destino']);
    exit;
}

try {
    $db = getDB();

    $stmtPerm = $db->prepare(
        "SELECT coordinacion_id, coordinacion_nombre FROM coordinacion WHERE coordinacion_id = ? AND coordinacion_estado = 'activo' AND coordinacion_nombre NOT LIKE 'Oficina de Atención al Ciudadano' LIMIT 1"
    );
    $stmtPerm->execute([$coord_destino]);
    $rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC);
    if (!$rowPerm) {
        echo json_encode(['success' => false, 'message' => 'La coordinación de destino no es válida']);
        exit;
    }

    if ($tipo_solicitud === 'inmediato' && $coord_destino !== $mi_coord_tramite_inmediato) {
        echo json_encode(['success' => false, 'message' => 'Los trámites inmediatos solo pueden realizarse en su propia coordinación.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $coordinacion_destino_raw = trim((string)($rowPerm['coordinacion_nombre'] ?? ''));
    $nombre_coordinacion_destino = presentarNombreCoordinacionUi($coordinacion_destino_raw !== '' ? $coordinacion_destino_raw : null);
    if ($nombre_coordinacion_destino === '') {
        $nombre_coordinacion_destino = coordinacionNombreParaAuditoria($db, $coord_destino);
    }

    $tramite_id = (int)($_POST['tipo_tramite_id'] ?? 0);
    if ($tramite_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Tipo de trámite inválido']);
        exit;
    }

    $stmt = $db->prepare('SELECT tramite_id, tramite_nombre, coordinacion_id FROM tramite WHERE tramite_id = :tid');
    $stmt->execute([':tid' => $tramite_id]);
    $tramiteRow = $stmt->fetch();
    if (!$tramiteRow || (int)($tramiteRow['coordinacion_id'] ?? 0) !== $coord_destino) {
        echo json_encode(['success' => false, 'message' => 'El trámite seleccionado no corresponde a la coordinación de destino elegida']);
        exit;
    }

    $db->beginTransaction();

    $cedula_numero = trim((string) ($_POST['cedula_numero'] ?? ''));
    $cedula_tipo = trim((string) ($_POST['cedula_tipo'] ?? 'V'));
    $nombres = trim((string) ($_POST['nombres'] ?? ''));
    $apellidos = trim((string) ($_POST['apellidos'] ?? ''));
    $genero = trim((string) ($_POST['genero'] ?? ''));
    $direccion = trim((string) ($_POST['direccion'] ?? ''));
    $direccionSave = cneCiudadanoDireccionParaGuardar($direccion);
    $estadoIdSave = cneCiudadanoEstadoMunicipioIdParaGuardar($_POST['estado_id'] ?? null);
    $municipioIdSave = cneCiudadanoEstadoMunicipioIdParaGuardar($_POST['municipio_id'] ?? null);
    $fecha_nacimiento_raw = isset($_POST['fecha_nacimiento']) ? trim((string) $_POST['fecha_nacimiento']) : '';

    if ($cedula_numero !== '') {
        $cedula_completa = $cedula_tipo . '-' . $cedula_numero;
    } else {
        $stmt = $db->query("
            SELECT ciudadano_identificacion
            FROM ciudadanos
            WHERE ciudadano_identificacion LIKE 'V-CNE%'
            ORDER BY ciudadano_identificacion DESC
            LIMIT 1
        ");
        $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
        $siguiente = 1;
        if ($ultimo && preg_match('/V-CNE(\d+)$/i', $ultimo['ciudadano_identificacion'], $m)) {
            $siguiente = (int)$m[1] + 1;
        }
        $cedula_completa = 'V-CNE' . str_pad((string)$siguiente, 4, '0', STR_PAD_LEFT);
    }

    $tel_cod = trim((string) ($_POST['telefono_codigo'] ?? '0412'));
    $tel_num = trim((string) ($_POST['telefono_numero'] ?? ''));
    $telefonoSave = cneCiudadanoTelefonoParaGuardar($tel_cod, $tel_num);

    $nombresSave = cneCiudadanoCampoEsNA($nombres) ? 'N/A' : $nombres;
    $apellidosSave = cneCiudadanoCampoEsNA($apellidos) ? 'N/A' : $apellidos;
    $generoSave = cneCiudadanoGeneroRequerido($genero);
    $fechaSave = cneCiudadanoFechaParaGuardar($fecha_nacimiento_raw);

    $institucion_id = null;
    $institucionParam = $_POST['institucion'];
    if ($institucionParam === 'otro') {
        $nombreOtro = isset($_POST['institucion_otro']) ? trim($_POST['institucion_otro']) : '';
        if ($nombreOtro === '' || $nombreOtro === '[]') {
            throw new Exception('Debe especificar el nombre de la institución cuando selecciona "Otro..."');
        }
        $stmt = $db->prepare('SELECT institucion_id FROM institucion WHERE institucion_nombre = :nombre LIMIT 1');
        $stmt->execute([':nombre' => $nombreOtro]);
        $existe = $stmt->fetch();
        if ($existe && !empty($existe['institucion_id'])) {
            $institucion_id = (int)$existe['institucion_id'];
        } else {
            $stmt = $db->prepare('INSERT INTO institucion (institucion_nombre) VALUES (:nombre)');
            $stmt->execute([':nombre' => $nombreOtro]);
            $institucion_id = (int)$db->lastInsertId();
        }
    } else {
        $institucion_id = (int)$institucionParam;
        if ($institucion_id <= 0) {
            throw new Exception('Institución inválida');
        }
    }

    $stmt = $db->prepare('
        SELECT ciudadano_identificacion
        FROM ciudadanos
        WHERE ciudadano_identificacion = :cedula
    ');
    $stmt->execute([':cedula' => $cedula_completa]);
    $ciudadano_existente = $stmt->fetch();

    if (!$ciudadano_existente) {
        $stmt = $db->prepare('
            INSERT INTO ciudadanos (
                ciudadano_identificacion,
                ciudadano_nombres,
                ciudadano_apellidos,
                ciudadano_tipo_identificacion,
                ciudadano_nacionalidad,
                ciudadano_fecha_nacimiento,
                ciudadano_genero,
                ciudadano_telefono,
                ciudadano_email,
                ciudadano_direccion,
                estado_id,
                municipio_id,
                institucion_id
            ) VALUES (
                :identificacion,
                :nombres,
                :apellidos,
                :tipo_identificacion,
                :nacionalidad,
                :fecha_nacimiento,
                :genero,
                :telefono,
                :ciudadano_email,
                :direccion,
                :estado_id,
                :municipio_id,
                :institucion_id
            )
        ');

        $tipo_identificacion = in_array($cedula_tipo, ['V', 'E', 'J', 'G'], true) ? 'cedula' : 'pasaporte';
        $nacionalidad = $cedula_tipo === 'E' ? 'E' : 'V';

        $ciudadano_email = !empty(trim($_POST['ciudadano_email'] ?? '')) ? trim($_POST['ciudadano_email']) : null;
        $stmt->execute([
            ':identificacion' => $cedula_completa,
            ':nombres' => $nombresSave,
            ':apellidos' => $apellidosSave,
            ':tipo_identificacion' => $tipo_identificacion,
            ':nacionalidad' => $nacionalidad,
            ':fecha_nacimiento' => $fechaSave,
            ':genero' => $generoSave,
            ':telefono' => $telefonoSave,
            ':ciudadano_email' => $ciudadano_email,
            ':direccion' => $direccionSave,
            ':estado_id' => $estadoIdSave,
            ':municipio_id' => $municipioIdSave,
            ':institucion_id' => $institucion_id,
        ]);
    } else {
        $stmtPrev = $db->prepare('
            SELECT ciudadano_nombres, ciudadano_apellidos, ciudadano_telefono, ciudadano_genero, ciudadano_fecha_nacimiento,
                ciudadano_direccion, estado_id, municipio_id
            FROM ciudadanos WHERE ciudadano_identificacion = :id LIMIT 1
        ');
        $stmtPrev->execute([':id' => $cedula_completa]);
        $prevRow = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        if (!$prevRow) {
            throw new Exception('No se pudo cargar el ciudadano existente');
        }
        cneCiudadanoValidarNoSobrescribirIdentidadRegistro($prevRow, $nombresSave, $apellidosSave, $generoSave, $telefonoSave, $fechaSave);
        cneCiudadanoValidarNoSobrescribirUbicacionRegistro($prevRow, $direccionSave, $estadoIdSave, $municipioIdSave);

        $ciudadano_email = !empty(trim($_POST['ciudadano_email'] ?? '')) ? trim($_POST['ciudadano_email']) : null;
        $stmt = $db->prepare('
            UPDATE ciudadanos SET
                ciudadano_nombres = :nombres,
                ciudadano_apellidos = :apellidos,
                ciudadano_genero = :genero,
                ciudadano_telefono = :telefono,
                ciudadano_email = :ciudadano_email,
                ciudadano_direccion = :direccion,
                ciudadano_fecha_nacimiento = :fecha_nacimiento,
                estado_id = :estado_id,
                municipio_id = :municipio_id,
                institucion_id = :institucion_id
            WHERE ciudadano_identificacion = :identificacion
        ');
        $stmt->execute([
            ':identificacion' => $cedula_completa,
            ':nombres' => $nombresSave,
            ':apellidos' => $apellidosSave,
            ':genero' => $generoSave,
            ':telefono' => $telefonoSave,
            ':ciudadano_email' => $ciudadano_email,
            ':direccion' => $direccionSave,
            ':fecha_nacimiento' => $fechaSave,
            ':estado_id' => $estadoIdSave,
            ':municipio_id' => $municipioIdSave,
            ':institucion_id' => $institucion_id,
        ]);
    }

    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING(solicitud_numero, 5) AS UNSIGNED)) as ultimo_numero
        FROM solicitudes
        WHERE solicitud_numero LIKE 'CNE-%'
    ");
    $stmt->execute();
    $result = $stmt->fetch();

    $siguiente_numero = 1;
    if ($result && $result['ultimo_numero'] !== null) {
        $siguiente_numero = (int)$result['ultimo_numero'] + 1;
    }

    $solicitud_numero = 'CNE-' . str_pad((string)$siguiente_numero, 4, '0', STR_PAD_LEFT);

    $estado = ($tipo_solicitud === 'inmediato') ? 'completada' : 'pendiente';
    $fecha_completada = ($tipo_solicitud === 'inmediato') ? date('Y-m-d H:i:s') : null;

    $requisitosSeleccionadosIds = [];
    $requisitosSeleccionadosLabels = [];
    $stmtCntReq = $db->prepare('SELECT COUNT(*) FROM requisitos WHERE tramite_id = ? AND requisito_activo = 1');
    $stmtCntReq->execute([$tramite_id]);
    $cantReqTramite = (int) $stmtCntReq->fetchColumn();

    if ($tipo_solicitud === 'inmediato') {
        $rawIds = $_POST['requisitos_seleccionados'] ?? '[]';
        $decodedIds = json_decode((string) $rawIds, true);
        if (!is_array($decodedIds)) {
            $decodedIds = [];
        }
        $requisitosSeleccionadosIds = array_values(array_unique(array_filter(array_map('intval', $decodedIds), function ($x) {
            return $x > 0;
        })));

        if ($cantReqTramite > 0 && count($requisitosSeleccionadosIds) === 0) {
            throw new Exception('Debe indicar al menos un requisito entregado para el trámite inmediato.');
        }

        if (count($requisitosSeleccionadosIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($requisitosSeleccionadosIds), '?'));
            $stmtValidReq = $db->prepare(
                "SELECT requisito_id, requisito_nombre FROM requisitos WHERE tramite_id = ? AND requisito_activo = 1 AND requisito_id IN ($placeholders)"
            );
            $stmtValidReq->execute(array_merge([$tramite_id], $requisitosSeleccionadosIds));
            $rowsValidReq = $stmtValidReq->fetchAll(PDO::FETCH_ASSOC);
            if (count($rowsValidReq) !== count($requisitosSeleccionadosIds)) {
                throw new Exception('Los requisitos seleccionados no son válidos para este trámite.');
            }
            foreach ($rowsValidReq as $rv) {
                $requisitosSeleccionadosLabels[] = trim((string) ($rv['requisito_nombre'] ?? ''));
            }
        }
    }

    $requisitosNombresParaHistorial = $requisitosSeleccionadosLabels;
    if ($tipo_solicitud === 'inmediato' && isset($_POST['requisitos_marcados_nombres'])) {
        $decNombres = json_decode((string) $_POST['requisitos_marcados_nombres'], true);
        if (is_array($decNombres)) {
            $nombresCliente = [];
            foreach ($decNombres as $n) {
                $t = trim((string) $n);
                if ($t !== '') {
                    $nombresCliente[] = $t;
                }
            }
            if (count($nombresCliente) === count($requisitosSeleccionadosIds) && count($requisitosSeleccionadosIds) > 0) {
                $requisitosNombresParaHistorial = $nombresCliente;
            }
        }
    }

    $nombreFunc = $_SESSION['nombre_completo'] ?? 'Funcionario';
    $descripcion = 'Solicitud registrada por ' . $nombreFunc
        . ' en la coordinación de ' . $nombre_coordinacion_destino
        . ' para ' . $nombresSave . ' ' . $apellidosSave;
    if ($tipo_solicitud === 'inmediato' && count($requisitosNombresParaHistorial) > 0) {
        $descripcion .= '. Requisitos entregados/registrados: ' . implode(', ', $requisitosNombresParaHistorial);
    }

    $stmt = $db->prepare('
        INSERT INTO solicitudes (
            solicitud_numero,
            ciudadano_identificacion,
            tramite_id,
            solicitud_descripcion,
            solicitud_estado,
            solicitud_fecha_solicitud,
            created_by,
            empleado_asignado_id,
            solicitud_fecha_completada
        ) VALUES (
            :numero,
            :ciudadano_id,
            :tramite_id,
            :descripcion,
            :estado,
            NOW(),
            :created_by,
            :empleado_asignado,
            :fecha_completada
        )
    ');

    $stmt->execute([
        ':numero' => $solicitud_numero,
        ':ciudadano_id' => $cedula_completa,
        ':tramite_id' => $tramite_id,
        ':descripcion' => $descripcion,
        ':estado' => $estado,
        ':created_by' => $_SESSION['user_id'],
        ':empleado_asignado' => $_SESSION['user_id'],
        ':fecha_completada' => $fecha_completada,
    ]);

    $solicitud_id = $db->lastInsertId();

    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'solicitudes' AND COLUMN_NAME = 'coordinacion_actual_id'");
        if ((bool)$chk->fetchColumn()) {
            $stmtSet = $db->prepare('
                UPDATE solicitudes s
                SET s.coordinacion_actual_id = :coord_id
                WHERE s.solicitud_id = :sid
            ');
            $stmtSet->execute([
                ':coord_id' => $tramiteRow['coordinacion_id'] ?? null,
                ':sid' => $solicitud_id,
            ]);
        }
    } catch (Exception $e) {
    }

    $coordNotif = (int)($tramiteRow['coordinacion_id'] ?? 0);
    $notificarPendienteRealtime = ($tipo_solicitud === 'normal' && $estado === 'pendiente');
    if ($notificarPendienteRealtime && $coordNotif > 0) {
        $mensaje = 'Nueva solicitud pendiente: ' . $solicitud_numero . ' — ' . ($tramiteRow['tramite_nombre'] ?? '');
        $stmt = $db->prepare('
            INSERT INTO notificaciones (
                usuario_id,
                coordinacion_id,
                solicitud_id,
                notificacion_titulo,
                mensaje,
                notificacion_estado
            ) VALUES (
                NULL,
                :coord_id,
                :sol_id,
                :titulo,
                :mensaje,
                \'no_leido\'
            )
        ');
        $stmt->execute([
            ':coord_id' => $coordNotif,
            ':sol_id' => $solicitud_id,
            ':titulo' => 'Nueva solicitud pendiente',
            ':mensaje' => $mensaje,
        ]);
        pushRealtimeEnvelope([
            'targets' => ['coordinacion_ids' => [$coordNotif]],
            'event' => ['type' => 'notification_hint'],
        ]);
    }

    if ($notificarPendienteRealtime && stripos($cedula_completa, 'V-CNE') === 0) {
        try {
            $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones' AND COLUMN_NAME = 'destinatario_rol_id'");
            if ($chk && $chk->fetch()) {
                $msgAdmin = 'Nuevo ciudadano registrado con Cédula Temporal ' . $cedula_completa . '. Pendiente por actualizar datos de identidad real.';
                $stmtNotif = $db->prepare('
                    INSERT INTO notificaciones (usuario_id, coordinacion_id, destinatario_rol_id, solicitud_id, notificacion_titulo, mensaje, notificacion_estado)
                    VALUES (NULL, NULL, 5, :sol_id, \'Cédula Temporal Asignada\', :msg, \'no_leido\')
                ');
                $stmtNotif->execute([':sol_id' => $solicitud_id, ':msg' => $msgAdmin]);
                pushRealtimeEnvelope([
                    'targets' => ['rol_ids' => [5]],
                    'event' => ['type' => 'notification_hint'],
                ]);
            }
        } catch (Exception $e) {
            error_log('No se pudo crear notificación admin (cédula temporal): ' . $e->getMessage());
        }
    }

    if ($tipo_solicitud === 'inmediato') {
        foreach ($requisitosSeleccionadosIds as $rid) {
            $stmt2 = $db->prepare('
                INSERT INTO requisitos_solicitud (
                    solicitud_id,
                    requisito_id,
                    requisitos_solicitud_status
                ) VALUES (:solicitud_id, :requisito_id, :status)
            ');
            $stmt2->execute([
                ':solicitud_id' => $solicitud_id,
                ':requisito_id' => $rid,
                ':status' => 'aprobado',
            ]);
        }
    } else {
        $stmt = $db->prepare('
            SELECT requisito_id
            FROM requisitos
            WHERE tramite_id = :tramite_id AND requisito_activo = 1
        ');
        $stmt->execute([':tramite_id' => $tramite_id]);
        $requisitos = $stmt->fetchAll();
        $estado_requisitos = 'pendiente';
        foreach ($requisitos as $requisito) {
            $stmt2 = $db->prepare('
                INSERT INTO requisitos_solicitud (
                    solicitud_id,
                    requisito_id,
                    requisitos_solicitud_status
                ) VALUES (:solicitud_id, :requisito_id, :status)
            ');
            $stmt2->execute([
                ':solicitud_id' => $solicitud_id,
                ':requisito_id' => $requisito['requisito_id'],
                ':status' => $estado_requisitos,
            ]);
        }
    }

    $accion_codigo = ($tipo_solicitud === 'inmediato') ? 'SOLICITUD_COMPLETADA' : 'SOLICITUD_CREADA';
    $accion_descripcion = ($tipo_solicitud === 'inmediato')
        ? 'Trámite completado inmediatamente por funcionario en la coordinación de ' . $nombre_coordinacion_destino
        : 'Solicitud creada por funcionario en la coordinación de ' . $nombre_coordinacion_destino;

    $auditoriaExtra = [
        'ciudadano' => $cedula_completa,
        'tramite_id' => $tramite_id,
        'usuario_creador' => $_SESSION['user_id'],
        'numero_seguimiento' => $solicitud_numero,
        'tipo_solicitud' => $tipo_solicitud,
        'estado_inicial' => $estado,
        'id_coordinacion_destino' => $coord_destino,
        'coordinacion_destino' => $coordinacion_destino_raw !== '' ? $coordinacion_destino_raw : $nombre_coordinacion_destino,
    ];
    if ($tipo_solicitud === 'inmediato') {
        $auditoriaExtra['requisitos_entregados_ids'] = $requisitosSeleccionadosIds;
        $auditoriaExtra['requisitos_entregados_nombres'] = $requisitosNombresParaHistorial;
    }
    registrarAuditoria(
        $_SESSION['user_id'],
        $solicitud_id,
        $accion_codigo,
        $accion_descripcion,
        $auditoriaExtra
    );

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => ($tipo_solicitud === 'inmediato')
            ? 'Trámite registrado y completado exitosamente'
            : 'Solicitud registrada exitosamente',
        'numero_seguimiento' => $solicitud_numero,
        'solicitud_id' => (int)$solicitud_id,
        'tipo_solicitud' => $tipo_solicitud,
        'estado' => $estado,
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Error empleado_registrar_solicitud: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
    ]);
}
