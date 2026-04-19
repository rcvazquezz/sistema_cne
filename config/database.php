<?php
// config/database.php - MySQL para Laragon

require_once __DIR__ . '/../includes/realtime_push.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $host = 'localhost';
            $dbname = 'cne_sistema';
            $user = 'root';
            $password = '';
            
            $this->pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password, [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
        } catch (PDOException $e) {
            die("<div style='background:#f8d7da;color:#721c24;padding:20px;margin:20px;border-radius:5px;'>
                <h3>❌ Error de conexión MySQL</h3>
                <p><strong>Mensaje:</strong> {$e->getMessage()}</p>
                <p><strong>Solución:</strong> Abre HeidiSQL y crea la base de datos 'cne_sistema'</p>
            </div>");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function getError() {
        return $this->pdo->errorInfo();
    }
}

function getDB() {
    return Database::getInstance()->getConnection();
}

/**
 * Marca actividad en sesiones_activas (última actividad = ahora).
 * Llamar tras validar sesión en cada carga de dashboard o desde ping AJAX.
 */
function asegurarColumnaUserUltimaConexion(PDO $db) {
    static $hecho = false;
    if ($hecho) {
        return;
    }
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'user_ultima_conexion'");
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN user_ultima_conexion DATETIME NULL DEFAULT NULL AFTER user_estado");
        }
    } catch (Exception $e) {
        error_log('asegurarColumnaUserUltimaConexion: ' . $e->getMessage());
    }
    $hecho = true;
}

/**
 * Columna ultima_actividad en usuarios (KPI "Conectados" y monitores en ventana corta).
 */
function asegurarColumnaUltimaActividadUsuarios(PDO $db) {
    static $hecho = false;
    if ($hecho) {
        return;
    }
    try {
        asegurarColumnaUserUltimaConexion($db);
        $stmt = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'ultima_actividad'");
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN ultima_actividad DATETIME NULL DEFAULT NULL AFTER user_ultima_conexion");
            $db->exec('UPDATE usuarios SET ultima_actividad = user_ultima_conexion WHERE ultima_actividad IS NULL AND user_ultima_conexion IS NOT NULL');
        }
    } catch (Exception $e) {
        error_log('asegurarColumnaUltimaActividadUsuarios: ' . $e->getMessage());
    }
    $hecho = true;
}

/**
 * Persiste la última interacción en usuarios (visible aunque no haya fila en sesiones_activas).
 */
function actualizarUsuarioUltimaConexion($usuario_id) {
    $uid = trim((string)($usuario_id ?? ''));
    if ($uid === '') {
        return;
    }
    try {
        $db = getDB();
        asegurarColumnaUserUltimaConexion($db);
        asegurarColumnaUltimaActividadUsuarios($db);
        $stmt = $db->prepare('UPDATE usuarios SET user_ultima_conexion = NOW(), ultima_actividad = NOW() WHERE user_identificacion = :uid');
        $stmt->execute([':uid' => $uid]);
    } catch (Exception $e) {
        error_log('actualizarUsuarioUltimaConexion: ' . $e->getMessage());
    }
}

/**
 * Marca actividad en sesiones_activas y en usuarios.user_ultima_conexion.
 */
function actualizarSesionUltimaActividad($usuario_id) {
    $uid = trim((string)($usuario_id ?? ''));
    if ($uid === '') {
        return;
    }
    try {
        $db = getDB();
        $token = bin2hex(random_bytes(16));
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $db->prepare("
            INSERT INTO sesiones_activas (usuario_id, sesion_token, sesion_ip_address, sesion_user_agent, sesion_ultima_actividad)
            VALUES (:usuario_id, :token, :ip, :agent, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                sesion_token = VALUES(sesion_token),
                sesion_ip_address = VALUES(sesion_ip_address),
                sesion_user_agent = VALUES(sesion_user_agent),
                sesion_ultima_actividad = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':usuario_id' => $uid,
            ':token' => $token,
            ':ip' => $ip,
            ':agent' => $agent,
        ]);
        actualizarUsuarioUltimaConexion($uid);
    } catch (Exception $e) {
        error_log('actualizarSesionUltimaActividad: ' . $e->getMessage());
    }
}

/** Umbral (minutos) para mostrar "Activo" en monitores de conexión (tiempo real). */
function minutosUmbralActivoMonitor() {
    return 5;
}

/**
 * Minutos de inactividad desde configuracion_sistema (clave tiempo_inactividad, JSON con "minutos").
 * Usado para limpiar sesiones "fantasma" (cierre de navegador sin logout).
 */
function obtenerMinutosTiempoInactividadConfig() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $db = getDB();
        $stmt = $db->query("SELECT configuracion_valor FROM configuracion_sistema WHERE configuracion_clave = 'tiempo_inactividad' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !array_key_exists('configuracion_valor', $row)) {
            $cache = 30;
            return $cache;
        }
        $v = $row['configuracion_valor'];
        if (is_string($v)) {
            $j = json_decode($v, true);
            if ($j !== null && is_string($j)) {
                $j = json_decode($j, true);
            }
        } elseif (is_array($v)) {
            $j = $v;
        } else {
            $j = null;
        }
        $m = (is_array($j) && isset($j['minutos'])) ? (int)$j['minutos'] : 30;
        if ($m < 1) {
            $m = 30;
        }
        $cache = $m;
        return $cache;
    } catch (Exception $e) {
        error_log('obtenerMinutosTiempoInactividadConfig: ' . $e->getMessage());
        $cache = 30;
        return $cache;
    }
}

/** Elimina filas de sesiones_activas sin actividad reciente (según configuración). */
function limpiarSesionesExpiradas() {
    try {
        $min = (int)obtenerMinutosTiempoInactividadConfig();
        $db = getDB();
        $db->exec('DELETE FROM sesiones_activas WHERE sesion_ultima_actividad < DATE_SUB(NOW(), INTERVAL ' . $min . ' MINUTE)');
    } catch (Exception $e) {
        error_log('limpiarSesionesExpiradas: ' . $e->getMessage());
    }
}

/** Quita la sesión activa del usuario (logout). */
function eliminarSesionActivaUsuario($usuario_id) {
    $uid = trim((string)($usuario_id ?? ''));
    if ($uid === '') {
        return;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM sesiones_activas WHERE usuario_id = :uid');
        $stmt->execute([':uid' => $uid]);
    } catch (Exception $e) {
        error_log('eliminarSesionActivaUsuario: ' . $e->getMessage());
    }
}

// Nueva función para verificar permisos basada en la nueva estructura
function tienePermiso($usuario_id, $permiso_codigo) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) as tiene_permiso
            FROM rol_permisos rp
            JOIN permisos p ON rp.permiso_id = p.permiso_id
            JOIN usuarios u ON rp.rol_id = u.rol_id
            WHERE u.user_identificacion = :usuario_id 
            AND p.permiso_codigo = :permiso_codigo
        ");
        $stmt->execute([
            ':usuario_id' => $usuario_id, 
            ':permiso_codigo' => $permiso_codigo
        ]);
        $result = $stmt->fetch();
        return $result['tiene_permiso'] > 0;
    } catch (Exception $e) {
        error_log("Error verificando permisos: " . $e->getMessage());
        return false;
    }
}

// Función para obtener información del usuario actual
function obtenerUsuario($usuario_id) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT 
                u.*, 
                r.rol_nombre,
                c.coordinacion_nombre
            FROM usuarios u
            LEFT JOIN roles r ON u.rol_id = r.rol_id
            LEFT JOIN coordinacion c ON u.coordinacion_id = c.coordinacion_id
            WHERE u.user_identificacion = :usuario_id 
            AND u.user_estado = 'activo'
        ");
        $stmt->execute([':usuario_id' => $usuario_id]);
        return $stmt->fetch() ?: [];
    } catch (Exception $e) {
        error_log("Error obteniendo usuario: " . $e->getMessage());
        return [];
    }
}

/**
 * Código de acción en auditoría: MAYÚSCULAS y sin tildes (estándar reportes / PDF).
 */
function normalizarAccionAuditoria($codigo) {
    if ($codigo === null) {
        return '';
    }
    $s = trim((string) $codigo);
    if ($s === '') {
        return '';
    }
    $s = mb_strtoupper($s, 'UTF-8');
    static $sinTildes = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
    ];
    return strtr($s, $sinTildes);
}

/**
 * Acción para mostrar en UI: misma normalización + guiones bajos como espacios (ej. SOLICITUD CREADA).
 * No altera lo almacenado en BD; usar solo al imprimir en HTML/PDF/export.
 * Códigos legados EMPLEADO_* se muestran como FUNCIONARIO (terminología institucional CNE).
 */
function presentarAccionAuditoriaUi($codigo) {
    $n = normalizarAccionAuditoria($codigo);
    $ui = str_replace('_', ' ', $n);
    if (preg_match('/^EMPLEADO\s+/u', $ui)) {
        return (string) preg_replace('/^EMPLEADO\s+/u', 'FUNCIONARIO ', $ui);
    }

    return $ui;
}

/**
 * Nombre de coordinación en UI: guiones bajos como espacios (misma regla que acciones de auditoría).
 */
function presentarNombreCoordinacionUi(?string $nombre): string {
    if ($nombre === null || $nombre === '') {
        return '';
    }

    $s = str_replace('_', ' ', trim($nombre));
    if ($s !== '' && strpos((string) $nombre, '_') !== false && function_exists('mb_convert_case')) {
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    return $s;
}

/**
 * Nombre legible de una coordinación para textos de auditoría (consulta por id).
 */
function coordinacionNombreParaAuditoria(PDO $db, int $coordinacion_id): string
{
    if ($coordinacion_id < 1) {
        return 'coordinación no especificada';
    }
    $st = $db->prepare('SELECT coordinacion_nombre FROM coordinacion WHERE coordinacion_id = ? LIMIT 1');
    $st->execute([$coordinacion_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'coordinación (ID ' . $coordinacion_id . ')';
    }
    $n = presentarNombreCoordinacionUi($row['coordinacion_nombre'] ?? '');

    return $n !== '' ? $n : 'coordinación (ID ' . $coordinacion_id . ')';
}

/**
 * Mejora textos antiguos que citaban solo el id de coordinación (p. ej. "coordinación destino id 2").
 */
function enriquecerDescripcionAuditoriaCoordinacionLegacy(PDO $db, string $desc, ?string $detallesJson): string
{
    if ($desc === '') {
        return $desc;
    }
    if (!preg_match('/coordinaci[oó]n destino/u', $desc)) {
        return $desc;
    }
    $id = 0;
    if ($detallesJson !== null && $detallesJson !== '') {
        $d = json_decode($detallesJson, true);
        if (is_array($d) && !empty($d['id_coordinacion_destino'])) {
            $id = (int) $d['id_coordinacion_destino'];
        }
    }
    if ($id < 1 && preg_match('/coordinaci[oó]n destino id\s*(\d+)/iu', $desc, $m)) {
        $id = (int) $m[1];
    }
    if ($id < 1 && preg_match('/\(coordinaci[oó]n destino\s*(\d+)\)/iu', $desc, $m)) {
        $id = (int) $m[1];
    }
    if ($id < 1 && preg_match('/coordinaci[oó]n destino\s+(\d+)/iu', $desc, $m)) {
        $id = (int) $m[1];
    }
    if ($id < 1) {
        return $desc;
    }
    $nombre = coordinacionNombreParaAuditoria($db, $id);
    $desc = preg_replace('/\s*\(coordinaci[oó]n destino id\s*\d+\)/iu', ' en la coordinación de ' . $nombre, $desc);
    $desc = preg_replace('/\(coordinaci[oó]n destino\s*\d+\)/iu', ' en la coordinación de ' . $nombre, $desc);
    $desc = preg_replace('/coordinaci[oó]n destino\s+(\d+)/iu', 'coordinación de ' . $nombre, $desc);

    return $desc;
}

/** Mensaje al rechazar redirección hacia la oficina de entrada */
function mensajeRedireccionAtencionCiudadanoProhibida(): string {
    return 'No está permitido redirigir trámites a la Oficina de Atención al Ciudadano. Elija una coordinación técnica (COPAFI, Registro Civil, Secretaría, etc.).';
}

/**
 * Tras una redirección, el trámite de la solicitud debe apuntar a la coordinación destino
 * para que métricas y listados (COALESCE(coordinacion_actual_id, tramite.coordinacion_id)) cuenten bien.
 *
 * @return int|null tramite_id en la coordinación destino
 */
function cneResolverTramiteDestinoCoordinacion(PDO $db, int $solicitud_id, int $destCoordId): ?int
{
    if ($solicitud_id < 1 || $destCoordId < 1) {
        return null;
    }
    $st = $db->prepare('
        SELECT t.tramite_nombre
        FROM solicitudes s
        JOIN tramite t ON s.tramite_id = t.tramite_id
        WHERE s.solicitud_id = :sid
    ');
    $st->execute([':sid' => $solicitud_id]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) {
        return null;
    }
    $nombre = $cur['tramite_nombre'];
    $st2 = $db->prepare('
        SELECT tramite_id FROM tramite
        WHERE coordinacion_id = :cid AND tramite_nombre = :nom
        ORDER BY (tramite_padre_id IS NULL) DESC
        LIMIT 1
    ');
    $st2->execute([':cid' => $destCoordId, ':nom' => $nombre]);
    $r = $st2->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        return (int) $r['tramite_id'];
    }
    $st3 = $db->prepare('
        SELECT tramite_id FROM tramite
        WHERE coordinacion_id = :cid AND tramite_padre_id IS NULL
        ORDER BY tramite_nombre ASC
        LIMIT 1
    ');
    $st3->execute([':cid' => $destCoordId]);
    $r3 = $st3->fetch(PDO::FETCH_ASSOC);

    return $r3 ? (int) $r3['tramite_id'] : null;
}

/**
 * Fragmento SQL (solo condiciones AND) para excluir la Oficina de Atención como destino de redirección.
 */
function sqlCoordinacionesRedireccionPermitidas(): string {
    return "coordinacion_nombre NOT LIKE '%Oficina de Atención%' AND coordinacion_nombre NOT LIKE '%Atención al Ciudadano%'";
}

function coordinacionNombreEsOficinaAtencionAlCiudadano(string $nombre): bool {
    $n = mb_strtolower($nombre, 'UTF-8');

    return str_contains($n, 'oficina de atención') || str_contains($n, 'atención al ciudadano');
}

function coordinacionEsOficinaAtencionAlCiudadano(PDO $db, int $coordinacion_id): bool {
    if ($coordinacion_id < 1) {
        return false;
    }
    $st = $db->prepare('SELECT coordinacion_nombre FROM coordinacion WHERE coordinacion_id = ? LIMIT 1');
    $st->execute([$coordinacion_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    return coordinacionNombreEsOficinaAtencionAlCiudadano((string) ($row['coordinacion_nombre'] ?? ''));
}

/**
 * Descripción legible: conserva acentos; repara texto mal codificado cuando es posible.
 */
function normalizarDescripcionAuditoria($texto) {
    if ($texto === null) {
        return null;
    }
    $s = (string) $texto;
    if ($s === '') {
        return null;
    }
    if (!mb_check_encoding($s, 'UTF-8')) {
        $conv = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        if ($conv !== false && $conv !== '') {
            $s = $conv;
        }
    }
    return $s;
}

/**
 * Descripción para UI/PDF: repara mojibake y unifica textos de creación desde oficina de entrada
 * (evita "usuario de Oficina de Oficina..." por reemplazos encadenados sobre "Atención al Ciudadano").
 */
function presentarDescripcionAuditoriaUi($texto) {
    $s = normalizarDescripcionAuditoria($texto);
    if ($s === null || $s === '') {
        return '';
    }
    $s = str_replace(
        ['Tr?mite', 'Coordinaci?n', 'Atenci?n'],
        ['Trámite', 'Coordinación', 'Atención'],
        $s
    );
    $canonCreada = 'Solicitud creada por la Oficina de Atención al Ciudadano';
    $s = preg_replace(
        '/Solicitud creada por\s+el\s+usuario\s+de\s+Oficina\s+de\s+Oficina\s+de\s+Atenci[oó]n\s+al\s+Ciudadano\.?/ui',
        $canonCreada,
        $s
    );
    $s = preg_replace(
        '/Solicitud creada por\s+el\s+usuario\s+de\s+Oficina\s+de\s+Atenci[oó]n\s+al\s+Ciudadano\.?/ui',
        $canonCreada,
        $s
    );
    $s = preg_replace(
        '/Solicitud creada por\s+el\s+usuario\s+de\s+entrada\.?/ui',
        $canonCreada,
        $s
    );
    $canonInm = 'Trámite completado inmediatamente por la Oficina de Atención al Ciudadano';
    $s = preg_replace(
        '/Tr[aá]mite\s+completado\s+inmediatamente\s+por\s+el\s+usuario\s+de\s+Oficina\s+de\s+Oficina\s+de\s+Atenci[oó]n\s+al\s+Ciudadano\.?/ui',
        $canonInm,
        $s
    );
    $s = preg_replace(
        '/Tr[aá]mite\s+completado\s+inmediatamente\s+por\s+el\s+usuario\s+de\s+Oficina\s+de\s+Atenci[oó]n\s+al\s+Ciudadano\.?/ui',
        $canonInm,
        $s
    );
    $s = preg_replace(
        '/Tr[aá]mite\s+completado\s+inmediatamente\s+por\s+el\s+usuario\s+de\s+entrada\.?/ui',
        $canonInm,
        $s
    );
    // Rol de gestión en coordinación: "empleado" → "funcionario" en textos de auditoría
    $s = str_replace(
        [
            'El empleado completa el trámite',
            'el empleado completa el trámite',
            'Trámite iniciado por el empleado',
            'trámite iniciado por el empleado',
            'Requisitos actualizados por el empleado',
            'requisitos actualizados por el empleado',
        ],
        [
            'El funcionario completa el trámite',
            'el funcionario completa el trámite',
            'Trámite iniciado por el funcionario',
            'trámite iniciado por el funcionario',
            'Requisitos actualizados por el funcionario',
            'requisitos actualizados por el funcionario',
        ],
        $s
    );
    $s = preg_replace('/\bPor el empleado\b/u', 'Por el funcionario', $s);
    $s = preg_replace('/\bpor el empleado\b/u', 'por el funcionario', $s);
    $s = preg_replace('/\bEl empleado\b/u', 'El funcionario', $s);
    $s = preg_replace('/\bEl Empleado\b/u', 'El funcionario', $s);

    return $s;
}

/**
 * Nombre de rol en reportes UI: alinear con terminología institucional (tabla roles puede decir "Empleado").
 */
function presentarRolNombreReporteUi(?string $rolNombre): string {
    if ($rolNombre === null || $rolNombre === '') {
        return '';
    }
    $t = trim($rolNombre);
    if (strcasecmp($t, 'Empleado') === 0) {
        return 'Funcionario';
    }

    return $rolNombre;
}

/**
 * Clave interna para mapear estados de solicitud en reportes (minúsculas, sin tildes, guiones bajos).
 */
function normalizarClaveEstadoReporteSolicitud($estado) {
    $e = mb_strtolower(trim((string) $estado), 'UTF-8');
    if ($e === '') {
        return '';
    }
    static $tildes = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    ];
    $e = strtr($e, $tildes);
    $e = preg_replace('/\s+/', '_', $e);
    $e = str_replace('-', '_', $e);
    return $e;
}

/**
 * Etiqueta oficial para columna Estado en reporte de solicitudes (director y exportaciones).
 */
function presentarEstadoSolicitudReporteDirector($estado) {
    $raw = trim((string) ($estado ?? ''));
    if ($raw === '') {
        return '—';
    }
    $k = normalizarClaveEstadoReporteSolicitud($raw);
    static $map = [
        'pendiente' => 'Pendiente',
        'en_revision' => 'En Proceso',
        'en_proceso' => 'En Proceso',
        'aprobada' => 'En Proceso',
        'completada' => 'Completado',
        'completado' => 'Completado',
        'finalizado' => 'Completado',
        'finalizada' => 'Completado',
        'vencida' => 'Vencido',
        'vencido' => 'Vencido',
        'rechazada' => 'Vencido',
        'redirigida' => 'Redirigido',
        'redirigido' => 'Redirigido',
        'invalidada' => 'Invalidada',
    ];
    if (isset($map[$k])) {
        return $map[$k];
    }
    $human = str_replace('_', ' ', $k);
    if ($human !== '') {
        return mb_convert_case($human, MB_CASE_TITLE, 'UTF-8');
    }
    return $raw;
}

/**
 * Fragmento SQL para IN (...): redirección de trámite (código normalizado + variantes legadas en BD).
 */
function auditoriaSqlInCodigosRedireccion() {
    return "('REDIRECCION','EMPLEADO_REDIRIGE_TRAMITE','FUNCIONARIO_REDIRIGE_TRAMITE','Redirección','Redirecci?n')";
}

// Función para registrar en auditoría
function registrarAuditoria($empleado_id, $solicitud_id, $accion_codigo, $accion_descripcion = null, $detalles = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO auditoria 
            (empleado_id, solicitud_id, accion_codigo, accion_descripcion, detalles) 
            VALUES (:empleado_id, :solicitud_id, :accion_codigo, :accion_descripcion, :detalles)
        ");
        
        $accion_codigo = normalizarAccionAuditoria($accion_codigo);
        $accion_descripcion = normalizarDescripcionAuditoria($accion_descripcion);
        $detalles_json = $detalles ? json_encode($detalles, JSON_UNESCAPED_UNICODE) : null;
        
        $ok = $stmt->execute([
            ':empleado_id' => $empleado_id,
            ':solicitud_id' => $solicitud_id,
            ':accion_codigo' => $accion_codigo,
            ':accion_descripcion' => $accion_descripcion,
            ':detalles' => $detalles_json
        ]);
        if ($ok) {
            pushRealtimeEnvelope([
                'targets' => ['broadcast_all' => true],
                'event' => [
                    'type' => 'auditoria',
                    'solicitud_id' => (int) $solicitud_id,
                    'accion_codigo' => $accion_codigo,
                    'accion_label' => presentarAccionAuditoriaUi($accion_codigo),
                ],
            ]);
        }
        return $ok;
    } catch (Exception $e) {
        error_log("Error registrando auditoría: " . $e->getMessage());
        return false;
    }
}

function obtenerSubtramites($tramite_padre_id) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT 
                tramite_id as id,
                tramite_nombre as nombre
            FROM tramite
            WHERE tramite_padre_id = :padre_id
            ORDER BY tramite_nombre
        ");
        $stmt->execute([':padre_id' => $tramite_padre_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error obteniendo subtrámites: " . $e->getMessage());
        return [];
    }
}

/** Días tras RECIBIDO_CARACAS; misma regla que dashboard_empleado (rowHistorial). */
function cneEmpleadoDiasPlazoVencimientoTramite(): int {
    return 60;
}

function cneAuditoriaColumnaFecha(PDO $db): string {
    static $col = null;
    if ($col !== null) {
        return $col;
    }
    try {
        $chk = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'fecha_creacion'");
        $col = ((bool) $chk->fetchColumn()) ? 'fecha_creacion' : 'auditoria_created_at';
    } catch (Exception $e) {
        $col = 'auditoria_created_at';
    }

    return $col;
}

/** @return int|null Unix timestamp del último RECIBIDO_CARACAS */
function cneEmpleadoUltimoRecibidoCaracasTs(PDO $db, int $solicitud_id): ?int {
    $c = cneAuditoriaColumnaFecha($db);
    if (!in_array($c, ['fecha_creacion', 'auditoria_created_at'], true)) {
        $c = 'auditoria_created_at';
    }
    $stmt = $db->prepare("SELECT MAX(a.`{$c}`) AS mf FROM auditoria a WHERE a.solicitud_id = :sid AND a.accion_codigo = 'RECIBIDO_CARACAS'");
    $stmt->execute([':sid' => $solicitud_id]);
    $row = $stmt->fetch();
    $mf = $row['mf'] ?? null;
    if ($mf === null || $mf === '') {
        return null;
    }
    $t = strtotime((string) $mf);

    return $t !== false ? $t : null;
}

/**
 * Mensaje de error si el empleado no debe mutar la solicitud (completada, vencida en BD o vencida por plazo post-Caracas).
 */
function cneEmpleadoMensajeSiSolicitudNoGestionable(PDO $db, int $solicitud_id): ?string {
    $stmt = $db->prepare('SELECT solicitud_estado FROM solicitudes WHERE solicitud_id = :id');
    $stmt->execute([':id' => $solicitud_id]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'Trámite no encontrado';
    }
    $est = strtolower((string) ($row['solicitud_estado'] ?? ''));
    if ($est === 'completada' || $est === 'vencida' || $est === 'invalidada') {
        return 'Este trámite está finalizado y no puede gestionarse';
    }
    $ts = cneEmpleadoUltimoRecibidoCaracasTs($db, $solicitud_id);
    if ($ts !== null) {
        $deadline = $ts + cneEmpleadoDiasPlazoVencimientoTramite() * 86400;
        if (time() > $deadline) {
            return 'Este trámite está vencido por plazo y no puede gestionarse';
        }
    }

    return null;
}

/**
 * Estado explícito para JSON / Power BI: siempre 'vencida' si aplica plazo o BD (nunca se fusiona con 'completada').
 * $estadoEfectivo: valor ya calculado (p. ej. redirigida por coordinación).
 * $estadoTabla: columna solicitudes.solicitud_estado.
 */
function cneEstadoParaReporteFila(string $estadoEfectivo, string $estadoTabla, ?string $recibidoFecha): string {
    $eff = strtolower(trim($estadoEfectivo));
    $tab = strtolower(trim($estadoTabla));
    if ($eff === 'redirigida') {
        return 'redirigida';
    }
    if ($tab === 'invalidada' || $eff === 'invalidada') {
        return 'invalidada';
    }
    if ($tab === 'vencida' || $eff === 'vencida') {
        return 'vencida';
    }
    if ($tab === 'completada' || $eff === 'completada') {
        return 'completada';
    }
    if ($recibidoFecha !== null && $recibidoFecha !== '' && !in_array($tab, ['completada', 'vencida'], true)) {
        $norm = preg_replace('/(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', '$1T$2', trim((string) $recibidoFecha));
        $ts = strtotime($norm);
        if ($ts !== false && time() > $ts + cneEmpleadoDiasPlazoVencimientoTramite() * 86400) {
            return 'vencida';
        }
    }

    return $eff !== '' ? $eff : $tab;
}

/**
 * JOIN a la fecha del último RECIBIDO_CARACAS por solicitud (alias cne_rec_car).
 * Requiere alias de tabla solicitudes = s.
 */
function cneSqlJoinRecibidoCaracasPorSolicitud(PDO $db): string {
    $c = cneAuditoriaColumnaFecha($db);
    if (!in_array($c, ['fecha_creacion', 'auditoria_created_at'], true)) {
        $c = 'auditoria_created_at';
    }

    return "
        LEFT JOIN (
            SELECT a.solicitud_id, MAX(a.`{$c}`) AS cne_recibido_caracas_dt
            FROM auditoria a
            WHERE a.accion_codigo = 'RECIBIDO_CARACAS'
            GROUP BY a.solicitud_id
        ) cne_rec_car ON cne_rec_car.solicitud_id = s.solicitud_id
    ";
}

/**
 * Expresión CASE SQL: vencida y completada siempre separadas; vencimiento por plazo post-Caracas => 'vencida'.
 * Requiere cneSqlJoinRecibidoCaracasPorSolicitud. $condRedirigida: expresión SQL booleana.
 */
function cneSqlCaseEstadoParaReporte(string $condRedirigida): string {
    $d = (int) cneEmpleadoDiasPlazoVencimientoTramite();

    return "CASE
        WHEN ($condRedirigida) THEN 'redirigida'
        WHEN s.solicitud_estado = 'invalidada' THEN 'invalidada'
        WHEN s.solicitud_estado = 'vencida' THEN 'vencida'
        WHEN s.solicitud_estado = 'completada' THEN 'completada'
        WHEN cne_rec_car.cne_recibido_caracas_dt IS NOT NULL
            AND TIMESTAMPDIFF(DAY, cne_rec_car.cne_recibido_caracas_dt, NOW()) > {$d}
            AND s.solicitud_estado NOT IN ('completada','vencida','rechazada','invalidada') THEN 'vencida'
        ELSE s.solicitud_estado
    END";
}

/**
 * Condición SQL (AND ...): trámite vencido en BD o por plazo tras RECIBIDO_CARACAS (misma regla que cneSqlCaseEstadoParaReporte).
 * Requiere alias s (solicitudes) y JOIN cneSqlJoinRecibidoCaracasPorSolicitud (alias cne_rec_car).
 */
function cneSqlCondicionVencidaEfectiva(): string {
    $d = (int) cneEmpleadoDiasPlazoVencimientoTramite();

    return "(s.solicitud_estado IN ('vencida','rechazada')
        OR (
            cne_rec_car.cne_recibido_caracas_dt IS NOT NULL
            AND TIMESTAMPDIFF(DAY, cne_rec_car.cne_recibido_caracas_dt, NOW()) > {$d}
            AND s.solicitud_estado NOT IN ('completada','vencida','rechazada','invalidada')
        ))";
}

/** Vacío, NULL o literal N/A (insensible a mayúsculas) — marcador de dato pendiente en ciudadanos. */
function cneCiudadanoCampoEsNA(?string $v): bool
{
    if ($v === null) {
        return true;
    }
    $t = trim((string) $v);

    return $t === '' || strcasecmp($t, 'N/A') === 0;
}

function cneCiudadanoNormalizarTextoCmp(?string $s): string
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

    return preg_replace('/\s+/u', ' ', $t);
}

/** Teléfono a persistir: N/A si no hay número de abonado. */
function cneCiudadanoTelefonoParaGuardar(?string $telCod, ?string $telNum): string
{
    $c = trim((string) ($telCod ?? ''));
    $n = trim((string) ($telNum ?? ''));
    if ($n === '') {
        return 'N/A';
    }

    return $c . '-' . $n;
}

function cneCiudadanoFechaYmdDesdeRaw($raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $s = trim((string) $raw);
    if ($s === '' || strcasecmp($s, 'N/A') === 0) {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Fecha de nacimiento opcional: NULL si vacío, literal N/A o inválida (adecuado para columna DATE nullable).
 * Si hay fecha válida, devuelve Y-m-d.
 */
function cneCiudadanoFechaParaGuardar(?string $raw): ?string
{
    $s = $raw !== null ? trim((string) $raw) : '';
    if (cneCiudadanoCampoEsNA($s)) {
        return null;
    }
    $ymd = cneCiudadanoFechaYmdDesdeRaw($s);

    return $ymd !== '' ? $ymd : null;
}

/**
 * Género obligatorio al registrar o actualizar desde formularios: solo masculino o femenino.
 */
function cneCiudadanoGeneroRequerido(?string $genero): string
{
    $g = strtolower(trim((string) ($genero ?? '')));
    if ($g === '' || !in_array($g, ['masculino', 'femenino'], true)) {
        throw new Exception('El género es obligatorio (Masculino o Femenino).');
    }

    return $g;
}

function cneCiudadanoConflictoTextoIdentidad(string $prev, string $nuevo, string $labelEs): void
{
    if (cneCiudadanoCampoEsNA($prev)) {
        return;
    }
    if (cneCiudadanoCampoEsNA($nuevo)) {
        throw new Exception("No puede dejar el {$labelEs} vacío o N/A: ya existe un valor registrado para este ciudadano.");
    }
    if (cneCiudadanoNormalizarTextoCmp($prev) !== cneCiudadanoNormalizarTextoCmp($nuevo)) {
        throw new Exception("Datos diferentes: el {$labelEs} no coincide con el registro del ciudadano y no puede modificarse.");
    }
}

/**
 * Impide degradar o alterar datos de identidad ya consolidados (≠ N/A).
 *
 * @param array $prev Filas desde SELECT ciudadanos
 */
function cneCiudadanoValidarNoSobrescribirIdentidadRegistro(array $prev, string $nombresSave, string $apellidosSave, string $generoSave, string $telefonoSave, ?string $fechaSave): void
{
    cneCiudadanoConflictoTextoIdentidad((string) ($prev['ciudadano_nombres'] ?? ''), $nombresSave, 'nombre');
    cneCiudadanoConflictoTextoIdentidad((string) ($prev['ciudadano_apellidos'] ?? ''), $apellidosSave, 'apellido');
    cneCiudadanoConflictoTextoIdentidad((string) ($prev['ciudadano_genero'] ?? ''), $generoSave, 'género');

    $telPrev = (string) ($prev['ciudadano_telefono'] ?? '');
    if (!cneCiudadanoCampoEsNA($telPrev)) {
        if (cneCiudadanoCampoEsNA($telefonoSave)) {
            throw new Exception('No puede dejar el teléfono vacío o N/A: ya existe un valor registrado para este ciudadano.');
        }
        $d1 = preg_replace('/\D/', '', $telPrev);
        $d2 = preg_replace('/\D/', '', $telefonoSave);
        if ($d1 !== $d2) {
            throw new Exception('Datos diferentes: el teléfono no coincide con el registro del ciudadano y no puede modificarse.');
        }
    }

    $dbY = cneCiudadanoFechaYmdDesdeRaw($prev['ciudadano_fecha_nacimiento'] ?? null);
    $nwRaw = $fechaSave !== null ? trim((string) $fechaSave) : '';
    $nwY = cneCiudadanoCampoEsNA($nwRaw) ? '' : cneCiudadanoFechaYmdDesdeRaw($nwRaw);
    if ($dbY !== '') {
        if ($nwY === '') {
            throw new Exception('No puede eliminar la fecha de nacimiento: ya existe un valor registrado para este ciudadano.');
        }
        if ($nwY !== $dbY) {
            throw new Exception('Datos diferentes: la fecha de nacimiento no coincide con el registro del ciudadano y no puede modificarse.');
        }
    }
}

/** estado_id / municipio_id en NULL o ≤0 se tratan como ubicación pendiente (equivalente a N/A con FK). */
function cneCiudadanoFkUbicacionEsNA($v): bool
{
    if ($v === null || $v === false) {
        return true;
    }
    if (is_string($v) && cneCiudadanoCampoEsNA($v)) {
        return true;
    }
    $n = (int) $v;

    return $n <= 0;
}

/** Dirección a persistir: vacío o N/A → literal N/A. */
function cneCiudadanoDireccionParaGuardar(?string $d): string
{
    $t = trim((string) ($d ?? ''));

    return cneCiudadanoCampoEsNA($t) ? 'N/A' : $t;
}

/** ID de estado/municipio para INSERT/UPDATE: vacío → NULL (sin FK). */
function cneCiudadanoEstadoMunicipioIdParaGuardar($raw): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_string($raw) && cneCiudadanoCampoEsNA($raw)) {
        return null;
    }
    $n = (int) $raw;

    return $n > 0 ? $n : null;
}

/**
 * No permite borrar ni cambiar estado/municipio ya asignados (≠ N/A).
 *
 * @param int|null $prevId Valor en BD
 * @param int|null $nuevoId Valor enviado (ya normalizado)
 */
function cneCiudadanoConflictoFkUbicacion(?int $prevId, ?int $nuevoId, string $labelEs): void
{
    if (cneCiudadanoFkUbicacionEsNA($prevId)) {
        return;
    }
    if (cneCiudadanoFkUbicacionEsNA($nuevoId)) {
        throw new Exception("No puede dejar {$labelEs} sin seleccionar: ya existe un valor registrado para este ciudadano.");
    }
    if ((int) $prevId !== (int) $nuevoId) {
        throw new Exception("Datos diferentes: {$labelEs} no coincide con el registro del ciudadano y no puede modificarse.");
    }
}

/**
 * @param array $prev Debe incluir ciudadano_direccion, estado_id, municipio_id
 */
function cneCiudadanoValidarNoSobrescribirUbicacionRegistro(array $prev, string $direccionSave, ?int $estadoIdSave, ?int $municipioIdSave): void
{
    cneCiudadanoConflictoTextoIdentidad((string) ($prev['ciudadano_direccion'] ?? ''), $direccionSave, 'dirección');
    $prevEst = array_key_exists('estado_id', $prev) ? $prev['estado_id'] : null;
    $prevMun = array_key_exists('municipio_id', $prev) ? $prev['municipio_id'] : null;
    cneCiudadanoConflictoFkUbicacion(
        cneCiudadanoFkUbicacionEsNA($prevEst) ? null : (int) $prevEst,
        $estadoIdSave,
        'el estado'
    );
    cneCiudadanoConflictoFkUbicacion(
        cneCiudadanoFkUbicacionEsNA($prevMun) ? null : (int) $prevMun,
        $municipioIdSave,
        'el municipio'
    );
}
?>
