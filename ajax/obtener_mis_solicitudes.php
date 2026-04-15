<?php
session_start();
require_once '../config/database.php';

// Verificar autenticación (Atención al Ciudadano)
if (!isset($_SESSION['user_id']) || (int) ($_SESSION['rol_id'] ?? 0) !== 1) {
    echo '<tr><td colspan="7" class="text-center py-4 text-red-500">No autorizado</td></tr>';
    exit;
}

$usuario_id = $_SESSION['user_id'];
$adminView = !empty($_SESSION['is_admin_viewing']);
$coordScopeAdmin = (int) ($_SESSION['admin_view_coordinacion_id'] ?? $_SESSION['coordinacion_id'] ?? 0);

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
    
    // Construir consulta con filtros
    $sql = "
        SELECT 
            s.solicitud_id,
            s.solicitud_numero,
            CONCAT(c.ciudadano_nombres, ' ', c.ciudadano_apellidos) as ciudadano_nombre,
            c.ciudadano_identificacion,
            t.tramite_nombre as tramite_nombre,
            co.coordinacion_nombre,
            s.solicitud_estado,
            DATE_FORMAT(s.solicitud_created_at, '%d/%m/%Y %h:%i %p') AS fecha_registro,
            i.institucion_nombre
        FROM solicitudes s
        JOIN ciudadanos c ON s.ciudadano_identificacion = c.ciudadano_identificacion
        JOIN tramite t ON s.tramite_id = t.tramite_id
        JOIN coordinacion co ON t.coordinacion_id = co.coordinacion_id
        LEFT JOIN institucion i ON c.institucion_id = i.institucion_id
        " . ($esFiltroVencida ? trim(cneSqlJoinRecibidoCaracasPorSolicitud($db)) : '') . "
    ";
    if ($adminView) {
        $cid = $coordScopeAdmin;
        if ($cid < 1) {
            $rowOac = $db->query("SELECT coordinacion_id FROM coordinacion WHERE coordinacion_estado = 'activo' AND coordinacion_nombre LIKE '%Atención al Ciudadano%' ORDER BY coordinacion_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $cid = $rowOac ? (int) $rowOac['coordinacion_id'] : 0;
        }
        if ($cid > 0) {
            $sql .= " WHERE t.coordinacion_id = :coord_scope";
            $params = [':coord_scope' => $cid];
        } else {
            $sql .= " WHERE s.created_by = :usuario_id";
            $params = [':usuario_id' => $usuario_id];
        }
    } else {
        $sql .= " WHERE s.created_by = :usuario_id";
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
        echo '<tr><td colspan="7" class="text-center py-4 text-gray-500">No hay solicitudes registradas</td></tr>';
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
        }
        
        // Mostrar las columnas en el orden solicitado:
        // Cédula, Ciudadano, Coordinación, Estado del Trámite, Tipo de Trámite, Número de Seguimiento, Fecha
        echo "<tr>
            <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>
                <span class='font-mono'>{$solicitud['ciudadano_identificacion']}</span>
            </td>
            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>
                {$solicitud['ciudadano_nombre']}
            </td>
            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>
                {$solicitud['coordinacion_nombre']}
            </td>
            <td class='px-6 py-4 whitespace-nowrap'>
                <span class='status-badge {$estado_class}'>{$estado_text}</span>
            </td>
            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>
                {$solicitud['tramite_nombre']}
            </td>
            <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600'>
                <span class='font-mono'>{$solicitud['solicitud_numero']}</span>
            </td>
            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>
                {$solicitud['fecha_registro']}
            </td>
        </tr>";
    }
    
} catch (Exception $e) {
    error_log("Error obteniendo solicitudes: " . $e->getMessage());
    echo '<tr><td colspan="7" class="px-6 py-8 text-center text-red-500">Error al cargar las solicitudes</td></tr>';
}
?>
