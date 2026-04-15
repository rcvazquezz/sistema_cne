<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}
$solicitud_id = isset($_POST['solicitud_id']) ? (int)$_POST['solicitud_id'] : 0;
$accion = $_POST['accion'] ?? '';
$codigo_interno = $_POST['codigo_interno'] ?? '';
$observaciones = $_POST['observaciones'] ?? '';
$destino_coordinacion_id = $_POST['destino_coordinacion_id'] ?? '';
if ($solicitud_id <= 0 || !$accion) {
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos'], JSON_UNESCAPED_UNICODE);
    exit;
}
$observaciones = trim($observaciones);
if ($accion !== 'iniciar' && strlen($observaciones) < 5) {
    echo json_encode(['success' => false, 'message' => 'Debe escribir observaciones (mínimo 5 caracteres)'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $db = getDB();
    require_once __DIR__ . '/../includes/cne_admin_view_context.php';
    $usuario = cneObtenerUsuarioContextoSesion($_SESSION['user_id']);
    $mi_coordinacion_id = $usuario['coordinacion_id'] ?? null;
    if (!$mi_coordinacion_id) {
        echo json_encode(['success' => false, 'message' => 'Coordinación del usuario no definida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $db->prepare("
        SELECT 
            COALESCE(au.coord_destino, t.coordinacion_id) AS coord_actual,
            s.solicitud_estado
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN (
            SELECT a.solicitud_id,
                   c2.coordinacion_id AS coord_destino
            FROM auditoria a
            LEFT JOIN coordinacion c2 
              ON c2.coordinacion_nombre = JSON_UNQUOTE(JSON_EXTRACT(a.detalles, '$.coordinacion_destino'))
            WHERE a.auditoria_id IN (
                SELECT MAX(a2.auditoria_id)
                FROM auditoria a2
                WHERE a2.solicitud_id = a.solicitud_id
                  AND (
                    a2.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . "
                    OR a2.accion_descripcion LIKE '%redirigido%'
                  )
            )
        ) au ON au.solicitud_id = s.solicitud_id
        WHERE s.solicitud_id = :id
    ");
    $stmt->execute([':id' => $solicitud_id]);
    $info = $stmt->fetch();
    if (!$info) {
        echo json_encode(['success' => false, 'message' => 'Trámite no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$info['coord_actual'] !== (int)$mi_coordinacion_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso Denegado: Este trámite ya fue redirigido y solo puede ser gestionado por la coordinación actual.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bloqueo = cneEmpleadoMensajeSiSolicitudNoGestionable($db, $solicitud_id);
    if ($bloqueo !== null) {
        echo json_encode(['success' => false, 'message' => $bloqueo], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $estado = null;
    $accion_codigo = null;
    $accion_desc = null;
    $fecha_completada = null;
    if ($accion === 'iniciar') {
        $estado = 'en_revision';
        $accion_codigo = 'INICIO_TRAMITE';
        $accion_desc = 'Trámite iniciado por el funcionario';
    } elseif ($accion === 'completar') {
        $estado = 'completada';
        $accion_codigo = 'FUNCIONARIO_COMPLETA_TRAMITE';
        $accion_desc = 'El funcionario completa el trámite';
        $fecha_completada = date('Y-m-d H:i:s');
    } elseif ($accion === 'redirigir') {
        $estado = 'pendiente';
        $accion_codigo = 'FUNCIONARIO_REDIRIGE_TRAMITE';
        $accion_desc = 'El funcionario redirige el trámite a otra coordinación';
    } elseif ($accion === 'vencer') {
        $estado = 'vencida';
        $accion_codigo = 'FUNCIONARIO_MARCA_VENCIDA';
        $accion_desc = 'El funcionario marca el trámite como vencido';
    } else {
        echo json_encode(['success' => false, 'message' => 'Acción inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($accion === 'redirigir') {
        if (!$destino_coordinacion_id) {
            echo json_encode(['success' => false, 'message' => 'Debe indicar la coordinación destino'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((int)$destino_coordinacion_id === (int)$mi_coordinacion_id) {
            echo json_encode(['success' => false, 'message' => 'La coordinación destino debe ser distinta a la actual'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (coordinacionEsOficinaAtencionAlCiudadano($db, (int) $destino_coordinacion_id)) {
            echo json_encode(['success' => false, 'message' => mensajeRedireccionAtencionCiudadanoProhibida()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    try {
        if ($accion === 'iniciar') {
            $stmt = $db->prepare("
                UPDATE solicitudes 
                SET solicitud_estado = :estado,
                    empleado_asignado_id = :empleado_id
                WHERE solicitud_id = :id
            ");
            $ok = $stmt->execute([
                ':estado' => $estado,
                ':empleado_id' => $_SESSION['user_id'],
                ':id' => $solicitud_id
            ]);
            if (!$ok) {
                $err = $stmt->errorInfo();
                throw new Exception('SQLSTATE ' . ($err[0] ?? '') . ' ' . ($err[2] ?? 'Fallo al actualizar estado'));
            }
        } else {
            if ($accion === 'completar') {
                $stmt = $db->prepare("
                    UPDATE solicitudes SET 
                        solicitud_estado = :estado,
                        empleado_asignado_id = :empleado_id,
                        codigo_interno = :codigo,
                        solicitud_fecha_completada = :fecha
                    WHERE solicitud_id = :id
                ");
                $ok = $stmt->execute([
                    ':estado' => $estado,
                    ':empleado_id' => $_SESSION['user_id'],
                    ':codigo' => $codigo_interno ?: null,
                    ':fecha' => $fecha_completada,
                    ':id' => $solicitud_id
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE solicitudes SET 
                        solicitud_estado = :estado,
                        codigo_interno = :codigo,
                        solicitud_fecha_completada = :fecha
                    WHERE solicitud_id = :id
                ");
                $ok = $stmt->execute([
                    ':estado' => $estado,
                    ':codigo' => $codigo_interno ?: null,
                    ':fecha' => $fecha_completada,
                    ':id' => $solicitud_id
                ]);
            }
            if (!$ok) {
                $err = $stmt->errorInfo();
                throw new Exception('SQLSTATE ' . ($err[0] ?? '') . ' ' . ($err[2] ?? 'Fallo al actualizar solicitud'));
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($accion === 'redirigir' && $destino_coordinacion_id) {
        $stmt = $db->prepare("
            SELECT 
                s.solicitud_numero,
                t.tramite_nombre,
                t.coordinacion_id AS coord_origen
            FROM solicitudes s
            JOIN tramite t ON s.tramite_id = t.tramite_id
            WHERE s.solicitud_id = :sid
        ");
        $stmt->execute([':sid' => $solicitud_id]);
        $meta = $stmt->fetch();
        $numero = $meta['solicitud_numero'] ?? '';
        $tramiteNom = $meta['tramite_nombre'] ?? '';
        $coordOrigenId = (int)($meta['coord_origen'] ?? 0);
        $origenNombre = '';
        if ($coordOrigenId) {
            $stmt = $db->prepare("SELECT coordinacion_nombre FROM coordinacion WHERE coordinacion_id = :cid");
            $stmt->execute([':cid' => $coordOrigenId]);
            $row = $stmt->fetch();
            $origenNombre = $row['coordinacion_nombre'] ?? '';
        }
        // Nombre de la coordinación destino
        $destinoNombre = '';
        $stmt = $db->prepare("SELECT coordinacion_nombre FROM coordinacion WHERE coordinacion_id = :cid");
        $stmt->execute([':cid' => $destino_coordinacion_id]);
        $rowDest = $stmt->fetch();
        $destinoNombre = $rowDest['coordinacion_nombre'] ?? '';
        $origenUi = presentarNombreCoordinacionUi($origenNombre !== '' ? $origenNombre : null);
        if ($origenUi === '') {
            $origenUi = $origenNombre;
        }
        $destinoUi = presentarNombreCoordinacionUi($destinoNombre !== '' ? $destinoNombre : null);
        if ($destinoUi === '') {
            $destinoUi = $destinoNombre;
        }
        $mensaje = "Trámite redirigido: {$numero} - {$tramiteNom}. Proveniente de: {$origenUi}";
        $stmt = $db->prepare("
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
                'Trámite redirigido',
                :mensaje,
                'no_leido'
            )
        ");
        $stmt->execute([
            ':coord_id' => $destino_coordinacion_id,
            ':sol_id' => $solicitud_id,
            ':mensaje' => $mensaje
        ]);
        pushRealtimeEnvelope([
            'targets' => ['coordinacion_ids' => [(int) $destino_coordinacion_id]],
            'event' => ['type' => 'notification_hint'],
        ]);
        // Auditor?a de redirecci?n (acci?n normalizada a REDIRECCION en registrarAuditoria)
        $accion_codigo = 'REDIRECCION';
        $accion_desc = "Trámite redirigido desde {$origenUi} hacia {$destinoUi}. Motivo: {$observaciones}";
        // Enriquecer detalles
        $detalle_extra = [
            'coordinacion_origen' => $origenNombre,
            'coordinacion_destino' => $destinoNombre,
            'observaciones' => $observaciones,
            'solicitud_numero' => $numero,
            'tramite_nombre' => $tramiteNom
        ];
    }
    // Guardar observaciones en detalles_solicitud
    $detalle_texto = $observaciones;
    if ($accion === 'iniciar' && $detalle_texto === '') {
        $detalle_texto = 'Trámite iniciado por el funcionario';
    }
    $stmt = $db->prepare("
        INSERT INTO detalles_solicitud (solicitud_id, detalle_texto, creado_por)
        VALUES (:solicitud_id, :detalle_texto, :creado_por)
    ");
    $stmt->execute([
        ':solicitud_id' => $solicitud_id,
        ':detalle_texto' => $detalle_texto,
        ':creado_por' => $_SESSION['user_id']
    ]);
    registrarAuditoria(
        $_SESSION['user_id'],
        $solicitud_id,
        $accion_codigo,
        $accion_desc,
        isset($detalle_extra) 
            ? array_merge(['codigo_interno' => $codigo_interno], $detalle_extra) 
            : ['codigo_interno' => $codigo_interno, 'detalle' => 'Trámite iniciado por el funcionario', 'observaciones' => $detalle_texto]
    );
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Error actualizar solicitud: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
