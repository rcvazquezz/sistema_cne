<?php
session_start();
require_once '../config/database.php';

/**
 * Vista ampliada solo cuando un administrador entró a esta pantalla vía Pantallas → OAC
 * (sesión: is_admin_viewing + supervisión del rol 1). No confiar en parámetros GET.
 */
$adminOacFullList = !empty($_SESSION['is_admin_viewing'])
    && (int) ($_SESSION['admin_view_target_rol_id'] ?? 0) === 1;
$colspan = $adminOacFullList ? 8 : 7;

// Verificar autenticación (Atención al Ciudadano: rol 1, incluye admin en vista supervisada)
if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 1) {
    echo '<tr><td colspan="' . (int) $colspan . '" class="text-center py-4 text-red-500">No autorizado</td></tr>';
    exit;
}

$usuario_id = $_SESSION['user_id'];

// Obtener filtros
$filtro_cedula = $_GET['cedula'] ?? '';
$filtro_area = $_GET['area'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtroEstNorm = strtolower(trim($filtro_estado));
$esFiltroVencida = in_array($filtroEstNorm, ['vencida', 'vencido'], true);
$filtro_tipo_tramite = $_GET['tipo_tramite'] ?? '';
$filtro_institucion = $_GET['institucion'] ?? '';
$filtro_fecha_desde = $_GET['fecha_desde'] ?? '';
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? '';

try {
    $db = getDB();
    $tieneTramiteIdInicial = cneSolicitudesTieneTramiteIdInicial($db);
    $tidEtqSql = cneSqlExpresionTramiteIdEtiqueta($tieneTramiteIdInicial);

    $stRol = $db->prepare('SELECT rol_id FROM roles WHERE rol_nombre = ? LIMIT 1');
    $stRol->execute(['Atención al Ciudadano']);
    $rolOacId = (int) $stRol->fetchColumn();
    if ($rolOacId < 1) {
        $rolOacId = 1;
    }

    $selCreador = '';
    $joinCreador = '';
    if ($adminOacFullList) {
        $selCreador = ",
            TRIM(CONCAT(COALESCE(u_creator.user_nombres, ''), ' ', COALESCE(u_creator.user_apellidos, ''))) AS creador_nombre_completo,
            u_creator.user_username AS creador_username
        ";
        $joinCreador = ' INNER JOIN usuarios u_creator ON s.created_by = u_creator.user_identificacion ';
    }

    // Construir consulta con filtros (nombre estable: catálogo de alta, no el remapeado al redirigir)
    $sql = "
        SELECT 
            s.solicitud_id,
            s.solicitud_numero,
            CONCAT(c.ciudadano_nombres, ' ', c.ciudadano_apellidos) as ciudadano_nombre,
            c.ciudadano_identificacion,
            CASE 
                WHEN t_etq.tramite_padre_id IS NOT NULL AND tp_etq.tramite_id IS NOT NULL 
                    THEN CONCAT(tp_etq.tramite_nombre, ' — ', t_etq.tramite_nombre)
                WHEN t_etq.tramite_id IS NOT NULL THEN t_etq.tramite_nombre
                WHEN t.tramite_padre_id IS NOT NULL AND tp_op.tramite_id IS NOT NULL 
                    THEN CONCAT(tp_op.tramite_nombre, ' — ', t.tramite_nombre)
                ELSE t.tramite_nombre
            END AS tramite_nombre,
            co.coordinacion_nombre,
            t_etq.coordinacion_id AS coordinacion_origen,
            s.solicitud_estado,
            DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro,
            i.institucion_nombre
            $selCreador
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        $joinCreador
        " . trim(cneSqlJoinAuditoriaTramiteIdCreacion()) . "
        JOIN tramite t ON s.tramite_id = t.tramite_id
        LEFT JOIN tramite t_etq ON t_etq.tramite_id = $tidEtqSql
        LEFT JOIN tramite tp_etq ON tp_etq.tramite_id = t_etq.tramite_padre_id
        LEFT JOIN tramite tp_op ON tp_op.tramite_id = t.tramite_padre_id
        JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
        LEFT JOIN institucion i ON c.institucion_id = i.institucion_id
        " . ($esFiltroVencida ? trim(cneSqlJoinRecibidoCaracasPorSolicitud($db)) : '') . "
    ";

    if ($adminOacFullList) {
        $sql .= ' WHERE u_creator.rol_id = :rol_oac ';
        $params = [':rol_oac' => $rolOacId];
    } else {
        $sql .= ' WHERE s.created_by = :usuario_id ';
        $params = [':usuario_id' => $usuario_id];
    }
    
    if ($filtro_cedula) {
        $sql .= " AND c.ciudadano_identificacion LIKE :cedula";
        $params[':cedula'] = '%' . $filtro_cedula . '%';
    }
    
    if ($filtro_area) {
        $sql .= " AND t.coordinacion_id = :area_id";
        $params[':area_id'] = $filtro_area;
    }
    
    if ($filtro_estado) {
        if ($esFiltroVencida) {
            $sql .= ' AND ' . cneSqlCondicionVencidaEfectiva();
        } else {
            $sql .= " AND s.solicitud_estado = :estado";
            $params[':estado'] = $filtro_estado;
        }
    }
    
    $filtro_subtramite = $_GET['subtramite'] ?? '';
    if ($filtro_subtramite) {
        $sql .= " AND t.tramite_id = :subtramite_id";
        $params[':subtramite_id'] = $filtro_subtramite;
    } elseif ($filtro_tipo_tramite) {
        $sql .= " AND (t.tramite_id = :tipo_tramite_id OR t.tramite_padre_id = :tipo_tramite_id)";
        $params[':tipo_tramite_id'] = $filtro_tipo_tramite;
    }
    
    if ($filtro_institucion) {
        $sql .= " AND c.institucion_id = :institucion_id";
        $params[':institucion_id'] = $filtro_institucion;
    }
    
    if ($filtro_fecha_desde) {
        $sql .= " AND DATE(s.solicitud_created_at) >= :fecha_desde";
        $params[':fecha_desde'] = $filtro_fecha_desde;
    }
    
    if ($filtro_fecha_hasta) {
        $sql .= " AND DATE(s.solicitud_created_at) <= :fecha_hasta";
        $params[':fecha_hasta'] = $filtro_fecha_hasta;
    }
    
    $sql .= " ORDER BY s.solicitud_created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $solicitudes = $stmt->fetchAll();
    
    if (empty($solicitudes)) {
        echo '<tr><td colspan="' . (int) $colspan . '" class="text-center py-4 text-gray-500">No hay solicitudes registradas</td></tr>';
        exit;
    }

    foreach ($solicitudes as $solicitud) {
        // Determinar clase CSS para el estado
        $estado_class = 'status-pendiente';
        $estado_text = 'Pendiente';
        
        switch ($solicitud['solicitud_estado']) {
            case 'en_revision':
                $estado_class = 'status-proceso';
                $estado_text = 'En Proceso';
                break;
            case 'aprobada':
                $estado_class = 'status-proceso';
                $estado_text = 'En Proceso';
                break;
            case 'completada':
                $estado_class = 'status-completado';
                $estado_text = 'Completada';
                break;
            case 'rechazada':
                $estado_class = 'status-vencido';
                $estado_text = 'VENCIDO';
                break;
            case 'vencida':
                $estado_class = 'status-vencido';
                $estado_text = 'VENCIDO';
                break;
            case 'redirigida':
                $estado_class = 'status-redirigido';
                $estado_text = 'Redirigida';
                break;
            case 'invalidada':
                $estado_class = 'status-invalidada';
                $estado_text = 'Invalidada';
                break;
        }

        $hCed = htmlspecialchars((string) ($solicitud['ciudadano_identificacion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hNom = htmlspecialchars((string) ($solicitud['ciudadano_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hCoord = htmlspecialchars((string) ($solicitud['coordinacion_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hTram = htmlspecialchars((string) ($solicitud['tramite_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hSeg = htmlspecialchars((string) ($solicitud['solicitud_numero'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hFecha = htmlspecialchars((string) ($solicitud['fecha_registro'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hEst = htmlspecialchars($estado_text, ENT_QUOTES, 'UTF-8');

        $tdCreador = '';
        if ($adminOacFullList) {
            $nomC = trim((string) ($solicitud['creador_nombre_completo'] ?? ''));
            if ($nomC === '') {
                $nomC = (string) ($solicitud['creador_username'] ?? '');
            }
            $hCread = htmlspecialchars($nomC, ENT_QUOTES, 'UTF-8');
            $tdCreador = "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-700'>{$hCread}</td>";
        }

        // Cédula, Ciudadano, [Funcionario creador si admin], Coordinación, Estado, Tipo, N°, Fecha
        echo '<tr>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                <span class="font-mono">' . $hCed . '</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . $hNom . '</td>'
            . $tdCreador . '
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . $hCoord . '</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="status-badge ' . htmlspecialchars($estado_class, ENT_QUOTES, 'UTF-8') . '">' . $hEst . '</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . $hTram . '</td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                <span class="font-mono">' . $hSeg . '</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">' . $hFecha . '</td>
        </tr>';
    }
    
} catch (Exception $e) {
    error_log("Error obteniendo solicitudes: " . $e->getMessage());
    echo '<tr><td colspan="' . (int) $colspan . '" class="px-6 py-8 text-center text-red-500">Error al cargar las solicitudes</td></tr>';
}
?>
