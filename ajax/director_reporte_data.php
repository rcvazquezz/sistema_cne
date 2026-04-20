<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 4) {
    echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$vista = $_GET['vista'] ?? 'funcionario';
$area_id = $_GET['area_id'] ?? '';
$usuario_id = trim($_GET['usuario_id'] ?? '');
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

if (!in_array($tipo, ['tramites', 'solicitudes', 'metricas', 'usuarios', 'auditoria'], true)) {
    echo json_encode(['success' => false, 'message' => 'Tipo de reporte inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Vista "Oficina de Atención al Ciudadano": solicitudes del mostrador (rol atención, p. ej. rol_id=1 / nombre en BD)
 * más trámites inmediatos registrados por funcionarios adscritos a la coordinación OAC.
 */
function cneSqlDirectorFiltroVistaAtencion(string $aliasU = 'u_creator', string $aliasS = 's'): string
{
    return "(
        EXISTS (SELECT 1 FROM roles r_ac WHERE r_ac.rol_id = {$aliasU}.rol_id AND (r_ac.rol_id = 1 OR r_ac.rol_nombre IN ('Atención al Ciudadano', 'Oficina de Atención al Ciudadano')))
        OR (
            EXISTS (SELECT 1 FROM roles r_fn WHERE r_fn.rol_id = {$aliasU}.rol_id AND r_fn.rol_id = 2)
            AND EXISTS (
                SELECT 1 FROM coordinacion c_oac
                WHERE c_oac.coordinacion_id = {$aliasU}.coordinacion_id
                  AND (c_oac.coordinacion_nombre LIKE '%Atención al Ciudadano%' OR c_oac.coordinacion_nombre LIKE '%Atencion al Ciudadano%')
            )
            AND {$aliasS}.solicitud_estado = 'completada'
            AND {$aliasS}.solicitud_fecha_completada IS NOT NULL
            AND TIMESTAMPDIFF(SECOND, {$aliasS}.solicitud_created_at, {$aliasS}.solicitud_fecha_completada) >= 0
            AND TIMESTAMPDIFF(SECOND, {$aliasS}.solicitud_created_at, {$aliasS}.solicitud_fecha_completada) <= 300
        )
    )";
}

/** Heurística: completada al registrar (entrada o empleado OAC con trámite inmediato). */
function cneSqlDirectorCaseRegistroTramite(string $aliasS = 's'): string
{
    return "CASE
        WHEN {$aliasS}.solicitud_estado = 'completada'
         AND {$aliasS}.solicitud_fecha_completada IS NOT NULL
         AND TIMESTAMPDIFF(SECOND, {$aliasS}.solicitud_created_at, {$aliasS}.solicitud_fecha_completada) >= 0
         AND TIMESTAMPDIFF(SECOND, {$aliasS}.solicitud_created_at, {$aliasS}.solicitud_fecha_completada) <= 300
        THEN 'Trámite inmediato'
        ELSE 'Flujo normal'
    END";
}

/**
 * Texto de alcance para títulos de exportación (PDF/Excel): área o vista atención.
 */
function directorReporteSubtituloAlcance(PDO $db, string $vista, string $area_id, string $usuario_id): string
{
    if ($vista === 'atencion') {
        $uid = trim($usuario_id);
        if ($uid === '') {
            return 'Global';
        }
        $st = $db->prepare('SELECT CONCAT(TRIM(user_nombres), " ", TRIM(user_apellidos)) AS n FROM usuarios WHERE user_identificacion = :id LIMIT 1');
        $st->execute([':id' => $uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $n = trim((string) ($row['n'] ?? ''));

        return $n !== '' ? $n : $uid;
    }
    $aid = trim((string) $area_id);
    if ($aid === '' || $aid === '0') {
        return 'Global';
    }
    $st = $db->prepare('SELECT coordinacion_nombre FROM coordinacion WHERE coordinacion_id = ? LIMIT 1');
    $st->execute([(int) $aid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $nom = trim((string) ($row['coordinacion_nombre'] ?? ''));
    if ($nom === '') {
        return 'Área ' . (int) $aid;
    }
    $ui = presentarNombreCoordinacionUi($nom);

    return $ui !== '' ? $ui : $nom;
}

try {
    $db = getDB();
    $subtitulo = directorReporteSubtituloAlcance($db, $vista, $area_id, $usuario_id);

    if ($tipo === 'tramites') {
        $sql = "
            SELECT co.coordinacion_nombre AS area_nombre, t.tramite_nombre AS tipo_tramite
            FROM tramite t
            JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
            WHERE 1=1
        ";
        $params = [];
        if ($vista === 'atencion' && $usuario_id !== '') {
            $sql .= " AND t.coordinacion_id = (SELECT uu.coordinacion_id FROM usuarios uu WHERE uu.user_identificacion = :uid_tram LIMIT 1)";
            $params[':uid_tram'] = $usuario_id;
        } elseif ($area_id !== '') {
            $sql .= " AND t.coordinacion_id = :area";
            $params[':area'] = (int)$area_id;
        }
        // Nota: Los trámites son un catálogo, no tienen fecha de registro en esta tabla. 
        // Si el usuario se refiere a trámites registrados en un período, debería usar 'solicitudes'.
        // Pero para consistencia, permitimos filtrar por área.
        
        $sql .= " ORDER BY co.coordinacion_nombre ASC, t.tramite_nombre ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        echo json_encode(['success' => true, 'rows' => $rows, 'headers' => ['Área', 'Tipo de Trámite'], 'subtitulo' => $subtitulo], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tipo === 'solicitudes') {
        $joinRecCar = cneSqlJoinRecibidoCaracasPorSolicitud($db);
        $caseEstRep = cneSqlCaseEstadoParaReporte('au.solicitud_id IS NOT NULL');
        $caseRegistro = cneSqlDirectorCaseRegistroTramite('s');
        $sql = "
            SELECT s.solicitud_numero, CONCAT(c.ciudadano_nombres, ' ', c.ciudadano_apellidos) AS ciudadano_nombre,
                   co.coordinacion_nombre AS area_nombre, t.tramite_nombre AS tipo_tramite,
                   ({$caseRegistro}) AS registro_tipo,
                   ({$caseEstRep}) AS estado_computado,
                   (SELECT ds.detalle_texto FROM detalles_solicitud ds WHERE ds.solicitud_id = s.solicitud_id ORDER BY ds.detalle_id DESC LIMIT 1) AS motivo_observacion,
                   DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro
            FROM solicitudes s
            JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
            JOIN tramite t ON s.tramite_id = t.tramite_id
            JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
            JOIN usuarios u_creator ON s.created_by = u_creator.user_identificacion
            LEFT JOIN usuarios u_asignado ON s.empleado_asignado_id = u_asignado.user_identificacion
            LEFT JOIN (
                SELECT a.solicitud_id FROM auditoria a
                JOIN (SELECT solicitud_id, MAX(auditoria_id) AS max_id FROM auditoria GROUP BY solicitud_id) last
                  ON a.solicitud_id = last.solicitud_id AND a.auditoria_id = last.max_id
                WHERE a.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . "
                   OR (a.accion_descripcion IS NOT NULL AND a.accion_descripcion LIKE '%redirigido%')
            ) au ON au.solicitud_id = s.solicitud_id
            {$joinRecCar}
            WHERE 1=1
        ";
        $params = [];
        if ($vista === 'atencion') {
            $sql .= ' AND ' . cneSqlDirectorFiltroVistaAtencion('u_creator', 's');
            if ($usuario_id !== '') {
                $sql .= " AND u_creator.user_identificacion = :usuario_id";
                $params[':usuario_id'] = $usuario_id;
            }
        } elseif ($vista === 'funcionario') {
            $sql .= " AND (u_creator.rol_id = 2 OR u_asignado.rol_id = 2)";
        }

        if ($vista !== 'atencion' && $area_id !== '') {
            $sql .= " AND t.coordinacion_id = :area";
            $params[':area'] = (int)$area_id;
        }
        if ($fecha_desde !== '') { $sql .= " AND DATE(s.solicitud_created_at) >= :fd"; $params[':fd'] = $fecha_desde; }
        if ($fecha_hasta !== '') { $sql .= " AND DATE(s.solicitud_created_at) <= :fh"; $params[':fh'] = $fecha_hasta; }
        $sql .= " ORDER BY s.solicitud_created_at DESC LIMIT 2000";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (array_key_exists('estado_computado', $r)) {
                $r['estado_computado'] = presentarEstadoSolicitudReporteDirector($r['estado_computado']);
            }
        }
        unset($r);
        foreach ($rows as &$r2) {
            if (array_key_exists('motivo_observacion', $r2) && ($r2['motivo_observacion'] === null || $r2['motivo_observacion'] === '')) {
                $r2['motivo_observacion'] = '—';
            }
        }
        unset($r2);
        echo json_encode(['success' => true, 'rows' => $rows, 'headers' => ['N° Seguimiento', 'Ciudadano', 'Área', 'Tipo', 'Registro', 'Estado', 'Motivo / Observación', 'Fecha/Hora'], 'subtitulo' => $subtitulo], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tipo === 'metricas') {
        $joinRecCarM = cneSqlJoinRecibidoCaracasPorSolicitud($db);
        $caseEstRepM = cneSqlCaseEstadoParaReporte('au.solicitud_id IS NOT NULL');
        $sql = "
            SELECT ({$caseEstRepM}) AS estado_computado, COUNT(*) AS cantidad
            FROM solicitudes s
            JOIN tramite t ON s.tramite_id = t.tramite_id
            JOIN usuarios u_creator ON s.created_by = u_creator.user_identificacion
            LEFT JOIN usuarios u_asignado ON s.empleado_asignado_id = u_asignado.user_identificacion
            LEFT JOIN (
                SELECT a.solicitud_id FROM auditoria a
                JOIN (SELECT solicitud_id, MAX(auditoria_id) AS max_id FROM auditoria GROUP BY solicitud_id) last
                  ON a.solicitud_id = last.solicitud_id AND a.auditoria_id = last.max_id
                WHERE a.accion_codigo IN " . auditoriaSqlInCodigosRedireccion() . "
                   OR (a.accion_descripcion IS NOT NULL AND a.accion_descripcion LIKE '%redirigido%')
            ) au ON au.solicitud_id = s.solicitud_id
            {$joinRecCarM}
            WHERE 1=1
        ";
        $params = [];
        if ($vista === 'atencion') {
            $sql .= ' AND ' . cneSqlDirectorFiltroVistaAtencion('u_creator', 's');
            if ($usuario_id !== '') {
                $sql .= " AND u_creator.user_identificacion = :usuario_id";
                $params[':usuario_id'] = $usuario_id;
            }
        } elseif ($vista === 'funcionario') {
            $sql .= " AND (u_creator.rol_id = 2 OR u_asignado.rol_id = 2)";
        }

        if ($vista !== 'atencion' && $area_id !== '') {
            $sql .= " AND t.coordinacion_id = :area";
            $params[':area'] = (int)$area_id;
        }
        if ($fecha_desde !== '') { $sql .= " AND DATE(s.solicitud_created_at) >= :fd"; $params[':fd'] = $fecha_desde; }
        if ($fecha_hasta !== '') { $sql .= " AND DATE(s.solicitud_created_at) <= :fh"; $params[':fh'] = $fecha_hasta; }
        $sql .= " GROUP BY estado_computado";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $map = ['Pendiente' => 0, 'En Proceso' => 0, 'Completada' => 0, 'Vencida' => 0, 'Redirigida' => 0, 'Invalidada' => 0];
        foreach ($rows as $r) {
            $e = $r['estado_computado'] ?? '';
            $c = (int)$r['cantidad'];
            if ($e === 'pendiente') $map['Pendiente'] += $c;
            elseif (in_array($e, ['en_revision','aprobada'], true)) $map['En Proceso'] += $c;
            elseif ($e === 'completada') $map['Completada'] += $c;
            elseif ($e === 'vencida') $map['Vencida'] += $c;
            elseif ($e === 'redirigida') $map['Redirigida'] += $c;
            elseif ($e === 'invalidada') $map['Invalidada'] += $c;
        }
        $rows = array_map(fn($k,$v) => [$k, $v], array_keys($map), array_values($map));
        echo json_encode(['success' => true, 'rows' => $rows, 'headers' => ['Métrica', 'Valor'], 'subtitulo' => $subtitulo], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tipo === 'usuarios') {
        $params = [];

        if ($vista === 'atencion') {
            $whereFecha = '';
            if ($fecha_desde !== '') {
                $whereFecha .= " AND DATE(s.solicitud_created_at) >= :fd";
                $params[':fd'] = $fecha_desde;
            }
            if ($fecha_hasta !== '') {
                $whereFecha .= " AND DATE(s.solicitud_created_at) <= :fh";
                $params[':fh'] = $fecha_hasta;
            }
            $whereUsuario = '';
            if ($usuario_id !== '') {
                $whereUsuario = " AND u.user_identificacion = :uid_at";
                $params[':uid_at'] = $usuario_id;
            }
            // Filtro por rol "Oficina de Atención al Ciudadano" (nombre exacto en tabla roles) + GROUP BY compatible con sql_mode=ONLY_FULL_GROUP_BY
            $sql = "
                SELECT
                    CONCAT(u.user_nombres, ' ', u.user_apellidos) AS usuario_nombre,
                    r.rol_nombre AS rol,
                    COALESCE(co.coordinacion_nombre, '-') AS area_nombre,
                    COUNT(s.solicitud_id) AS tramites_procesados,
                    SUM(CASE WHEN COALESCE(s.solicitud_estado, '') = 'completada' THEN 1 ELSE 0 END) AS completados
                FROM usuarios u
                INNER JOIN roles r ON u.rol_id = r.rol_id
                LEFT JOIN coordinacion co ON u.coordinacion_id = co.coordinacion_id
                LEFT JOIN solicitudes s ON s.created_by = u.user_identificacion $whereFecha
                WHERE u.user_estado = 'activo'
                  AND (r.rol_id = 1 OR r.rol_nombre IN ('Atención al Ciudadano', 'Oficina de Atención al Ciudadano'))
                  $whereUsuario
                GROUP BY u.user_identificacion, r.rol_id, co.coordinacion_id
                ORDER BY u.user_nombres ASC, u.user_apellidos ASC
            ";
        } else {
            $whereFecha = '';
            $whereArea = '';
            if ($fecha_desde !== '') {
                $whereFecha .= " AND DATE(s.solicitud_created_at) >= :fd";
                $params[':fd'] = $fecha_desde;
            }
            if ($fecha_hasta !== '') {
                $whereFecha .= " AND DATE(s.solicitud_created_at) <= :fh";
                $params[':fh'] = $fecha_hasta;
            }
            if ($area_id !== '') {
                $whereArea = " AND u.coordinacion_id = :area";
                $params[':area'] = (int)$area_id;
            }
            $sql = "
                SELECT CONCAT(u.user_nombres, ' ', u.user_apellidos) AS usuario_nombre, r.rol_nombre AS rol,
                       co.coordinacion_nombre AS area_nombre,
                       COUNT(s.solicitud_id) AS tramites_procesados,
                       SUM(CASE WHEN COALESCE(s.solicitud_estado,'') = 'completada' THEN 1 ELSE 0 END) AS completados
                FROM usuarios u
                JOIN roles r ON u.rol_id = r.rol_id
                LEFT JOIN coordinacion co ON u.coordinacion_id = co.coordinacion_id
                LEFT JOIN solicitudes s ON s.empleado_asignado_id = u.user_identificacion $whereFecha
                WHERE u.rol_id IN (2, 3) $whereArea
                GROUP BY u.user_identificacion, usuario_nombre, rol, area_nombre
                ORDER BY area_nombre ASC, rol ASC, usuario_nombre ASC
            ";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['tramites_procesados'] = (int)($r['tramites_procesados'] ?? 0);
            $r['completados'] = (int)($r['completados'] ?? 0);
            if (isset($r['rol'])) {
                $r['rol'] = presentarRolNombreReporteUi((string) $r['rol']);
            }
        }
        echo json_encode(['success' => true, 'rows' => $rows, 'headers' => ['Usuario', 'Rol', 'Área', 'Trámites Procesados', 'Completados'], 'subtitulo' => $subtitulo], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tipo === 'auditoria') {
        $where = " WHERE 1=1";
        $params = [];
        if ($fecha_desde !== '') {
            $where .= " AND DATE(a.fecha_creacion) >= :fd";
            $params[':fd'] = $fecha_desde;
        }
        if ($fecha_hasta !== '') {
            $where .= " AND DATE(a.fecha_creacion) <= :fh";
            $params[':fh'] = $fecha_hasta;
        }
        if ($vista === 'atencion') {
            $where .= " AND EXISTS (SELECT 1 FROM roles r_aud WHERE r_aud.rol_id = u.rol_id AND (r_aud.rol_id = 1 OR r_aud.rol_nombre IN ('Atención al Ciudadano', 'Oficina de Atención al Ciudadano')))";
            if ($usuario_id !== '') {
                $where .= " AND u.user_identificacion = :usuario_aud";
                $params[':usuario_aud'] = $usuario_id;
            }
        } elseif ($area_id !== '') {
            $where .= " AND u.coordinacion_id = :area";
            $params[':area'] = (int)$area_id;
        }
        
        $sql = "
            SELECT s.solicitud_numero, a.accion_codigo, a.accion_descripcion, a.detalles,
                   CONCAT(u.user_nombres, ' ', u.user_apellidos) AS empleado_nombre,
                   DATE_FORMAT(a.fecha_creacion, '%d/%m/%Y %h:%i %p') AS fecha_hora
            FROM auditoria a
            JOIN solicitudes s ON a.solicitud_id = s.solicitud_id
            JOIN usuarios u ON a.empleado_id = u.user_identificacion
            $where
            ORDER BY a.fecha_creacion DESC
            LIMIT 2000
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['accion_codigo'] = presentarAccionAuditoriaUi($r['accion_codigo'] ?? '');
            $det = $r['detalles'] ?? null;
            unset($r['detalles']);
            $r['accion_descripcion'] = enriquecerDescripcionAuditoriaCoordinacionLegacy(
                $db,
                presentarDescripcionAuditoriaUi($r['accion_descripcion'] ?? ''),
                $det
            );
        }
        unset($r);
        echo json_encode(['success' => true, 'rows' => $rows, 'headers' => ['Solicitud', 'Acción', 'Descripción', 'Usuario', 'Fecha/Hora'], 'subtitulo' => $subtitulo], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (Exception $e) {
    error_log("director_reporte_data: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
