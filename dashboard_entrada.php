<?php
session_start();
require_once 'config/database.php';

// Verificar autenticación y rol por rol_id (1 = Atención al Ciudadano)
if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 1) {
    header('Location: auth/login.php');
    exit();
}

$db = getDB();
$usuario_id = $_SESSION['user_id'];
limpiarSesionesExpiradas();
actualizarSesionUltimaActividad($usuario_id);

// Obtener datos completos del usuario (incluye coordinación)
$usuario = obtenerUsuario($usuario_id);
$coordinacion_nombre = $usuario['coordinacion_nombre'] ?? ($_SESSION['coordinacion_nombre'] ?? '');
$coordinacion_id = $usuario['coordinacion_id'] ?? ($_SESSION['acoordinacion_id'] ?? null);
$CNE_RT = ['dashboard' => 'entrada', 'coord' => (int) ($coordinacion_id ?? 0)];

// Obtener áreas activas, excluyendo la Oficina de Atención al Ciudadano (el usuario ya pertenece a ella)
$stmt = $db->query("SELECT coordinacion_id as id, coordinacion_nombre as nombre FROM coordinacion WHERE coordinacion_estado = 'activo' AND coordinacion_nombre NOT LIKE 'Oficina de Atención al Ciudadano' ORDER BY coordinacion_nombre");
$areas = $stmt->fetchAll();

// Obtener instituciones
$stmt = $db->query("SELECT institucion_id as id, institucion_nombre as nombre FROM institucion ORDER BY institucion_nombre");
$instituciones = $stmt->fetchAll();

// Obtener todos los tipos de trámite para el filtro
$stmt = $db->query("SELECT tramite_id as id, tramite_nombre as nombre FROM tramite WHERE tramite_padre_id IS NULL ORDER BY tramite_nombre");
$tipos_tramite = $stmt->fetchAll();

// Obtener estados y municipios para el formulario
$stmt = $db->query("SELECT estado_id as id, estado_nombre as nombre FROM estados ORDER BY estado_nombre");
$estados = $stmt->fetchAll();
$stmt = $db->query("SELECT municipio_id as id, municipio_nombre as nombre, estado_id FROM municipios ORDER BY municipio_nombre");
$municipios = $stmt->fetchAll();
// Agrupar municipios por estado para uso instantáneo en el frontend
$municipios_por_estado = [];
foreach ($municipios as $m) {
    $eid = $m['estado_id'];
    if (!isset($municipios_por_estado[$eid])) {
        $municipios_por_estado[$eid] = [];
    }
    $municipios_por_estado[$eid][] = ['id' => $m['id'], 'nombre' => $m['nombre']];
}

$estados_lista_js = array_map(function ($e) {
    return ['id' => (string) $e['id'], 'nombre' => $e['nombre']];
}, $estados);
$inst_lista_js = [];
foreach ($instituciones as $inst) {
    $inst_lista_js[] = ['id' => (string) $inst['id'], 'nombre' => $inst['nombre']];
}
$inst_lista_js[] = ['id' => 'otro', 'nombre' => 'Otro...'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/includes/head_viewport.php'; ?>
    <title>Sistema CNE - Dashboard Atención al Ciudadano</title>
    <link rel="icon" href="recursos/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/fuse.js@6.6.2"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <?php require __DIR__ . '/includes/realtime_head.php'; ?>
    <?php require __DIR__ . '/includes/cne_ciudadano_mayus.php'; ?>
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; }
        body { font-size: 14px; line-height: 1.5; overflow-x: hidden; min-height: 100vh; }
        
        /* Sidebar mejorado */
        .sidebar { 
            box-shadow: 2px 0 10px rgba(0,0,0,0.1); 
            transition: transform 0.3s ease; 
            width: 260px;
            position: fixed;
            height: 100vh;
            top: 0;
            left: 0;
            z-index: 40;
            overflow-y: auto;
        }
        .sidebar.mobile-hidden { transform: translateX(-100%); }
        .sidebar.mobile-visible { transform: translateX(0); }
        
        /* Mejor overlay para móvil */
        .menu-overlay { 
            position: fixed; 
            top:0; 
            left:0; 
            width:100%; 
            height:100%; 
            background: rgba(0,0,0,0.5); 
            z-index:30; 
            opacity:0; 
            visibility:hidden; 
            transition: opacity 0.3s; 
        }
        .menu-overlay.active { 
            opacity:1; 
            visibility:visible; 
        }
        
        .layout-shell { width: 100%; max-width: 100%; min-width: 0; overflow-x: hidden; }
        .main-content {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            transition: margin-left 0.3s ease;
            padding: 0;
        }
        
        .sidebar.mobile-visible + .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            max-width: 100%;
        }
        
        @media (min-width: 1024px) {
            .sidebar {
                transform: translateX(0) !important;
                position: fixed;
                height: 100vh;
            }
            
            .main-content {
                margin-left: 260px;
                width: calc(100% - 260px);
                max-width: 100%;
            }
            
            .menu-overlay {
                display: none !important;
            }
            
            .menu-btn {
                display: none !important;
            }
            
            .menu-close-btn {
                display: none !important;
            }
        }
        
        @media (max-width: 1023px) {
            .main-content {
                width: 100% !important;
                margin-left: 0 !important;
                max-width: 100% !important;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 280px;
            }
            
            .form-container {
                padding: 1.5rem !important;
            }
            
            .grid-cols-1 > div {
                margin-bottom: 1rem;
            }
            
            .header {
                padding: 1rem !important;
            }
            
            .section {
                padding: 1rem !important;
            }
            
            .menu-btn {
                width: 44px;
                height: 44px;
                display: flex !important;
                align-items: center;
                justify-content: center;
                background: #3b82f6;
                color: white;
                border-radius: 10px;
                margin-right: 12px;
            }
        }
        
        .menu-item:hover { background-color: #34495e; }
        .menu-item.active { background-color: #1a252f; border-left-color: #3498db; }
        .form-container { 
            box-shadow: 0 5px 20px rgba(0,0,0,0.08); 
            border:1px solid rgba(0,0,0,0.05); 
        }
        
        /* Validation */
        .input-error { border-color: #ef4444 !important; }
        .error-message { color: #ef4444; font-size: 12px; margin-top: 4px; }
        .input-success { border-color: #10b981 !important; }
        /* Recuperado por cédula: solo borde verde (sin cambiar el fondo) */
        .campos-desde-ciudadano,
        .custom-select-button.campos-desde-ciudadano {
            border-color: #10b981 !important;
        }
        /* Valor distinto al registrado en BD (tras búsqueda por cédula) */
        .ciudadano-dato-alterado {
            border-color: #ef4444 !important;
        }
        .ciudadano-campo-protegido {
            border-color: #10b981 !important;
        }
        .custom-select-button.ciudadano-campo-protegido {
            border-color: #10b981 !important;
        }
        .ciudadano-campo-na-editable {
            border-color: #f59e0b !important;
        }
        .custom-select-button.ciudadano-campo-na-editable {
            border-color: #f59e0b !important;
        }
        
        /* Tags */
        .tag-item { 
            display:inline-flex; 
            align-items:center; 
            background:#e2e8f0; 
            color:#4a5568; 
            padding:8px 16px; 
            border-radius:8px; 
            font-size:14px; 
            cursor:pointer; 
            transition:all 0.2s; 
            border:2px solid transparent;
            margin: 4px;
        }
        .tag-item:hover { background:#cbd5e0; transform: translateY(-1px); }
        .tag-item.selected { background:#4299e1; color:white; border-color:#3182ce; }
        
        /* Status badges */
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-pendiente { background-color: #fef3c7; color: #92400e; }
        .status-proceso { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
        .status-completado { background-color: #d1fae5; color: #065f46; }
        .status-cancelado { background-color: #fee2e2; color: #991b1b; }
        .status-redirigido { background-color: #f3e8ff; color: #5b21b6; }
        .status-vencido { background-color: #e9ecef; color: #343a40; border: 1px solid #6c757d; }
        
        /* Mejoras para inputs en móvil */
        @media (max-width: 768px) {
            input, select, textarea {
                font-size: 16px !important;
            }
            
            .flex.items-center.gap-2 {
                flex-wrap: wrap;
            }
            
            .flex.items-center.gap-2 > * {
                margin-bottom: 8px;
            }
        }
        
        /* Scrollbar personalizado */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: #2d3748;
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: #4a5568;
            border-radius: 3px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: #718096;
        }
        
        /* Spinner de carga */
        .loading {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Indicador de carga para input de cédula */
        .loading-input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233b82f6' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 12a9 9 0 1 1-6.219-8.56'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 20px;
            padding-right: 40px !important;
        }

        /* Mensaje informativo */
        .mensaje-info {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Asegurar que el contenido ocupe todo el espacio */
        .section {
            width: 100%;
            padding: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .section {
                padding: 2rem;
            }
        }
        
        /* Centrar formulario correctamente */
        .form-container, .max-w-6xl, .max-w-4xl {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }
        
        /* Asegurar que el formulario esté centrado */
        .max-w-6xl {
            max-width: 72rem;
        }
        
        .max-w-4xl {
            max-width: 56rem;
        }
        
        /* Header ajustado y fijo */
        .header {
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 20;
            background-color: white;
        }
        
        @media (min-width: 768px) {
            .header {
                padding: 1rem 2rem;
            }
        }
        
        /* Ajuste para el contenedor principal en escritorio */
        @media (min-width: 1024px) {
            .section {
                display: flex;
                justify-content: center;
                padding: 2rem;
            }
            
            .form-container {
                width: 100%;
                max-width: 72rem;
            }
            
            .max-w-6xl {
                width: 100%;
            }
        }

        /* Estilos para select personalizado de género */
        .custom-select-wrapper {
            position: relative;
        }
        
        .custom-select-button {
            width: 100%;
            padding: 12px;
            border: 2px solid #d1d5db;
            border-radius: 0.5rem;
            background-color: white;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .custom-select-button:hover {
            border-color: #3b82f6;
        }
        
        .custom-select-button:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        
        .custom-select-button .selected-content {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .custom-select-button .selected-icon {
            width: 16px;
            height: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .custom-select-button .chevron {
            transition: transform 0.3s;
        }
        
        .custom-select-button.open .chevron {
            transform: rotate(180deg);
        }
        
        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin-top: 4px;
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 50;
            max-height: 250px;
            overflow-y: auto;
            display: none;
        }
        
        .custom-select-dropdown.open {
            display: block;
        }
        
        .custom-select-option {
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .custom-select-option:hover {
            background-color: #f3f4f6;
        }
        
        .custom-select-option.selected {
            background-color: #eff6ff;
            font-weight: 500;
        }
        
        .custom-select-option .option-icon {
            width: 16px;
            height: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Campos más compactos para cédula y teléfono */
        .cedula-tipo-compact {
            min-width: 60px;
        }
        
        .telefono-codigo-compact {
            min-width: 80px;
        }
        
        .cedula-input-compact {
            min-width: 120px;
        }
        
        .telefono-input-compact {
            min-width: 120px;
        }
        
        @media (max-width: 768px) {
            .cedula-tipo-compact {
                min-width: 50px;
            }
            
            .telefono-codigo-compact {
                min-width: 70px;
            }
            
            .cedula-input-compact {
                min-width: 100px;
            }
            
            .telefono-input-compact {
                min-width: 100px;
            }
        }
        
        /* Estilos para el nuevo dropdown de búsqueda de Tipo de Trámite (Nueva Solicitud) */
        #seccion-nueva-solicitud .select2-container {
            width: 100% !important;
            max-width: 100%;
        }
        #seccion-nueva-solicitud .select2-selection__rendered {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            min-height: 45px;
            height: auto !important;
            line-height: 1.35;
            padding-right: 2.25rem !important;
            box-sizing: border-box;
        }

        .tramite-search-wrapper {
            position: relative;
            width: 100% !important;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        
        .tramite-search-button {
            width: 100%;
            padding: 10px 2.5rem 10px 12px;
            border: 2px solid #d1d5db;
            border-radius: 0.5rem;
            background-color: white;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.2s;
            min-height: 45px;
            height: auto;
            box-sizing: border-box;
        }
        
        .tramite-search-button:hover {
            border-color: #3b82f6;
        }
        
        .tramite-search-button:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        
        .tramite-search-button .selected-tramite-content {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
            padding-right: 0.25rem;
        }
        
        .tramite-search-button .selected-tramite-text {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            word-break: break-word;
            line-height: 1.35;
            max-width: none !important;
        }
        
        .tramite-search-button .chevron {
            flex-shrink: 0;
            margin-left: 0.5rem;
            transition: transform 0.3s;
        }
        
        .tramite-search-button.open .chevron {
            transform: rotate(180deg);
        }
        .tramite-search-button.input-error { border-color: #ef4444 !important; }
        .tramite-search-button.input-success { border-color: #10b981 !important; }
        .tramite-search-button.campos-desde-ciudadano { border-color: #10b981 !important; }
        .tramite-search-button.ciudadano-dato-alterado { border-color: #ef4444 !important; }
        .tramite-search-button.ciudadano-campo-protegido { border-color: #10b981 !important; }
        .tramite-search-button.ciudadano-campo-na-editable { border-color: #f59e0b !important; }
        
        .tramite-search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin-top: 4px;
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 50;
            max-height: 350px;
            overflow-y: auto;
            display: none;
        }
        
        .tramite-search-dropdown.open {
            display: block;
        }
        
        /* Campo de búsqueda dentro del dropdown */
        .tramite-search-input-container {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            background-color: #f9fafb;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .tramite-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .tramite-search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        
        .tramite-search-input::placeholder {
            color: #9ca3af;
        }
        
        /* Contenedor de resultados */
        .tramite-search-results {
            max-height: 250px;
            overflow-y: auto;
        }
        
        .tramite-search-option {
            padding: 12px 16px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .tramite-search-option:last-child {
            border-bottom: none;
        }
        
        .tramite-search-option:hover {
            background-color: #f3f4f6;
        }
        
        .tramite-search-option.selected {
            background-color: #eff6ff;
            color: #1e40af;
            font-weight: 500;
            position: relative;
        }
        
        .tramite-search-option.selected::after {
            content: "✓";
            position: absolute;
            right: 12px;
            color: #1e40af;
            font-weight: bold;
        }
        
        .tramite-placeholder {
            color: #9ca3af;
            font-style: italic;
        }
        
        /* Mensaje cuando no hay resultados */
        .no-results-message {
            padding: 20px;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }
        
        /* Indicador de búsqueda */
        .searching-indicator {
            padding: 12px;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }
        
        /* Highlight de resultados de búsqueda */
        .search-highlight {
            background-color: #fef3c7;
            padding: 0 2px;
            border-radius: 2px;
        }
        
        @media (max-width: 768px) {
            .tramite-search-dropdown {
                max-height: 300px;
            }
            
            .tramite-search-results {
                max-height: 200px;
            }
        }

        /* ===================== MODALES CENTRADOS ===================== */
        
        /* Modal de Confirmación */
        #confirmModal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }

        #confirmModal.active {
            display: flex;
        }

        #confirmModal .modal-content {
            background-color: white;
            border-radius: 12px;
            padding: 1.5rem;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            margin: 0 auto;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Modal de Éxito */
        #successModal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }

        #successModal.active {
            display: flex;
        }

        #successModal .modal-content {
            background-color: white;
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            margin: 0 auto;
        }

        /* Modal de Confirmación Completado Inmediato */
        #confirmCompletadoModal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }

        #confirmCompletadoModal.active {
            display: flex;
        }

        #confirmCompletadoModal .modal-content {
            background-color: white;
            border-radius: 12px;
            padding: 1.5rem;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            margin: 0 auto;
        }

        /* Responsive para modales en móvil */
        @media (max-width: 768px) {
            #confirmModal .modal-content,
            #successModal .modal-content,
            #confirmCompletadoModal .modal-content {
                margin: 0;
                max-width: 95%;
                padding: 1.25rem;
            }
        }

        /* Asegurar que el contenido del modal esté centrado */
        #confirmModal .modal-content > div,
        #successModal .modal-content > div,
        #confirmCompletadoModal .modal-content > div {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        
        /* Scrollbar personalizado para modales */
        #confirmModal .modal-content::-webkit-scrollbar,
        #successModal .modal-content::-webkit-scrollbar,
        #confirmCompletadoModal .modal-content::-webkit-scrollbar {
            width: 8px;
        }
        
        #confirmModal .modal-content::-webkit-scrollbar-track,
        #successModal .modal-content::-webkit-scrollbar-track,
        #confirmCompletadoModal .modal-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        #confirmModal .modal-content::-webkit-scrollbar-thumb,
        #successModal .modal-content::-webkit-scrollbar-thumb,
        #confirmCompletadoModal .modal-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        #confirmModal .modal-content::-webkit-scrollbar-thumb:hover,
        #successModal .modal-content::-webkit-scrollbar-thumb:hover,
        #confirmCompletadoModal .modal-content::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Botón de Tramite Inmediato */
        .btn-tramite-inmediato {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            transition: all 0.3s;
        }
        
        .btn-tramite-inmediato:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        /* Badge para estado completado */
        .badge-completado {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background-color: #10b981;
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Dropdown de usuario simplificado */
        .user-dropdown-btn {
            width: 40px;
            height: 40px;
            border-radius: 9999px;
            background-color: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }
        .user-dropdown-btn:hover {
            background-color: #2563eb;
        }
        #dropdown-menu {
            min-width: 160px;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <!-- Overlay para menú móvil -->
    <div class="menu-overlay" id="menu-overlay"></div>
    
    <div class="flex min-h-screen layout-shell w-full min-w-0">
        <!-- Sidebar -->
        <aside class="sidebar bg-gray-800 text-white flex flex-col mobile-hidden" id="sidebar">
            <div class="sidebar-header p-5 border-b border-gray-700 flex justify-between items-center gap-2">
                <div class="logo-container flex items-center justify-center w-full min-w-0">
                    <img src="recursos/Logo.png" alt="Logo CNE" class="logo-img max-w-40 max-h-16 object-contain">
                </div>
                <button type="button" class="menu-close-btn text-white text-lg lg:hidden shrink-0 p-2 rounded-lg hover:bg-white/10" id="menu-close-btn" aria-label="Cerrar menú"><i class="fas fa-times"></i></button>
            </div>
            <nav class="menu flex-1 py-4">
                <ul class="list-none">
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all duration-300 active" data-section="nueva-solicitud">
                        <i class="fas fa-plus-circle w-5 text-center"></i> 
                        <span>Nueva Solicitud</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all duration-300" data-section="mis-solicitudes">
                        <i class="fas fa-list-alt w-5 text-center"></i> 
                        <span>Mis Solicitudes</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all duration-300" data-section="buscar-tramite">
                        <i class="fas fa-search w-5 text-center"></i> 
                        <span>Buscar Trámite</span>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer mt-auto p-4 border-t border-gray-700 text-sm text-gray-400 text-center">
                <p class="mb-1">Sistema de Gestión CNE</p>
                <p class="text-xs">v2.0.0</p>
            </div>
        </aside>

        <!-- Contenido Principal -->
        <main class="main-content flex flex-col bg-gray-50 w-full max-w-full min-w-0">
            <!-- Header unificado (sticky) -->
            <header class="header bg-white shadow-sm sticky top-0 z-20 px-4 md:px-6 py-4">
                <div class="header-content flex justify-between items-center gap-2">
                    <!-- Izquierda: título de la sección y botón menú móvil -->
                    <div class="flex items-center min-w-0">
                        <button type="button" class="menu-btn bg-blue-500 text-white p-2 rounded-lg items-center justify-center mr-2 lg:hidden shrink-0" id="menu-btn" aria-label="Abrir menú">
                            <i class="fas fa-bars text-base"></i>
                        </button>
                        <h1 class="text-lg md:text-xl font-semibold text-gray-800" id="section-title">Nueva Solicitud</h1>
                    </div>
                    
                    <!-- Derecha: usuario | coordinación + icono dropdown -->
                    <div class="flex items-center gap-3 md:gap-4">
                        <!-- Texto usuario y coordinación -->
                        <div class="text-right hidden sm:block">
                            <p class="font-semibold text-gray-800 leading-none">
                                <?php echo htmlspecialchars(($_SESSION['nombre_completo'] ?? 'Usuario') . ' | ' . ($coordinacion_nombre ?: 'Sin coordinación')); ?>
                            </p>
                            <p class="text-xs text-gray-500">Atención al Ciudadano</p>
                        </div>
                        
                        <!-- Icono de usuario con dropdown -->
                        <div class="relative">
                            <button id="user-dropdown-btn" class="user-dropdown-btn">
                                <i class="fas fa-user"></i>
                            </button>
                            <div id="dropdown-menu" class="absolute right-0 mt-2 w-40 bg-white rounded-lg shadow-lg border border-gray-200 hidden z-50">
                                <a href="auth/logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-2"></i> Cerrar Sesión
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Sección: Nueva Solicitud -->
            <section class="section active" id="seccion-nueva-solicitud">
                <div class="form-container bg-white rounded-xl shadow-lg">
                    <div class="max-w-6xl mx-auto p-6 md:p-8">
                        <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-6 pb-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                            <span class="flex items-center gap-3 min-w-0">
                                <i class="fas fa-file-alt text-blue-500 shrink-0"></i>
                                <span>Registrar Nueva Solicitud</span>
                            </span>
                            <button type="button" id="btn-limpiar-formulario-entrada" class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 flex items-center gap-2 shrink-0" title="Vaciar el formulario y desbloquear campos">
                                <i class="fas fa-broom" aria-hidden="true"></i>
                                <span>Limpiar</span>
                            </button>
                        </h2>
                        
                        <form id="tramitante-form" method="POST">
                            <!-- Campo oculto para tipo de solicitud -->
                            <input type="hidden" id="tipo_solicitud" name="tipo_solicitud" value="normal">
                            
                            <!-- Cédula, Teléfono y Género en una fila -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-6">
                                <!-- Cédula -->
                                <div id="cedula-container" class="relative">
                                    <label class="block mb-2 font-semibold text-gray-700">Cédula <span class="text-gray-500 font-normal">(opcional)</span></label>
                                    <div class="flex flex-row items-center gap-2">
                                        <select id="cedula-tipo" name="cedula_tipo" class="cedula-tipo-compact p-3 border-2 border-gray-300 rounded-lg font-bold">
                                            <option value="V">V</option>
                                            <option value="E">E</option>
                                            <option value="J">J</option>
                                            <option value="G">G</option>
                                        </select>
                                        <span class="font-bold text-gray-500">-</span>
                                        <input type="text" id="cedula-numero" name="cedula_numero"
                                            class="cedula-input-compact p-3 border-2 border-gray-300 rounded-lg font-mono"
                                            placeholder="12345678"
                                            maxlength="8"
                                            oninput="validarCedula(this); programarBusquedaCiudadanoPorCedulaEntrada();">
                                    </div>
                                    <div id="error-cedula" class="error-message hidden"></div>
                                </div>
                                
                                <!-- Teléfono -->
                                <div>
                                    <label class="block mb-2 font-semibold text-gray-700">Teléfono <span class="text-gray-500 font-normal">(opcional)</span></label>
                                    <div class="flex flex-row items-center gap-2">
                                        <select id="telefono-codigo" name="telefono_codigo" class="telefono-codigo-compact p-3 border-2 border-gray-300 rounded-lg">
                                            <option value="0412">0412</option>
                                            <option value="0414">0414</option>
                                            <option value="0416">0416</option>
                                            <option value="0424">0424</option>
                                            <option value="0426">0426</option>
                                        </select>
                                        <span class="font-bold text-gray-500">-</span>
                                        <input type="text" id="telefono-numero" name="telefono_numero"
                                            class="telefono-input-compact p-3 border-2 border-gray-300 rounded-lg"
                                            placeholder="1234567"
                                            maxlength="7"
                                            oninput="validarTelefono(this)">
                                    </div>
                                    <div id="error-telefono" class="error-message hidden"></div>
                                </div>
                                
                                <!-- Género -->
                                <div>
                                    <label for="genero" class="block mb-2 font-semibold text-gray-700">Género del Documento *</label>
                                    <div class="custom-select-wrapper">
                                        <select id="genero" name="genero" class="hidden" required>
                                            <option value="">Seleccione un género</option>
                                            <option value="masculino">Masculino</option>
                                            <option value="femenino">Femenino</option>
                                        </select>
                                        
                                        <button type="button" class="custom-select-button" id="custom-genero-button">
                                            <span class="selected-content">
                                                <span class="selected-icon text-gray-400" id="selected-icon">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-gender-bigender">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                        <path d="M7 11a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                                                        <path d="M19 3l-5 5" />
                                                        <path d="M15 3h4v4" />
                                                        <path d="M11 16v6" /><path d="M8 19h6" />
                                                    </svg>
                                                </span>
                                                <span id="selected-text" class="text-gray-400">Seleccione un género</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        
                                        <div class="custom-select-dropdown" id="custom-genero-dropdown">
                                            <div class="custom-select-option" data-value="">
                                                <div class="option-icon text-gray-400">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-gender-bigender">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                        <path d="M7 11a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M19 3l-5 5" />
                                                        <path d="M15 3h4v4" /><path d="M11 16v6" /><path d="M8 19h6" />
                                                    </svg>
                                                </div>
                                                <span>Seleccione un género</span>
                                            </div>
                                            <div class="custom-select-option" data-value="masculino">
                                                <div class="option-icon text-blue-500">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-man">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                        <path d="M10 16v5" /><path d="M14 16v5" />
                                                        <path d="M9 9h6l-1 7h-4l-1 -7" />
                                                        <path d="M5 11c1.333 -1.333 2.667 -2 4 -2" />
                                                        <path d="M19 11c-1.333 -1.333 -2.667 -2 -4 -2" />
                                                        <path d="M10 4a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                                    </svg>
                                                </div>
                                                <span>Masculino</span>
                                            </div>
                                            <div class="custom-select-option" data-value="femenino">
                                                <div class="option-icon text-pink-500">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-woman">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                                        <path d="M10 16v5" /><path d="M14 16v5" />
                                                        <path d="M8 16h8l-2 -7h-4l-2 7" />
                                                        <path d="M5 11c1.667 -1.333 3.333 -2 5 -2" />
                                                        <path d="M19 11c-1.667 -1.333 -3.333 -2 -5 -2" />
                                                        <path d="M10 4a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                                    </svg>
                                                </div>
                                                <span>Femenino</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="error-genero" class="error-message hidden"></div>
                                </div>
                            </div>
                            
                            <!-- Nombres y Apellidos -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <div>
                                    <label for="nombres" class="block mb-2 font-semibold text-gray-700">Nombres</label>
                                    <input type="text" id="nombres" name="nombres"
                                        class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        placeholder="Ingrese los nombres"
                                        oninput="validarNombre(this)">
                                    <div id="error-nombres" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label for="apellidos" class="block mb-2 font-semibold text-gray-700">Apellidos</label>
                                    <input type="text" id="apellidos" name="apellidos"
                                        class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        placeholder="Ingrese los apellidos"
                                        oninput="validarApellido(this)">
                                    <div id="error-apellidos" class="error-message hidden"></div>
                                </div>
                            </div>
                            
                            <!-- Fecha de Nacimiento y Estado -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <div>
                                    <label for="fecha_nacimiento" class="block mb-2 font-semibold text-gray-700">Fecha de Nacimiento</label>
                                    <div class="relative">
                                        <input type="text" id="fecha_nacimiento" name="fecha_nacimiento" readonly
                                            class="w-full p-3 md:p-4 pr-10 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                            placeholder="dd/mm/aaaa">
                                        <i class="fas fa-calendar-alt absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                                    </div>
                                </div>
                                <div>
                                    <label for="estado-search-button" class="block mb-2 font-semibold text-gray-700">Estado</label>
                                    <div class="tramite-search-wrapper">
                                        <button type="button" class="tramite-search-button" id="estado-search-button" aria-haspopup="listbox">
                                            <span class="selected-tramite-content">
                                                <span class="selected-tramite-text tramite-placeholder">Seleccione un estado</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        <div class="tramite-search-dropdown" id="estado-search-dropdown">
                                            <div class="tramite-search-input-container">
                                                <input type="text" class="tramite-search-input" id="estado-search-input" placeholder="Buscar estado..." autocomplete="off" aria-label="Buscar estado">
                                            </div>
                                            <div class="tramite-search-results" id="estado-search-results"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="estado_id" name="estado_id" value="">
                                    <div id="error-estado" class="error-message hidden"></div>
                                </div>
                            </div>
                            
                            <!-- Municipio, Correo electrónico y Dirección en una sola fila -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                            <!-- Columna izquierda: Municipio + Correo electrónico -->
                            <div class="space-y-4">
                                <div>
                                    <label for="municipio-search-button" class="block mb-2 font-semibold text-gray-700">Municipio</label>
                                    <div class="tramite-search-wrapper">
                                        <button type="button" class="tramite-search-button" id="municipio-search-button" disabled aria-haspopup="listbox">
                                            <span class="selected-tramite-content">
                                                <span class="selected-tramite-text tramite-placeholder">Seleccione un municipio</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        <div class="tramite-search-dropdown" id="municipio-search-dropdown">
                                            <div class="tramite-search-input-container">
                                                <input type="text" class="tramite-search-input" id="municipio-search-input" placeholder="Buscar municipio..." autocomplete="off" aria-label="Buscar municipio" disabled>
                                            </div>
                                            <div class="tramite-search-results" id="municipio-search-results"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="municipio_id" name="municipio_id" value="" disabled>
                                    <div id="error-municipio" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label for="ciudadano_email" class="block mb-2 font-semibold text-gray-700">Correo electrónico</label>
                                    <input type="email" id="ciudadano_email" name="ciudadano_email"
                                        class="w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        placeholder="Opcional">
                                </div>
                            </div>

                            <!-- Columna derecha: Dirección / Punto de Referencia -->
                            <div>
                                <label for="direccion" class="block mb-2 font-semibold text-gray-700">Dirección / Punto de Referencia</label>
                                <textarea id="direccion" name="direccion" rows="3"
                                    class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                    placeholder="Especifique dirección o punto de referencia"></textarea>
                            </div>
                        </div>
                            
                            <!-- Institución y Coordinación (fila 1) -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <!-- Institución -->
                                <div>
                                    <label for="institucion-search-button" class="block mb-2 font-semibold text-gray-700">Institución *</label>
                                    <?php
                                        $personalInst = null;
                                        $otrasInst = [];
                                        foreach ($instituciones as $inst) {
                                            if (strcasecmp($inst['nombre'], 'Personal') === 0) {
                                                $personalInst = $inst;
                                            } else {
                                                $otrasInst[] = $inst;
                                            }
                                        }
                                    ?>
                                    <div class="tramite-search-wrapper">
                                        <button type="button" class="tramite-search-button" id="institucion-search-button" aria-haspopup="listbox">
                                            <span class="selected-tramite-content">
                                                <span class="selected-tramite-text tramite-placeholder">Seleccione una institución</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        <div class="tramite-search-dropdown" id="institucion-search-dropdown">
                                            <div class="tramite-search-input-container">
                                                <input type="text" class="tramite-search-input" id="institucion-search-input" placeholder="Buscar institución..." autocomplete="off" aria-label="Buscar institución">
                                            </div>
                                            <div class="tramite-search-results" id="institucion-search-results"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="institucion" name="institucion" value="<?php echo $personalInst ? htmlspecialchars((string)$personalInst['id']) : ''; ?>" required data-personal-id="<?php echo $personalInst['id'] ?? ''; ?>">
                                    <div id="error-institucion" class="error-message hidden"></div>
                                    <div id="institucion-otro-wrapper" class="mt-3 hidden">
                                        <input 
                                            type="text" 
                                            id="institucion-otro" 
                                            name="institucion_otro" 
                                            placeholder="Ingrese el nombre de la institución"
                                            class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        />
                                        <div id="error-institucion-otro" class="error-message hidden"></div>
                                    </div>
                                </div>
                                
                                <!-- Coordinación -->
                                <div>
                                    <label for="area_id" class="block mb-2 font-semibold text-gray-700">Coordinación *</label>
                                    <select id="area_id" name="area_id" required
                                        class="w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        onchange="cargarTiposTramite(this.value)">
                                        <option value="">Seleccione una coordinación</option>
                                        <?php foreach ($areas as $area): ?>
                                            <option value="<?php echo $area['id']; ?>"><?php echo htmlspecialchars($area['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="error-area" class="error-message hidden"></div>
                                </div>
                            </div>
                            
                            <!-- Tipo de Trámite -->
                            <div class="mb-6">
                                <label class="block mb-2 font-semibold text-gray-700">Tipo de Trámite *</label>
                                <div class="tramite-search-wrapper">
                                    <button type="button" class="tramite-search-button" id="tramite-search-button">
                                        <span class="selected-tramite-content">
                                            <span class="selected-tramite-text tramite-placeholder">Seleccione un tipo de trámite</span>
                                        </span>
                                        <i class="fas fa-chevron-down chevron"></i>
                                    </button>
                                    
                                    <div class="tramite-search-dropdown" id="tramite-search-dropdown">
                                        <div class="tramite-search-input-container">
                                            <input type="text" 
                                                   class="tramite-search-input" 
                                                   id="tramite-search-input"
                                                   placeholder="Buscar tipo de trámite..."
                                                   autocomplete="off">
                                        </div>
                                        <div class="tramite-search-results" id="tramite-search-results">
                                            <div class="tramite-search-option" data-value="">
                                                <span class="tramite-placeholder">Seleccione un tipo de trámite</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" id="tipo_tramite_id" name="tipo_tramite_id" value="">
                                <div id="error-tipo-tramite" class="error-message hidden">Debe seleccionar un tipo de trámite</div>
                            </div>
                            
                            <div id="subtramite-wrapper" class="mb-6 hidden">
                                <label for="subtramite_id" class="block mb-2 font-semibold text-gray-700">Variante o Subtrámite</label>
                                <select id="subtramite_id" class="w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                    <option value="">Seleccione una variante</option>
                                </select>
                                <div id="error-subtramite" class="error-message hidden">Debe seleccionar una variante</div>
                            </div>
                            
                            <div id="requisitos-wrapper" class="mb-6 hidden">
                                <h3 class="text-gray-800 font-semibold mb-4 pb-2 border-b border-gray-200">Requisitos del Trámite</h3>
                                <p class="text-sm text-gray-600 mb-3">En <strong>trámite inmediato</strong> debe quedar al menos un requisito marcado. En <strong>solicitud normal</strong> puede dejarlos sin marcar.</p>
                                <div id="requisitos-list" class="space-y-2.5 text-gray-800"></div>
                            </div>
                            
                            <!-- Campos dinámicos según tipo de trámite -->
                            <div id="campos-dinamicos" class="mb-6 hidden">
                                <h3 class="text-gray-800 font-semibold mb-4 pb-2 border-b border-gray-200">Información Adicional</h3>
                                <div id="campos-contenido"></div>
                            </div>
                            
                            <!-- Botones de Envío -->
                            <div class="text-center mt-8 pt-6 border-t border-gray-200">
                                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                                    <button type="button" id="btn-tramite-inmediato" class="btn-tramite-inmediato py-3 px-6 rounded-lg font-medium flex items-center gap-2">
                                        <i class="fas fa-bolt"></i> 
                                        <span>Realizar Trámite Inmediato</span>
                                    </button>
                                    
                                    <div class="text-gray-400">|</div>
                                    
                                    <button type="submit" class="bg-blue-500 text-white py-3 px-6 rounded-lg font-medium hover:bg-blue-600 transition duration-300 flex items-center gap-2">
                                        <i class="fas fa-paper-plane"></i> 
                                        <span>Enviar Solicitud Normal</span>
                                    </button>
                                </div>
                               <!--  <div class="mt-4 text-sm text-gray-500 flex flex-col gap-1">
                                    <p class="flex items-center justify-center gap-2">
                                        <i class="fas fa-bolt text-green-500"></i>
                                        <span><strong>Trámite Inmediato:</strong> Se marca como COMPLETADO automáticamente</span>
                                    </p>
                                    <p class="flex items-center justify-center gap-2">
                                        <i class="fas fa-paper-plane text-blue-500"></i>
                                        <span><strong>Solicitud Normal:</strong> Inicia en estado PENDIENTE para seguimiento</span>
                                    </p>
                                </div> -->
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN: MIS SOLICITUDES -->
            <section class="section hidden" id="seccion-mis-solicitudes">
                <div class="max-w-7xl mx-auto w-full">
                    <!-- Panel de información -->
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow-lg mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-history text-blue-600"></i> 
                                    <span>Mis Solicitudes Registradas</span>
                                </h2>
                                <p class="text-sm text-gray-600">Visualiza y gestiona todas las solicitudes registradas en Atención al Ciudadano</p>
                            </div>
                            <div class="bg-white px-4 py-3 rounded-lg shadow-sm">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-chart-line text-blue-500"></i>
                                    <div>
                                        <p class="text-xs text-gray-500">Total de solicitudes</p>
                                        <p class="text-lg font-bold text-gray-800" id="contador-solicitudes">0</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Panel de filtros mejorado -->
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow-lg mb-6">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                            <h3 class="text-md font-semibold text-gray-700 mb-3 md:mb-0 flex items-center gap-2">
                                <i class="fas fa-filter text-blue-500"></i>
                                Filtros de búsqueda
                            </h3>
                            <button id="btn-reset-filtros" class="text-sm text-gray-600 hover:text-gray-800 flex items-center gap-1">
                                <i class="fas fa-redo"></i>
                                Restablecer filtros
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                            <!-- Filtro por Cédula -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-id-card mr-1"></i> Cédula
                                </label>
                                <input type="text" id="filtro-cedula" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500"
                                       placeholder="Ej: V-12345678">
                            </div>
                            
                            <!-- Filtro por Coordinación -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-building mr-1"></i> Coordinación
                                </label>
                                <select id="filtro-area" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todas las coordinaciones</option>
                                    <?php foreach ($areas as $area): ?>
                                        <option value="<?php echo $area['id']; ?>"><?php echo htmlspecialchars($area['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filtro por Estado -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-flag mr-1"></i> Estado
                                </label>
                                <select id="filtro-estado" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todos los estados</option>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="en_revision">En Proceso</option>
                                    <option value="completada">Completada</option>
                                    <option value="redirigida">Redirigida</option>
                                    <option value="vencida">Vencido</option>
                                </select>
                            </div>
                            
                            <!-- Filtro por Tipo de Trámite -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-file-alt mr-1"></i> Tipo de Trámite
                                </label>
                                <select id="filtro-tipo-tramite" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todos los tipos</option>
                                    <?php foreach ($tipos_tramite as $tipo): ?>
                                        <option value="<?php echo $tipo['id']; ?>"><?php echo htmlspecialchars($tipo['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="filtro-subtramite-wrapper" class="hidden">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-code-branch mr-1"></i> Variante / Subtrámite
                                </label>
                                <select id="filtro-subtramite" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todas las variantes</option>
                                </select>
                            </div>
                            
                            <!-- Filtro por Institución -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-university mr-1"></i> Institución
                                </label>
                                <select id="filtro-institucion" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todas las instituciones</option>
                                    <?php foreach ($instituciones as $institucion): ?>
                                        <option value="<?php echo $institucion['id']; ?>"><?php echo htmlspecialchars($institucion['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filtro por Fecha Desde -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-calendar-start mr-1"></i> Fecha Desde
                                </label>
                                <input type="date" id="filtro-fecha-desde" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                            
                            <!-- Filtro por Fecha Hasta -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-calendar-end mr-1"></i> Fecha Hasta
                                </label>
                                <input type="date" id="filtro-fecha-hasta" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                        </div>
                        
                        <!-- Botón de búsqueda -->
                        <div class="flex justify-end">
                            <button id="btn-aplicar-filtros" class="bg-blue-600 text-white px-5 py-3 rounded-lg hover:bg-blue-700 transition-colors duration-200 flex items-center gap-2 shadow-sm">
                                <i class="fas fa-search"></i>
                                <span>Aplicar Filtros</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Tabla de solicitudes -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center">
                                <h3 class="text-md font-semibold text-gray-700 flex items-center gap-2">
                                    <i class="fas fa-list-ul text-blue-500"></i>
                                    Listado de Solicitudes
                                </h3>
                                <div class="mt-2 sm:mt-0">
                                    <div class="text-sm text-gray-600 flex items-center gap-2">
                                        <i class="fas fa-info-circle text-blue-500"></i>
                                        <span>Mostrando <span id="mostrando-contador">0</span> de <span id="total-contador">0</span> solicitudes</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="cne-tabla-ciudadano-mayus min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-id-card mr-1"></i> Cédula
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-user mr-1"></i> Ciudadano
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-building mr-1"></i> Coordinación
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-flag mr-1"></i> Estado
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-file-alt mr-1"></i> Tipo de Trámite
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-hashtag mr-1"></i> N° Seguimiento
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <i class="fas fa-calendar mr-1"></i> Fecha
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="mis-solicitudes-content" class="bg-white divide-y divide-gray-200">
                                    <tr>
                                        <td colspan="7" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <div class="loading mb-4"></div>
                                                <p class="text-gray-500">Cargando solicitudes...</p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                            <div class="flex flex-col sm:flex-row justify-between items-center">
                                <div class="text-sm text-gray-600 mb-2 sm:mb-0">
                                    <i class="fas fa-clock text-blue-500 mr-1"></i>
                                    Última actualización: <span id="ultima-actualizacion">--:--:--</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button id="btn-refresh" class="text-blue-600 hover:text-blue-800 flex items-center gap-1 text-sm">
                                        <i class="fas fa-sync-alt"></i>
                                        Actualizar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sección: Buscar Trámite -->
            <section class="section hidden" id="seccion-buscar-tramite">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-white rounded-xl p-6 md:p-8 shadow-lg border border-gray-100">
                        <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                            <i class="fas fa-search text-blue-500"></i> 
                            <span>Buscar Trámite</span>
                        </h2>
                        <p class="text-sm text-gray-600 mb-6">Consulte por número de seguimiento o por cédula del ciudadano para ver el historial de solicitudes.</p>
                        
                        <div class="mb-8">
                            <label for="numero-seguimiento" class="block mb-2 font-semibold text-gray-700">Número de seguimiento o cédula</label>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input type="text" id="numero-seguimiento" 
                                    class="flex-1 p-3 md:p-4 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 font-mono"
                                    placeholder="Buscar por nro. seguimiento o cédula..."
                                    autocomplete="off">
                                <button type="button" id="btn-buscar-tramite" class="bg-blue-600 text-white px-4 md:px-6 py-3 md:py-4 rounded-lg hover:bg-blue-700 shadow-sm transition-colors">
                                    <i class="fas fa-search"></i> 
                                    <span class="ml-2">Buscar</span>
                                </button>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">Ejemplos: código <span class="font-mono text-gray-700">CNE-2026-…</span> o documento <span class="font-mono text-gray-700">V-12345678</span>. Varios trámites de la misma persona se listan del más reciente al más antiguo.</p>
                        </div>
                        
                        <div id="resultado-busqueda" class="hidden">
                            <h3 class="text-md font-semibold text-gray-800 mb-2 flex items-center gap-2 pb-2 border-b border-gray-200">
                                <i class="fas fa-list-ul text-blue-500"></i>
                                Resultados de la búsqueda
                            </h3>
                            <p id="resumen-busqueda-tramite" class="text-sm text-gray-600 mb-4 hidden"></p>
                            <div id="contenido-resultado-busqueda"></div>
                        </div>
                        
                        <div id="sin-resultados" class="hidden text-center py-8">
                            <i class="fas fa-search text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-500">Ingrese un número de seguimiento o cédula para buscar</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Modal de Confirmación para Solicitud Normal -->
    <div id="confirmModal">
        <div class="modal-content">
            <div class="flex items-center gap-3 mb-4">
                <div class="bg-blue-100 p-2 rounded-full">
                    <i class="fas fa-check-circle text-blue-500 text-xl"></i>
                </div>
                <h3 class="text-lg font-semibold">Confirmar Envío de Solicitud</h3>
            </div>
            
            <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                <div class="flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    <p class="text-sm text-blue-700">Esta solicitud iniciará en estado <strong>PENDIENTE</strong> para seguimiento</p>
                </div>
            </div>
            
            <div id="confirm-details" class="cne-bloque-datos-ciudadano mb-6 bg-gray-50 p-4 rounded-lg space-y-3"></div>
            
            <div class="flex justify-end gap-3">
                <button id="cancelBtn" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Cancelar</button>
                <button id="confirmBtn" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 flex items-center gap-2">
                    <span>Confirmar</span>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación para Trámite Inmediato -->
    <div id="confirmCompletadoModal">
        <div class="modal-content">
            <div class="flex items-center gap-3 mb-4">
                <div class="bg-green-100 p-2 rounded-full">
                    <i class="fas fa-bolt text-green-500 text-xl"></i>
                </div>
                <h3 class="text-lg font-semibold">Confirmar Trámite Inmediato</h3>
            </div>
            
            <div class="mb-4 p-3 bg-green-50 rounded-lg">
                <div class="flex items-center gap-2">
                    <i class="fas fa-info-circle text-green-500"></i>
                    <p class="text-sm text-green-700">Este trámite se marcará como <strong>COMPLETADO</strong> automáticamente</p>
                </div>
            </div>
            
            <div id="confirm-completado-details" class="cne-bloque-datos-ciudadano mb-6 bg-gray-50 p-4 rounded-lg space-y-3"></div>
            
            <div class="flex justify-end gap-3">
                <button id="cancelCompletadoBtn" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Cancelar</button>
                <button id="confirmCompletadoBtn" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 flex items-center gap-2">
                    <span>Confirmar</span>
                    <i class="fas fa-bolt"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de Éxito -->
    <div id="successModal">
        <div class="modal-content">
            <div class="mb-4">
                <div class="bg-green-100 text-green-500 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold mb-2">¡Solicitud Registrada!</h3>
                <p class="text-gray-600 mb-4" id="success-message">La solicitud ha sido registrada exitosamente en el sistema</p>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <p class="text-sm font-medium text-blue-800 mb-1">Número de Seguimiento:</p>
                <p id="numero-seguimiento-generado" class="text-xl font-bold text-blue-600 font-mono"></p>
                <div id="estado-info" class="mt-2"></div>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <button id="btn-nueva-solicitud" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                    Nueva Solicitud
                </button>
                <button id="btn-imprimir" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                    <i class="fas fa-print mr-2"></i> Imprimir
                </button>
            </div>
        </div>
    </div>

    <script src="recursos/js/cne_nueva_solicitud_combos_busqueda.js?v=1"></script>
    <script>
        // Variables globales
        let tiposTramiteData = {};
        let tramiteParaBuscar = ''; // Variable para almacenar el número de seguimiento temporalmente
        let fuseInstance = null; // Instancia de Fuse.js para búsqueda
        let tramiteSearchDebounceTimer = null; // Timer para debounce
        let currentTramiteList = []; // Lista actual de trámites
        let tipoSolicitudActual = 'normal'; // 'normal' o 'inmediato'
        let flatpickrFechaNacimiento = null;
        let fechaNacimientoEntradaProgrammatic = false;
        let busquedaCiudadanoAbortEntrada = null;
        let busquedaCiudadanoSeqEntrada = 0;
        let debounceTimerBusquedaCedulaEntrada = null;
        /** Valores opcionales devueltos por la última búsqueda exitosa de ciudadano (correo, dirección, estado, municipio). */
        let snapshotDatosOpcionalesCiudadanoEntrada = null;
        const MUNICIPIOS_POR_ESTADO = <?php echo json_encode($municipios_por_estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const CNE_NS_ESTADOS = <?php echo json_encode($estados_lista_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const CNE_NS_INST = <?php echo json_encode($inst_lista_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            inicializarMenu();
            inicializarValidaciones();
            inicializarFiltros();
            inicializarBusqueda();
            inicializarCustomSelectGenero();
            inicializarTramiteSearch();
            if (typeof window.cneNuevaSolicitudCombosInit === 'function') {
                window.cneNuevaSolicitudCombosInit({
                    estadosLista: CNE_NS_ESTADOS,
                    institucionesLista: CNE_NS_INST,
                    municipiosPorEstado: MUNICIPIOS_POR_ESTADO,
                    ids: {
                        estado: { h: 'estado_id', b: 'estado-search-button', d: 'estado-search-dropdown', i: 'estado-search-input', r: 'estado-search-results' },
                        municipio: { h: 'municipio_id', b: 'municipio-search-button', d: 'municipio-search-dropdown', i: 'municipio-search-input', r: 'municipio-search-results' },
                        institucion: { h: 'institucion', b: 'institucion-search-button', d: 'institucion-search-dropdown', i: 'institucion-search-input', r: 'institucion-search-results' }
                    }
                });
            }
            inicializarBotonesTramite();
            inicializarInstitucionOtro();
            inicializarDropdownUsuario(); // Nuevo: inicializa el dropdown del usuario
            
            // Flatpickr para Fecha de Nacimiento (español, d/m/Y, máx. hoy)
            const inpFechaNac = document.getElementById('fecha_nacimiento');
            if (inpFechaNac && typeof flatpickr !== 'undefined') {
                flatpickrFechaNacimiento = flatpickr(inpFechaNac, {
                    locale: (flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default',
                    dateFormat: 'Y-m-d',
                    altFormat: 'd/m/Y',
                    altInput: true,
                    allowInput: false,
                    maxDate: 'today',
                    disableMobile: true,
                    onChange: function() {
                        if (fechaNacimientoEntradaProgrammatic) return;
                        quitarMarcaCamposDesdeCiudadanoEntrada();
                    }
                });
            }
            
            const subtramiteSelect = document.getElementById('subtramite_id');
            const requisitosWrapper = document.getElementById('requisitos-wrapper');
            subtramiteSelect?.addEventListener('change', function() {
                const val = this.value;
                if (!val) {
                    document.getElementById('tipo_tramite_id').value = '';
                    requisitosWrapper.classList.add('hidden');
                    validarTipoTramite();
                    return;
                }
                document.getElementById('tipo_tramite_id').value = val;
                validarTipoTramite();
                cargarRequisitosEntrada(val);
            });
            
            // Para escritorio, mostrar sidebar siempre
            if (window.innerWidth >= 1024) {
                document.getElementById('sidebar').classList.remove('mobile-hidden');
                document.getElementById('sidebar').classList.add('mobile-visible');
            }

            inicializarBusquedaCiudadanoPorCedulaEntrada();

            document.getElementById('btn-limpiar-formulario-entrada')?.addEventListener('click', function() {
                limpiarFormularioNuevaSolicitudEntrada();
            });
        });

        function inicializarInstitucionOtro() {
            const select = document.getElementById('institucion');
            const otroWrapper = document.getElementById('institucion-otro-wrapper');
            const otroInput = document.getElementById('institucion-otro');
            if (!select || !otroWrapper || !otroInput) return;
            const toggle = () => {
                if (select.value === 'otro') {
                    otroWrapper.classList.remove('hidden');
                    otroInput.focus();
                } else {
                    otroWrapper.classList.add('hidden');
                    otroInput.value = '';
                    const err = document.getElementById('error-institucion-otro');
                    if (err) err.classList.add('hidden');
                    otroInput.classList.remove('input-error', 'input-success');
                }
            };
            select.addEventListener('change', toggle);
            toggle();
        }
        
        function inicializarMenu() {
            // Menú móvil
            const menuBtn = document.getElementById('menu-btn');
            const menuCloseBtn = document.getElementById('menu-close-btn');
            const menuOverlay = document.getElementById('menu-overlay');
            
            menuBtn?.addEventListener('click', () => {
                sidebar.classList.remove('mobile-hidden');
                sidebar.classList.add('mobile-visible');
                menuOverlay.classList.add('active');
            });
            
            menuCloseBtn?.addEventListener('click', () => {
                sidebar.classList.remove('mobile-visible');
                sidebar.classList.add('mobile-hidden');
                menuOverlay.classList.remove('active');
            });
            
            menuOverlay?.addEventListener('click', () => {
                sidebar.classList.remove('mobile-visible');
                sidebar.classList.add('mobile-hidden');
                menuOverlay.classList.remove('active');
            });
            
            // Navegación entre secciones
            document.querySelectorAll('.menu-item').forEach(item => {
                item.addEventListener('click', function() {
                    const section = this.dataset.section;
                    
                    // Actualizar menú activo
                    document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Mostrar sección correspondiente
                    document.querySelectorAll('.section').forEach(sec => sec.classList.add('hidden'));
                    document.getElementById(`seccion-${section}`).classList.remove('hidden');
                    
                    // Actualizar título
                    const titles = {
                        'nueva-solicitud': 'Nueva Solicitud',
                        'mis-solicitudes': 'Mis Solicitudes',
                        'buscar-tramite': 'Buscar Trámite'
                    };
                    document.getElementById('section-title').textContent = titles[section];
                    
                    // Si vamos a la sección de búsqueda, verificar si hay un número pendiente
                    if (section === 'buscar-tramite') {
                        setTimeout(() => {
                            const input = document.getElementById('numero-seguimiento');
                            if (input) {
                                if (tramiteParaBuscar) {
                                    input.value = tramiteParaBuscar;
                                    input.focus();
                                    setTimeout(() => {
                                        buscarTramite();
                                    }, 200);
                                    tramiteParaBuscar = '';
                                } else {
                                    input.value = '';
                                    input.focus();
                                }
                            }
                            document.getElementById('resultado-busqueda').classList.add('hidden');
                            document.getElementById('sin-resultados').classList.remove('hidden');
                        }, 100);
                    }
                    
                    // Cerrar menú en móvil
                    if (window.innerWidth < 1024) {
                        sidebar.classList.remove('mobile-visible');
                        sidebar.classList.add('mobile-hidden');
                        menuOverlay.classList.remove('active');
                    }
                    
                    // Cargar contenido de la sección
                    if (section === 'mis-solicitudes') {
                        cargarMisSolicitudes();
                    }
                });
            });
        }
        
        // Inicializa el dropdown del usuario
        function inicializarDropdownUsuario() {
            const userBtn = document.getElementById('user-dropdown-btn');
            const dropdown = document.getElementById('dropdown-menu');
            
            if (userBtn && dropdown) {
                userBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    dropdown.classList.toggle('hidden');
                });
                
                document.addEventListener('click', function() {
                    dropdown.classList.add('hidden');
                });
            }
        }

        function cargarRequisitosEntrada(tramiteId) {
            const w = document.getElementById('requisitos-wrapper');
            const list = document.getElementById('requisitos-list');
            if (!w || !list) return;
            fetch(`ajax/obtener_requisitos.php?tramite_id=${tramiteId}`)
                .then(r => r.json())
                .then(d => {
                    list.innerHTML = '';
                    if (d.success && d.requisitos && d.requisitos.length) {
                        d.requisitos.forEach(r => {
                            const nombre = (r.nombre || '').trim();
                            const esAsesoria = /^asesor[ií]a$/i.test(nombre);
                            const label = document.createElement('label');
                            label.className = 'flex items-start gap-3 text-sm text-gray-800 cursor-pointer leading-snug border border-gray-100 rounded-lg px-3 py-2.5 bg-gray-50/80 hover:bg-gray-50 transition-colors';
                            label.setAttribute('data-req-id', String(r.id));
                            const cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.className = 'req-item-entrada mt-0.5 w-4 h-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500';
                            cb.dataset.id = String(r.id);
                            cb.dataset.name = nombre;
                            cb.dataset.asesoria = esAsesoria ? '1' : '0';
                            const span = document.createElement('span');
                            span.className = 'flex-1';
                            span.textContent = nombre;
                            label.appendChild(cb);
                            label.appendChild(span);
                            list.appendChild(label);
                        });
                        inicializarReglaRequisitosEntrada(list);
                        w.classList.remove('hidden');
                    } else {
                        w.classList.add('hidden');
                    }
                })
                .catch(() => { list.innerHTML = ''; w.classList.add('hidden'); });
        }

        function inicializarReglaRequisitosEntrada(container) {
            const inputs = Array.from(container.querySelectorAll('input.req-item-entrada'));
            if (!inputs.length) return;
            const asesoria = inputs.find(i => i.dataset.asesoria === '1' || /^asesor[ií]a$/i.test((i.dataset.name || '').trim()));
            const otros = asesoria ? inputs.filter(i => i !== asesoria) : inputs;

            const aplicarEstiloDeshabilitado = (input, deshabilitado) => {
                const label = input.closest('label');
                if (!label) return;
                if (deshabilitado) {
                    label.classList.add('opacity-50', 'grayscale', 'cursor-not-allowed', 'pointer-events-none');
                    label.classList.remove('hover:bg-gray-50', 'cursor-pointer');
                } else {
                    label.classList.remove('opacity-50', 'grayscale', 'cursor-not-allowed', 'pointer-events-none');
                    label.classList.add('hover:bg-gray-50', 'cursor-pointer');
                }
            };

            const marcarTodosNoAsesoria = () => {
                otros.forEach(i => {
                    i.disabled = false;
                    i.checked = true;
                    aplicarEstiloDeshabilitado(i, false);
                });
            };

            const alMarcarAsesoria = () => {
                otros.forEach(i => {
                    i.checked = false;
                    i.disabled = true;
                    aplicarEstiloDeshabilitado(i, true);
                });
            };

            if (asesoria) {
                asesoria.checked = false;
            }
            marcarTodosNoAsesoria();

            if (asesoria) {
                asesoria.addEventListener('change', () => {
                    if (asesoria.checked) {
                        alMarcarAsesoria();
                    } else {
                        marcarTodosNoAsesoria();
                    }
                });
            }
        }

        function obtenerRequisitosMarcadosEntrada() {
            return Array.from(document.querySelectorAll('#requisitos-list input.req-item-entrada:checked'))
                .map(i => ({
                    id: parseInt(i.dataset.id, 10),
                    nombre: (i.dataset.name || '').trim()
                }))
                .filter(x => !isNaN(x.id) && x.id > 0);
        }

        function obtenerNombresRequisitosMarcadosEntrada() {
            return obtenerRequisitosMarcadosEntrada().map(x => x.nombre).filter(Boolean);
        }

        function requisitosInmediatosVisiblesEntrada() {
            const w = document.getElementById('requisitos-wrapper');
            return w && !w.classList.contains('hidden') && document.querySelectorAll('#requisitos-list input.req-item-entrada').length > 0;
        }

        function validarRequisitosTramiteInmediatoEntrada() {
            if (!requisitosInmediatosVisiblesEntrada()) {
                return true;
            }
            const marcados = obtenerRequisitosMarcadosEntrada();
            if (marcados.length < 1) {
                alert('Debe marcar al menos un requisito entregado o Asesoría para realizar el trámite inmediato');
                const list = document.getElementById('requisitos-list');
                list?.classList.add('ring-2', 'ring-amber-400', 'rounded-lg', 'p-1');
                setTimeout(() => list?.classList.remove('ring-2', 'ring-amber-400', 'rounded-lg', 'p-1'), 2500);
                return false;
            }
            return true;
        }
        
        function inicializarBotonesTramite() {
            document.getElementById('btn-tramite-inmediato')?.addEventListener('click', async function(e) {
                e.preventDefault();
                tipoSolicitudActual = 'inmediato';
                document.getElementById('tipo_solicitud').value = 'inmediato';
                if (await validarFormulario()) {
                    if (!validarRequisitosTramiteInmediatoEntrada()) {
                        return;
                    }
                    mostrarModalConfirmacionCompletado();
                }
            });
            
            document.getElementById('cancelCompletadoBtn')?.addEventListener('click', function() {
                document.getElementById('confirmCompletadoModal').classList.remove('active');
            });
            
            document.getElementById('confirmCompletadoBtn')?.addEventListener('click', function() {
                document.getElementById('confirmCompletadoModal').classList.remove('active');
                enviarFormulario('inmediato');
            });
        }
        
        function inicializarCustomSelectGenero() {
            const select = document.getElementById('genero');
            const button = document.getElementById('custom-genero-button');
            const dropdown = document.getElementById('custom-genero-dropdown');
            const options = dropdown.querySelectorAll('.custom-select-option');
            const selectedIcon = document.getElementById('selected-icon');
            const selectedText = document.getElementById('selected-text');
            
            function updateButton(value, text) {
                if (value === '') {
                    selectedIcon.className = 'selected-icon text-gray-400';
                    selectedIcon.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-gender-bigender"><path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M7 11a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                            <path d="M19 3l-5 5" />
                            <path d="M15 3h4v4" />
                            <path d="M11 16v6" /><path d="M8 19h6" />
                        </svg>
                    `;
                    selectedText.textContent = 'Seleccione un género';
                } else if (value === 'masculino') {
                    selectedIcon.className = 'selected-icon text-blue-500';
                    selectedIcon.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-man">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M10 16v5" /><path d="M14 16v5" />
                            <path d="M9 9h6l-1 7h-4l-1 -7" />
                            <path d="M5 11c1.333 -1.333 2.667 -2 4 -2" />
                            <path d="M19 11c-1.333 -1.333 -2.667 -2 -4 -2" />
                            <path d="M10 4a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                        </svg>
                    `;
                    selectedText.textContent = text;
                    selectedText.className = ''; 
                } else if (value === 'femenino') {
                    selectedIcon.className = 'selected-icon text-pink-500';
                    selectedIcon.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-woman">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M10 16v5" /><path d="M14 16v5" />
                        <path d="M8 16h8l-2 -7h-4l-2 7" />
                        <path d="M5 11c1.667 -1.333 3.333 -2 5 -2" />
                        <path d="M19 11c-1.667 -1.333 -3.333 -2 -5 -2" />
                        <path d="M10 4a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                    </svg>
                    `;
                    selectedText.textContent = text;
                    selectedText.className = '';
                }
            }
            
            const initialValue = select.value;
            const initialOption = select.querySelector(`option[value="${initialValue}"]`);
            if (initialOption) {
                updateButton(initialValue, initialOption.textContent);
            }
            
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = dropdown.classList.contains('open');
                
                document.querySelectorAll('.custom-select-dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });
                document.querySelectorAll('.custom-select-button.open').forEach(b => {
                    b.classList.remove('open');
                });
                
                if (!isOpen) {
                    dropdown.classList.add('open');
                    button.classList.add('open');
                }
            });
            
            options.forEach(option => {
                option.addEventListener('click', function() {
                    const value = this.getAttribute('data-value');
                    const text = this.querySelector('span').textContent;
                    
                    select.value = value;
                    select.dispatchEvent(new Event('change'));
                    
                    updateButton(value, text);
                    
                    options.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    
                    dropdown.classList.remove('open');
                    button.classList.remove('open');
                    
                    validarGenero(select);
                });
            });
            
            document.addEventListener('click', function() {
                dropdown.classList.remove('open');
                button.classList.remove('open');
            });
            
            dropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            
            select.addEventListener('change', function() {
                const value = this.value;
                const option = this.querySelector(`option[value="${value}"]`);
                if (option) {
                    updateButton(value, option.textContent);
                    
                    options.forEach(opt => {
                        if (opt.getAttribute('data-value') === value) {
                            opt.classList.add('selected');
                        } else {
                            opt.classList.remove('selected');
                        }
                    });
                }
            });
        }
        
        function inicializarTramiteSearch() {
            const button = document.getElementById('tramite-search-button');
            const dropdown = document.getElementById('tramite-search-dropdown');
            const searchInput = document.getElementById('tramite-search-input');
            const resultsContainer = document.getElementById('tramite-search-results');
            
            if (!button || !dropdown || !searchInput || !resultsContainer) return;
            
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = dropdown.classList.contains('open');
                
                document.querySelectorAll('.tramite-search-dropdown.open').forEach(d => {
                    d.classList.remove('open');
                });
                document.querySelectorAll('.tramite-search-button.open').forEach(b => {
                    b.classList.remove('open');
                });
                
                if (!isOpen) {
                    dropdown.classList.add('open');
                    button.classList.add('open');
                    
                    setTimeout(() => {
                        searchInput.focus();
                        searchInput.select();
                    }, 100);
                }
            });
            
            searchInput.addEventListener('input', function() {
                clearTimeout(tramiteSearchDebounceTimer);
                tramiteSearchDebounceTimer = setTimeout(() => {
                    buscarTramites(this.value.trim());
                }, 300);
            });
            
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    buscarTramites(this.value.trim());
                }
            });
            
            document.addEventListener('click', function(e) {
                if (!button.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.remove('open');
                    button.classList.remove('open');
                }
            });
            
            dropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            
            function buscarTramites(query) {
                if (!fuseInstance || currentTramiteList.length === 0) {
                    mostrarNoResults();
                    return;
                }
                
                if (!query || query.trim() === '') {
                    mostrarTramiteOptions(currentTramiteList);
                    return;
                }
                
                const resultados = fuseInstance.search(query);
                
                if (resultados.length > 0) {
                    const itemsFiltrados = resultados.map(result => result.item);
                    mostrarTramiteOptions(itemsFiltrados, query);
                } else {
                    mostrarNoResults();
                }
            }
            
            function mostrarTramiteOptions(tramites, searchQuery = '') {
                if (tramites.length === 0) {
                    mostrarNoResults();
                    return;
                }
                
                let optionsHtml = '';
                
                tramites.forEach(tramite => {
                    let nombreDisplay = tramite.nombre;
                    
                    if (searchQuery) {
                        const regex = new RegExp(`(${escapeRegExp(searchQuery)})`, 'gi');
                        nombreDisplay = nombreDisplay.replace(regex, '<span class="search-highlight">$1</span>');
                    }
                    
                    optionsHtml += `
                        <div class="tramite-search-option" data-value="${tramite.id}" data-nombre="${tramite.nombre}">
                            <span>${nombreDisplay}</span>
                        </div>
                    `;
                });
                
                resultsContainer.innerHTML = optionsHtml;
                
                resultsContainer.querySelectorAll('.tramite-search-option').forEach(option => {
                    option.addEventListener('click', function() {
                        seleccionarTramiteOption(this);
                    });
                });
            }
            
            function mostrarNoResults() {
                resultsContainer.innerHTML = `
                    <div class="no-results-message">
                        <i class="fas fa-search mb-2"></i>
                        <p>No se encontraron trámites que coincidan con la búsqueda</p>
                    </div>
                `;
            }
            
            function escapeRegExp(string) {
                return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }
            
            window.seleccionarTramiteOption = function(optionElement) {
                const value = optionElement.getAttribute('data-value');
                const nombre = optionElement.getAttribute('data-nombre');
                
                resultsContainer.querySelectorAll('.tramite-search-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                optionElement.classList.add('selected');
                
                const selectedText = button.querySelector('.selected-tramite-text');
                selectedText.textContent = nombre;
                selectedText.className = 'selected-tramite-text';
                
                document.getElementById('tipo_tramite_id').value = value;
                
                dropdown.classList.remove('open');
                button.classList.remove('open');
                
                searchInput.value = '';
                
                validarTipoTramite();
                
                const subtramiteWrapper = document.getElementById('subtramite-wrapper');
                const subtramiteSelect = document.getElementById('subtramite_id');
                const requisitosWrapper = document.getElementById('requisitos-wrapper');
                
                fetch(`ajax/obtener_subtramites.php?padre_id=${value}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && Array.isArray(data.subtramites) && data.subtramites.length > 0) {
                            subtramiteSelect.innerHTML = '<option value="">Seleccione una variante</option>' + data.subtramites.map(st => `<option value="${st.id}">${st.nombre}</option>`).join('');
                            subtramiteWrapper.classList.remove('hidden');
                            document.getElementById('tipo_tramite_id').value = '';
                            validarTipoTramite();
                            requisitosWrapper.classList.add('hidden');
                        } else {
                            subtramiteWrapper.classList.add('hidden');
                            subtramiteSelect.value = '';
                            document.getElementById('tipo_tramite_id').value = value;
                            cargarRequisitosEntrada(value);
                        }
                    })
                    .catch(() => {
                        subtramiteWrapper.classList.add('hidden');
                        document.getElementById('tipo_tramite_id').value = value;
                        cargarRequisitosEntrada(value);
                    });
                
                const tipoSeleccionado = currentTramiteList.find(t => t.id == value);
                
                const camposDinamicos = document.getElementById('campos-dinamicos');
                if (tipoSeleccionado && tipoSeleccionado.config && tipoSeleccionado.config.campos && tipoSeleccionado.config.campos.length > 0) {
                    generarCamposDinamicos(tipoSeleccionado.config.campos);
                    camposDinamicos.classList.remove('hidden');
                } else {
                    camposDinamicos.classList.add('hidden');
                }
            };
        }
        
        function inicializarValidaciones() {
            document.getElementById('tramitante-form')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                tipoSolicitudActual = 'normal';
                document.getElementById('tipo_solicitud').value = 'normal';
                if (await validarFormulario()) {
                    mostrarModalConfirmacion();
                }
            });
            
            document.getElementById('estado_id')?.addEventListener('change', function() {
                this.classList.remove('campos-desde-ciudadano');
                document.getElementById('municipio_id')?.classList.remove('campos-desde-ciudadano');
                validarEstado(this);
                populateMunicipios(this.value);
                setTimeout(() => actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada(), 80);
            });
            document.getElementById('municipio_id')?.addEventListener('change', function() {
                this.classList.remove('campos-desde-ciudadano');
                validarMunicipio(this);
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada();
            });
            document.getElementById('ciudadano_email')?.addEventListener('input', actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada);
            document.getElementById('direccion')?.addEventListener('input', actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada);
        }
        
        function inicializarFiltros() {
            document.getElementById('btn-aplicar-filtros')?.addEventListener('click', cargarMisSolicitudes);
            
            const btnResetFiltros = document.getElementById('btn-reset-filtros');
            if (btnResetFiltros) {
                btnResetFiltros.addEventListener('click', function() {
                    document.getElementById('filtro-cedula').value = '';
                    document.getElementById('filtro-area').value = '';
                    document.getElementById('filtro-estado').value = '';
                    document.getElementById('filtro-tipo-tramite').value = '';
                    document.getElementById('filtro-institucion').value = '';
                    document.getElementById('filtro-fecha-desde').value = '';
                    document.getElementById('filtro-fecha-hasta').value = '';
                    cargarMisSolicitudes();
                });
            }
            
            const btnRefresh = document.getElementById('btn-refresh');
            if (btnRefresh) {
                btnRefresh.addEventListener('click', cargarMisSolicitudes);
            }
            
            const filtroTipoTramite = document.getElementById('filtro-tipo-tramite');
            const filtroSubWrapper = document.getElementById('filtro-subtramite-wrapper');
            const filtroSubSelect = document.getElementById('filtro-subtramite');
            
            filtroTipoTramite?.addEventListener('change', function() {
                const padreId = this.value;
                if (!padreId) {
                    if (filtroSubSelect) {
                        filtroSubSelect.innerHTML = '<option value="">Todas las variantes</option>';
                        filtroSubSelect.value = '';
                    }
                    filtroSubWrapper?.classList.add('hidden');
                    return;
                }
                fetch(`ajax/obtener_subtramites.php?padre_id=${padreId}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && Array.isArray(data.subtramites) && data.subtramites.length > 0) {
                            const options = ['<option value="">Todas las variantes</option>'].concat(
                                data.subtramites.map(st => `<option value="${st.id}">${st.nombre}</option>`)
                            ).join('');
                            if (filtroSubSelect) {
                                filtroSubSelect.innerHTML = options;
                            }
                            filtroSubWrapper?.classList.remove('hidden');
                        } else {
                            if (filtroSubSelect) {
                                filtroSubSelect.innerHTML = '<option value="">Todas las variantes</option>';
                                filtroSubSelect.value = '';
                            }
                            filtroSubWrapper?.classList.add('hidden');
                        }
                    })
                    .catch(() => {
                        if (filtroSubSelect) {
                            filtroSubSelect.innerHTML = '<option value="">Todas las variantes</option>';
                            filtroSubSelect.value = '';
                        }
                        filtroSubWrapper?.classList.add('hidden');
                    });
            });
        }
        
        function inicializarBusqueda() {
            document.getElementById('btn-buscar-tramite')?.addEventListener('click', buscarTramite);
            
            document.getElementById('numero-seguimiento')?.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    buscarTramite();
                }
            });
        }
        
        function populateMunicipios(estadoId) {
            if (typeof window.cneNuevaSolicitudCombosRefrescarMunicipios === 'function') {
                window.cneNuevaSolicitudCombosRefrescarMunicipios(estadoId);
            }
        }
        
        // Funciones de validación (se mantienen igual que en el original)
        function validarNombre(input) {
            const errorDiv = document.getElementById('error-nombres');
            const valor = input.value.trim();
            if (!valor) {
                mostrarExito(input, errorDiv);
                return true;
            }
            if (valor.length < 2) {
                mostrarError(input, errorDiv, 'El nombre debe tener al menos 2 caracteres');
                return false;
            }
            mostrarExito(input, errorDiv);
            return true;
        }
        
        function validarApellido(input) {
            const errorDiv = document.getElementById('error-apellidos');
            const valor = input.value.trim();
            if (!valor) {
                mostrarExito(input, errorDiv);
                return true;
            }
            if (valor.length < 2) {
                mostrarError(input, errorDiv, 'El apellido debe tener al menos 2 caracteres');
                return false;
            }
            mostrarExito(input, errorDiv);
            return true;
        }
        
        function validarCedula(input) {
            const errorDiv = document.getElementById('error-cedula');
            const valor = input.value.trim();
            if (!valor) {
                mostrarExito(input, errorDiv);
                return true;
            }
            if (!/^\d+$/.test(valor)) {
                mostrarError(input, errorDiv, 'Solo se permiten números');
                return false;
            }
            if (valor.length > 8) {
                mostrarError(input, errorDiv, 'Máximo 8 dígitos');
                return false;
            }
            if (valor.length > 0 && valor.length < 8) {
                mostrarExito(input, errorDiv);
                return true;
            }
            mostrarExito(input, errorDiv);
            return true;
        }
        
        function validarTelefono(input) {
            const errorDiv = document.getElementById('error-telefono');
            const valor = input.value.trim();
            if (!valor) {
                mostrarExito(input, errorDiv);
                return true;
            }
            if (!/^\d+$/.test(valor)) {
                mostrarError(input, errorDiv, 'Solo se permiten números');
                return false;
            }
            if (valor.length !== 7) {
                mostrarError(input, errorDiv, 'El teléfono debe tener 7 dígitos');
                return false;
            }
            mostrarExito(input, errorDiv);
            return true;
        }
        
        function cneAlertaGeneroObligatorioEntrada() {
            const g = document.getElementById('genero');
            const v = g ? String(g.value).trim().toLowerCase() : '';
            if (v !== 'masculino' && v !== 'femenino') {
                alert('Debe seleccionar género (Masculino o Femenino).');
                return false;
            }
            return true;
        }

        function validarGenero(select) {
            const errorDiv = document.getElementById('error-genero');
            const btn = document.getElementById('custom-genero-button');
            const valor = String(select.value || '').trim().toLowerCase();
            if (valor !== 'masculino' && valor !== 'femenino') {
                mostrarError(select, errorDiv, 'Debe seleccionar género (Masculino o Femenino)');
                if (btn) {
                    btn.classList.remove('input-success', 'campos-desde-ciudadano');
                    btn.classList.add('input-error');
                }
                return false;
            }
            mostrarExito(select, errorDiv);
            if (btn) {
                btn.classList.remove('input-error');
                btn.classList.add('input-success');
                btn.classList.remove('campos-desde-ciudadano');
            }
            return true;
        }
        
        function validarInstitucion(select) {
            const errorDiv = document.getElementById('error-institucion');
            const visual = document.getElementById('institucion-search-button');
            const valor = select.value;
            if (!valor) {
                mostrarError(visual || select, errorDiv, 'La institución es obligatoria');
                return false;
            }
            if (valor === 'otro') {
                const otroInput = document.getElementById('institucion-otro');
                const otroError = document.getElementById('error-institucion-otro');
                const nombre = (otroInput?.value || '').trim();
                if (!nombre) {
                    mostrarError(otroInput, otroError, 'Debe ingresar el nombre de la institución');
                    return false;
                }
                mostrarExito(otroInput, otroError);
            }
            mostrarExito(visual || select, errorDiv);
            return true;
        }
        
        function validarArea(select) {
            const errorDiv = document.getElementById('error-area');
            const valor = select.value;
            if (!valor) {
                mostrarError(select, errorDiv, 'La coordinación es obligatoria');
                return false;
            }
            mostrarExito(select, errorDiv);
            return true;
        }
        
        function validarEstado(select) {
            const errorDiv = document.getElementById('error-estado');
            const visual = document.getElementById('estado-search-button');
            if (!errorDiv || !select) return true;
            if (!select.value) {
                if (visual) visual.classList.remove('input-error');
                else select.classList.remove('input-error');
                errorDiv.classList.add('hidden');
                return true;
            }
            mostrarExito(visual || select, errorDiv);
            return true;
        }
        
        function validarMunicipio(select) {
            const errorDiv = document.getElementById('error-municipio');
            const visual = document.getElementById('municipio-search-button');
            if (!errorDiv || !select) return true;
            mostrarExito(visual || select, errorDiv);
            return true;
        }
        
        function validarTipoTramite() {
            const errorDiv = document.getElementById('error-tipo-tramite');
            const hiddenVal = document.getElementById('tipo_tramite_id').value;
            const subWrapper = document.getElementById('subtramite-wrapper');
            const subSelect = document.getElementById('subtramite_id');
            const isSubVisible = subWrapper && !subWrapper.classList.contains('hidden');
            const subVal = subSelect ? subSelect.value : '';
            const valido = isSubVisible ? !!subVal : !!hiddenVal;
            if (!valido) {
                errorDiv.classList.remove('hidden');
                return false;
            }
            errorDiv.classList.add('hidden');
            return true;
        }
        
        function cneEsValorNATexto(v) {
            const s = String(v == null ? '' : v).trim();
            return s === '' || s.toUpperCase() === 'N/A';
        }
        function cneIdentificacionEsTemporalCNE(id) {
            return /^V-CNE\d+$/i.test(String(id || '').trim());
        }
        function cneFkIdUbicacionEsNAClient(v) {
            if (v === null || v === undefined) return true;
            const s = String(v).trim();
            return s === '' || s === '0';
        }
        function quitarProteccionUbicacionCiudadanoEntrada() {
            const clsProt = ['ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'campos-desde-ciudadano', 'input-success'];
            const est = document.getElementById('estado_id');
            const mun = document.getElementById('municipio_id');
            const estBtn = document.getElementById('estado-search-button');
            const munBtn = document.getElementById('municipio-search-button');
            const munInp = document.getElementById('municipio-search-input');
            const dir = document.getElementById('direccion');
            [est, mun].forEach(el => {
                if (!el) return;
                el.removeAttribute('disabled');
                el.classList.remove(...clsProt, 'pointer-events-none');
            });
            [estBtn, munBtn].forEach(btn => {
                if (!btn) return;
                btn.removeAttribute('disabled');
                btn.classList.remove(...clsProt, 'pointer-events-none');
            });
            if (munInp) munInp.removeAttribute('disabled');
            if (dir) {
                dir.removeAttribute('readonly');
                dir.classList.remove(...clsProt);
            }
        }
        function aplicarProteccionUbicacionCiudadanoEntrada(d) {
            quitarProteccionUbicacionCiudadanoEntrada();
            if (!d) return;
            const marcaProt = 'ciudadano-campo-protegido';
            const marcaNa = 'ciudadano-campo-na-editable';
            const fkReal = (v) => {
                if (v === null || v === undefined || v === '') return false;
                const n = parseInt(String(v), 10);
                return !isNaN(n) && n > 0;
            };
            const estReal = fkReal(d.estado_id);
            const munReal = fkReal(d.municipio_id);
            const estEl = document.getElementById('estado_id');
            const munEl = document.getElementById('municipio_id');
            const estBtn = document.getElementById('estado-search-button');
            const munBtn = document.getElementById('municipio-search-button');
            const munInp = document.getElementById('municipio-search-input');
            const dirEl = document.getElementById('direccion');
            if (estEl) {
                estEl.classList.remove(marcaProt, marcaNa, 'pointer-events-none', 'input-error');
                if (estReal) {
                    estEl.setAttribute('disabled', 'disabled');
                    estEl.classList.add(marcaProt, 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
                } else {
                    estEl.classList.add(marcaNa);
                }
            }
            if (estBtn) {
                estBtn.classList.remove(marcaProt, marcaNa, 'pointer-events-none', 'input-error');
                if (estReal) {
                    estBtn.setAttribute('disabled', 'disabled');
                    estBtn.classList.add(marcaProt, 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
                } else {
                    estBtn.removeAttribute('disabled');
                    estBtn.classList.add(marcaNa);
                }
            }
            if (munEl) {
                munEl.classList.remove(marcaProt, marcaNa, 'pointer-events-none', 'input-error');
                if (munReal) {
                    munEl.setAttribute('disabled', 'disabled');
                    munEl.classList.add(marcaProt, 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
                } else {
                    munEl.removeAttribute('disabled');
                    munEl.classList.add(marcaNa);
                }
            }
            if (munBtn) {
                munBtn.classList.remove(marcaProt, marcaNa, 'pointer-events-none', 'input-error');
                if (munReal) {
                    munBtn.setAttribute('disabled', 'disabled');
                    munBtn.classList.add(marcaProt, 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
                } else {
                    munBtn.removeAttribute('disabled');
                    munBtn.classList.add(marcaNa);
                }
            }
            if (munInp) {
                if (munReal) {
                    munInp.setAttribute('disabled', 'disabled');
                } else if (!munEl || !munEl.disabled) {
                    munInp.removeAttribute('disabled');
                }
            }
            if (dirEl) {
                dirEl.classList.remove(marcaProt, marcaNa, 'campos-desde-ciudadano', 'input-error', 'input-success');
                if (!cneEsValorNATexto(d.ciudadano_direccion)) {
                    dirEl.setAttribute('readonly', 'readonly');
                    dirEl.classList.add(marcaProt, 'campos-desde-ciudadano', 'input-success');
                } else {
                    dirEl.classList.add(marcaNa);
                }
            }
        }
        function quitarProteccionIdentidadCiudadanoEntrada() {
            quitarProteccionUbicacionCiudadanoEntrada();
            const clsProt = ['ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'campos-desde-ciudadano', 'input-success'];
            const limpiarEl = (el) => {
                if (!el) return;
                el.removeAttribute('readonly');
                el.classList.remove(...clsProt, 'pointer-events-none');
            };
            limpiarEl(document.getElementById('cedula-tipo'));
            limpiarEl(document.getElementById('cedula-numero'));
            limpiarEl(document.getElementById('nombres'));
            limpiarEl(document.getElementById('apellidos'));
            limpiarEl(document.getElementById('telefono-codigo'));
            limpiarEl(document.getElementById('telefono-numero'));
            limpiarEl(document.getElementById('fecha_nacimiento'));
            if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                limpiarEl(flatpickrFechaNacimiento.altInput);
            }
            const genBtn = document.getElementById('custom-genero-button');
            if (genBtn) {
                genBtn.classList.remove('ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
            }
        }
        function aplicarProteccionIdentidadCiudadanoEntrada(data) {
            quitarProteccionIdentidadCiudadanoEntrada();
            if (!data) return;
            const idFull = data.ciudadano_identificacion || ((document.getElementById('cedula-tipo')?.value || 'V') + '-' + (document.getElementById('cedula-numero')?.value || '').trim().replace(/[\s.\-]/g, ''));
            const marcaProt = 'ciudadano-campo-protegido';
            const marcaNa = 'ciudadano-campo-na-editable';
            const tipoEl = document.getElementById('cedula-tipo');
            const numEl = document.getElementById('cedula-numero');
            if (tipoEl && numEl && idFull) {
                tipoEl.classList.remove(marcaProt, marcaNa, 'pointer-events-none');
                numEl.classList.remove(marcaProt, marcaNa);
                numEl.removeAttribute('readonly');
                if (cneIdentificacionEsTemporalCNE(idFull)) {
                    tipoEl.removeAttribute('readonly');
                } else {
                    tipoEl.classList.add(marcaProt, 'pointer-events-none');
                    numEl.setAttribute('readonly', 'readonly');
                    numEl.classList.add(marcaProt);
                }
            }
            const setCampoTexto = (id, dbVal) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.remove(marcaProt, marcaNa, 'campos-desde-ciudadano', 'input-error', 'input-success');
                if (cneEsValorNATexto(dbVal)) {
                    el.removeAttribute('readonly');
                    el.classList.add(marcaNa);
                } else {
                    el.setAttribute('readonly', 'readonly');
                    el.classList.add(marcaProt);
                }
            };
            setCampoTexto('nombres', data.ciudadano_nombres);
            setCampoTexto('apellidos', data.ciudadano_apellidos);
            const telNA = cneEsValorNATexto(data.ciudadano_telefono);
            ['telefono-codigo', 'telefono-numero'].forEach(tid => {
                const el = document.getElementById(tid);
                if (!el) return;
                el.classList.remove(marcaProt, marcaNa, 'campos-desde-ciudadano', 'input-success');
                if (telNA) {
                    el.removeAttribute('readonly');
                    el.classList.add(marcaNa);
                } else {
                    el.setAttribute('readonly', 'readonly');
                    el.classList.add(marcaProt);
                }
            });
            const fechaNA = cneEsValorNATexto(data.ciudadano_fecha_nacimiento);
            const finp = document.getElementById('fecha_nacimiento');
            if (finp) {
                finp.classList.remove(marcaProt, marcaNa, 'campos-desde-ciudadano', 'input-success');
                if (fechaNA) {
                    finp.removeAttribute('readonly');
                    finp.classList.add(marcaNa);
                    if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                        flatpickrFechaNacimiento.altInput.removeAttribute('readonly');
                        flatpickrFechaNacimiento.altInput.classList.remove(marcaProt);
                        flatpickrFechaNacimiento.altInput.classList.add(marcaNa);
                    }
                } else {
                    finp.setAttribute('readonly', 'readonly');
                    finp.classList.add(marcaProt);
                    if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                        flatpickrFechaNacimiento.altInput.setAttribute('readonly', 'readonly');
                        flatpickrFechaNacimiento.altInput.classList.add(marcaProt);
                        flatpickrFechaNacimiento.altInput.classList.remove(marcaNa);
                    }
                }
            }
            const genNA = cneEsValorNATexto(data.ciudadano_genero);
            const genBtn = document.getElementById('custom-genero-button');
            if (genBtn) {
                genBtn.classList.remove(marcaProt, marcaNa, 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
                if (genNA) {
                    genBtn.classList.add(marcaNa);
                } else {
                    genBtn.classList.add(marcaProt, 'pointer-events-none');
                }
            }
            aplicarProteccionUbicacionCiudadanoEntrada(data);
        }

        function quitarMarcaCamposDesdeCiudadanoEntrada() {
            document.getElementById('fecha_nacimiento')?.classList.remove('campos-desde-ciudadano');
            if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                flatpickrFechaNacimiento.altInput.classList.remove('campos-desde-ciudadano');
            }
            document.getElementById('custom-genero-button')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('estado_id')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('municipio_id')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('estado-search-button')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('municipio-search-button')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('ciudadano_email')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('direccion')?.classList.remove('campos-desde-ciudadano');
        }

        function aplicarMarcaCamposDesdeCiudadanoEntrada(data) {
            const marca = 'campos-desde-ciudadano';
            if (data && data.ciudadano_fecha_nacimiento && !cneEsValorNATexto(data.ciudadano_fecha_nacimiento)) {
                const finp = document.getElementById('fecha_nacimiento');
                if (finp) {
                    finp.classList.add(marca, 'input-success');
                    finp.classList.remove('input-error');
                }
                if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                    flatpickrFechaNacimiento.altInput.classList.add(marca, 'input-success');
                    flatpickrFechaNacimiento.altInput.classList.remove('input-error');
                }
            }
            if (data && data.ciudadano_genero && !cneEsValorNATexto(data.ciudadano_genero)) {
                const btn = document.getElementById('custom-genero-button');
                if (btn) {
                    btn.classList.add(marca, 'input-success');
                    btn.classList.remove('input-error');
                }
            }
            const eidMarca = data && data.estado_id != null ? parseInt(String(data.estado_id), 10) : 0;
            if (data && !isNaN(eidMarca) && eidMarca > 0) {
                const est = document.getElementById('estado_id');
                const estB = document.getElementById('estado-search-button');
                if (est) {
                    est.classList.add(marca, 'input-success');
                    est.classList.remove('input-error');
                }
                if (estB) {
                    estB.classList.add(marca, 'input-success');
                    estB.classList.remove('input-error');
                }
            }
            const midMarca = data && data.municipio_id != null ? parseInt(String(data.municipio_id), 10) : 0;
            if (data && !isNaN(midMarca) && midMarca > 0) {
                const mun = document.getElementById('municipio_id');
                const munB = document.getElementById('municipio-search-button');
                if (mun) {
                    mun.classList.add(marca, 'input-success');
                    mun.classList.remove('input-error');
                }
                if (munB) {
                    munB.classList.add(marca, 'input-success');
                    munB.classList.remove('input-error');
                }
            }
            const emailRaw = data && data.ciudadano_email != null ? String(data.ciudadano_email).trim() : '';
            if (emailRaw !== '') {
                const em = document.getElementById('ciudadano_email');
                if (em) {
                    em.classList.add(marca, 'input-success');
                    em.classList.remove('input-error', 'ciudadano-dato-alterado');
                }
            }
            const dirRaw = data && data.ciudadano_direccion != null ? String(data.ciudadano_direccion).trim() : '';
            if (dirRaw !== '' && !cneEsValorNATexto(dirRaw)) {
                const ta = document.getElementById('direccion');
                if (ta) {
                    ta.classList.add(marca, 'input-success');
                    ta.classList.remove('input-error', 'ciudadano-dato-alterado');
                }
            }
        }

        function guardarSnapshotOpcionalesCiudadanoEntrada(data) {
            if (!data) {
                snapshotDatosOpcionalesCiudadanoEntrada = null;
                return;
            }
            const eSnap = data.estado_id != null ? parseInt(String(data.estado_id), 10) : 0;
            const mSnap = data.municipio_id != null ? parseInt(String(data.municipio_id), 10) : 0;
            snapshotDatosOpcionalesCiudadanoEntrada = {
                email: (data.ciudadano_email != null ? String(data.ciudadano_email) : '').trim(),
                direccion: cneEsValorNATexto(data.ciudadano_direccion) ? '' : String(data.ciudadano_direccion != null ? data.ciudadano_direccion : '').trim(),
                estado_id: !isNaN(eSnap) && eSnap > 0 ? String(eSnap) : '',
                municipio_id: !isNaN(mSnap) && mSnap > 0 ? String(mSnap) : ''
            };
        }

        function actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada() {
            const clsAlt = 'ciudadano-dato-alterado';
            const marca = 'campos-desde-ciudadano';
            const emailEl = document.getElementById('ciudadano_email');
            const dirEl = document.getElementById('direccion');
            const estEl = document.getElementById('estado_id');
            const munEl = document.getElementById('municipio_id');
            const estBtn = document.getElementById('estado-search-button');
            const munBtn = document.getElementById('municipio-search-button');
            [emailEl, dirEl, estEl, munEl, estBtn, munBtn].forEach(el => {
                if (el) el.classList.remove(clsAlt);
            });
            const snap = snapshotDatosOpcionalesCiudadanoEntrada;
            if (!snap) return;

            const curE = (emailEl?.value || '').trim();
            if (curE !== snap.email) {
                emailEl?.classList.add(clsAlt);
                emailEl?.classList.remove(marca, 'input-success', 'input-error');
            } else if (snap.email !== '') {
                emailEl?.classList.add(marca, 'input-success');
                emailEl?.classList.remove('input-error');
            } else {
                emailEl?.classList.remove(marca, 'input-success');
            }

            const curD = (dirEl?.value || '').trim();
            if (curD !== snap.direccion) {
                dirEl?.classList.add(clsAlt);
                dirEl?.classList.remove(marca, 'input-success', 'input-error');
            } else if (snap.direccion !== '') {
                dirEl?.classList.add(marca, 'input-success');
                dirEl?.classList.remove('input-error');
            } else {
                dirEl?.classList.remove(marca, 'input-success');
            }

            const curEst = estEl ? String(estEl.value || '') : '';
            if (curEst !== snap.estado_id) {
                estEl?.classList.add(clsAlt);
                estEl?.classList.remove(marca, 'input-success', 'input-error');
                estBtn?.classList.add(clsAlt);
                estBtn?.classList.remove(marca, 'input-success', 'input-error');
            } else if (snap.estado_id !== '') {
                estEl?.classList.add(marca, 'input-success');
                estEl?.classList.remove('input-error');
                estBtn?.classList.add(marca, 'input-success');
                estBtn?.classList.remove('input-error');
            } else {
                estEl?.classList.remove(marca, 'input-success');
                estBtn?.classList.remove(marca, 'input-success');
            }

            const curMun = munEl ? String(munEl.value || '') : '';
            if (curMun !== snap.municipio_id) {
                munEl?.classList.add(clsAlt);
                munEl?.classList.remove(marca, 'input-success', 'input-error');
                munBtn?.classList.add(clsAlt);
                munBtn?.classList.remove(marca, 'input-success', 'input-error');
            } else if (snap.municipio_id !== '') {
                munEl?.classList.add(marca, 'input-success');
                munEl?.classList.remove('input-error');
                munBtn?.classList.add(marca, 'input-success');
                munBtn?.classList.remove('input-error');
            } else {
                munEl?.classList.remove(marca, 'input-success');
                munBtn?.classList.remove(marca, 'input-success');
            }
        }

        function marcarConflictoDatosCedulaEntrada(activo) {
            const ids = ['nombres', 'apellidos', 'telefono-codigo', 'telefono-numero', 'cedula-numero', 'estado_id', 'municipio_id'];
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (activo) {
                    el.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
                    el.classList.add('input-error');
                } else {
                    el.classList.remove('input-error');
                }
            });
            ['estado-search-button', 'municipio-search-button'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (activo) {
                    el.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
                    el.classList.add('input-error');
                } else {
                    el.classList.remove('input-error');
                }
            });
            const finp = document.getElementById('fecha_nacimiento');
            if (finp) {
                if (activo) {
                    finp.classList.remove('input-success', 'campos-desde-ciudadano');
                    finp.classList.add('input-error');
                } else {
                    finp.classList.remove('input-error');
                }
            }
            const alt = flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput;
            if (alt) {
                if (activo) {
                    alt.classList.remove('input-success', 'campos-desde-ciudadano');
                    alt.classList.add('input-error');
                } else {
                    alt.classList.remove('input-error');
                }
            }
            const btn = document.getElementById('custom-genero-button');
            if (btn) {
                if (activo) {
                    btn.classList.remove('input-success', 'campos-desde-ciudadano');
                    btn.classList.add('input-error');
                } else {
                    btn.classList.remove('input-error');
                }
            }
            ['ciudadano_email', 'direccion'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (activo) {
                    el.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
                    el.classList.add('input-error');
                } else {
                    el.classList.remove('input-error');
                }
            });
        }

        function obtenerFechaNacimientoFormularioYmd() {
            const inp = document.getElementById('fecha_nacimiento');
            if (!inp) return '';
            if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.selectedDates && flatpickrFechaNacimiento.selectedDates[0]) {
                const d = flatpickrFechaNacimiento.selectedDates[0];
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + day;
            }
            const v = (inp.value || '').trim();
            if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v.substring(0, 10);
            return '';
        }

        function cneNormTextoVerifCed(v) {
            return String(v == null ? '' : v).trim().replace(/\s+/g, ' ').toUpperCase();
        }
        function cneSoloDigitosVerifCed(v) {
            return String(v == null ? '' : v).replace(/\D/g, '');
        }
        function cneTelefonoIndeterminadoVerifCed(digits) {
            return !digits || digits.length < 7;
        }
        function cneNormFechaVerifCedYmd(v) {
            const s = String(v == null ? '' : v).trim();
            const m = s.match(/^(\d{4}-\d{2}-\d{2})/);
            if (m) return m[1];
            const m2 = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (m2) {
                const d = parseInt(m2[1], 10);
                const mo = parseInt(m2[2], 10);
                const y = parseInt(m2[3], 10);
                if (y >= 1900 && y <= 2100 && mo >= 1 && mo <= 12 && d >= 1 && d <= 31) {
                    return y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                }
            }
            return '';
        }
        function cneTelefonosCoincidenClienteVerifCed(telForm, telDb) {
            if (cneEsValorNATexto(telDb)) return true;
            const a = cneSoloDigitosVerifCed(telForm);
            const b = cneSoloDigitosVerifCed(telDb);
            if (cneTelefonoIndeterminadoVerifCed(a) && cneTelefonoIndeterminadoVerifCed(b)) return true;
            return a === b;
        }
        function cneFechasCoincidenClienteVerifCed(fnForm, fnDb) {
            if (cneEsValorNATexto(fnDb)) return true;
            const fIn = cneNormFechaVerifCedYmd(fnForm);
            const fDb = cneNormFechaVerifCedYmd(fnDb);
            if (fDb === '' && fIn === '') return true;
            if (fDb === '') return true;
            return fIn === fDb;
        }
        function cneGenerosCoincidenClienteVerifCed(gForm, gDb) {
            if (cneEsValorNATexto(gDb)) return true;
            const d = cneNormTextoVerifCed(gDb);
            if (d === '') return true;
            return cneNormTextoVerifCed(gForm) === d;
        }
        function cneDireccionesCoincidenClienteVerifCed(dirForm, dirDb) {
            if (cneEsValorNATexto(dirDb)) return true;
            return cneNormTextoVerifCed(dirForm) === cneNormTextoVerifCed(dirDb);
        }
        function cneFkUbicacionCoincideClienteVerifCed(idForm, idDb) {
            if (cneFkIdUbicacionEsNAClient(idDb)) return true;
            const a = String(idForm || '').trim();
            const b = String(idDb != null ? idDb : '').trim();
            return a !== '' && b !== '' && parseInt(a, 10) === parseInt(b, 10);
        }
        /** Respuesta de verificar_cedula.php: evita falsos positivos y registra el campo que difiere. */
        function cneInterpretarRespuestaVerificarCedulaEntrada(data, ctx) {
            if (!data || data.valido) return true;
            const ex = data.datos_existentes;
            if (!ex) {
                console.log('Verificación cédula: inválido sin datos_existentes', data);
                return false;
            }
            const okNombres = cneEsValorNATexto(ex.nombres) || cneNormTextoVerifCed(ctx.nombres) === cneNormTextoVerifCed(ex.nombres);
            const okApellidos = cneEsValorNATexto(ex.apellidos) || cneNormTextoVerifCed(ctx.apellidos) === cneNormTextoVerifCed(ex.apellidos);
            const okNombre = okNombres && okApellidos;
            const okTel = cneTelefonosCoincidenClienteVerifCed(ctx.telefonoCompleto, ex.telefono);
            const okFecha = cneFechasCoincidenClienteVerifCed(ctx.fechaYmd, ex.fecha_nacimiento);
            const okGen = cneGenerosCoincidenClienteVerifCed(ctx.genero, ex.genero);
            const okEst = cneFkUbicacionCoincideClienteVerifCed(ctx.estado_id, ex.estado_id);
            const okMun = cneFkUbicacionCoincideClienteVerifCed(ctx.municipio_id, ex.municipio_id);
            const okDir = cneDireccionesCoincidenClienteVerifCed(ctx.direccion, ex.direccion);
            if (okNombre && okTel && okFecha && okGen && okEst && okMun && okDir) {
                console.log('[Verificar cédula] El servidor marcó conflicto pero los datos coinciden tras normalización (entrada).');
                return true;
            }
            if (!okNombre) {
                if (!okNombres) console.log('Diferencia detectada en campo:', 'nombres', 'Valor DB:', cneNormTextoVerifCed(ex.nombres), 'Valor Input:', cneNormTextoVerifCed(ctx.nombres));
                if (!okApellidos) console.log('Diferencia detectada en campo:', 'apellidos', 'Valor DB:', cneNormTextoVerifCed(ex.apellidos), 'Valor Input:', cneNormTextoVerifCed(ctx.apellidos));
            }
            if (!okTel) {
                console.log('Diferencia detectada en campo:', 'telefono', 'Valor DB:', cneSoloDigitosVerifCed(ex.telefono), 'Valor Input:', cneSoloDigitosVerifCed(ctx.telefonoCompleto));
            }
            if (!okFecha) {
                console.log('Diferencia detectada en campo:', 'fecha_nacimiento', 'Valor DB:', cneNormFechaVerifCedYmd(ex.fecha_nacimiento), 'Valor Input:', cneNormFechaVerifCedYmd(ctx.fechaYmd));
            }
            if (!okGen) {
                console.log('Diferencia detectada en campo:', 'genero', 'Valor DB:', cneNormTextoVerifCed(ex.genero), 'Valor Input:', cneNormTextoVerifCed(ctx.genero));
            }
            if (!okEst) console.log('Diferencia detectada en campo:', 'estado_id', 'DB:', ex.estado_id, 'Input:', ctx.estado_id);
            if (!okMun) console.log('Diferencia detectada en campo:', 'municipio_id', 'DB:', ex.municipio_id, 'Input:', ctx.municipio_id);
            if (!okDir) console.log('Diferencia detectada en campo:', 'direccion', 'DB:', ex.direccion, 'Input:', ctx.direccion);
            return false;
        }

        function validarCedulaDuplicada(cedulaTipo, cedulaNumero, nombres, apellidos, telefonoCodigo, telefonoNumero, fechaNacimientoYmd) {
            if (!cedulaNumero) {
                return Promise.resolve(true);
            }
            
            const cedulaCompleta = cedulaTipo + '-' + cedulaNumero;
            const nomT = (nombres || '').trim();
            const apT = (apellidos || '').trim();
            const nombreCompleto = (nomT || 'N/A') + ' ' + (apT || 'N/A');
            const telefonoCompleto = telefonoCodigo + '-' + telefonoNumero;
            const fn = fechaNacimientoYmd != null ? fechaNacimientoYmd : obtenerFechaNacimientoFormularioYmd();
            const generoVal = document.getElementById('genero')?.value || '';
            const estId = document.getElementById('estado_id')?.value || '';
            const munId = document.getElementById('municipio_id')?.value || '';
            const dirT = document.getElementById('direccion')?.value || '';
            
            return fetch(`ajax/verificar_cedula.php?cedula=${encodeURIComponent(cedulaCompleta)}&nombre=${encodeURIComponent(nombreCompleto)}&nombres=${encodeURIComponent(nomT)}&apellidos=${encodeURIComponent(apT)}&telefono=${encodeURIComponent(telefonoCompleto)}&fecha_nacimiento=${encodeURIComponent(fn)}&genero=${encodeURIComponent(generoVal)}&estado_id=${encodeURIComponent(estId)}&municipio_id=${encodeURIComponent(munId)}&direccion=${encodeURIComponent(dirT)}`)
                .then(response => response.json())
                .then(data => {
                    return cneInterpretarRespuestaVerificarCedulaEntrada(data, {
                        nombreCompleto: nombreCompleto,
                        nombres: nomT,
                        apellidos: apT,
                        telefonoCompleto: telefonoCompleto,
                        fechaYmd: fn,
                        genero: generoVal,
                        estado_id: estId,
                        municipio_id: munId,
                        direccion: dirT
                    });
                })
                .catch(error => {
                    console.error('Error al verificar cédula:', error);
                    return true;
                });
        }
        
        function mostrarError(input, errorDiv, mensaje) {
            input.classList.remove('input-success');
            input.classList.add('input-error');
            errorDiv.textContent = mensaje;
            errorDiv.classList.remove('hidden');
        }
        
        function mostrarExito(input, errorDiv) {
            input.classList.remove('input-error');
            input.classList.add('input-success');
            errorDiv.classList.add('hidden');
        }
        
        async function validarFormulario() {
            const errCedula = document.getElementById('error-cedula');
            if (errCedula) errCedula.classList.add('hidden');
            marcarConflictoDatosCedulaEntrada(false);

            if (!cneAlertaGeneroObligatorioEntrada()) {
                validarGenero(document.getElementById('genero'));
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada();
                return false;
            }

            const campos = [
                { input: document.getElementById('nombres'), validar: validarNombre },
                { input: document.getElementById('apellidos'), validar: validarApellido },
                { input: document.getElementById('cedula-numero'), validar: validarCedula },
                { input: document.getElementById('telefono-numero'), validar: validarTelefono },
                { input: document.getElementById('genero'), validar: validarGenero },
                { input: document.getElementById('institucion'), validar: validarInstitucion },
                { input: document.getElementById('area_id'), validar: validarArea }
            ];
            
            let valido = true;
            
            campos.forEach(campo => {
                if (!campo.validar(campo.input)) {
                    valido = false;
                }
            });
            
            if (!validarTipoTramite()) {
                valido = false;
            }

            validarEstado(document.getElementById('estado_id'));
            validarMunicipio(document.getElementById('municipio_id'));
            
            if (!valido) {
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada();
                return false;
            }
            
            const cedulaTipo = document.getElementById('cedula-tipo').value;
            const cedulaNumero = document.getElementById('cedula-numero').value.trim();
            const nombres = document.getElementById('nombres').value.trim();
            const apellidos = document.getElementById('apellidos').value.trim();
            const telefonoCodigo = document.getElementById('telefono-codigo').value;
            const telefonoNumero = document.getElementById('telefono-numero').value.trim();
            const fechaNacYmd = obtenerFechaNacimientoFormularioYmd();
            
            try {
                const cedulaValida = await validarCedulaDuplicada(cedulaTipo, cedulaNumero, nombres, apellidos, telefonoCodigo, telefonoNumero, fechaNacYmd);
                
                if (!cedulaValida) {
                    const errorDiv = document.getElementById('error-cedula');
                    errorDiv.textContent = 'Datos diferentes detectados: ya existe un registro con esta cédula. Verifique nombres, apellidos, teléfono, fecha de nacimiento, género, estado, municipio y dirección.';
                    errorDiv.classList.remove('hidden');
                    marcarConflictoDatosCedulaEntrada(true);
                    actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada();
                    return false;
                }
                
                marcarConflictoDatosCedulaEntrada(false);
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada();
                return true;
            } catch (error) {
                console.error('Error en validación de cédula:', error);
                return true;
            }
        }
        
        /** True si hay algo que enviar al backend (sin mínimo de longitud). */
        function cedulaNumeroTieneContenidoBusquedaEntrada(tipo, raw) {
            const t = String(tipo || 'V').toUpperCase();
            const n = String(raw || '').trim().replace(/[\s.\-]/g, '');
            if (!n) return false;
            if (t === 'J' || t === 'G') return /[0-9A-Za-z]/.test(n);
            return /\d/.test(n);
        }

        /** Resetea el formulario de nueva solicitud: valores, protección ficha evolutiva, estilos y mensajes de error. */
        function limpiarFormularioNuevaSolicitudEntrada() {
            const form = document.getElementById('tramitante-form');
            if (!form) return;
            snapshotDatosOpcionalesCiudadanoEntrada = null;
            quitarProteccionIdentidadCiudadanoEntrada();
            quitarMarcaCamposDesdeCiudadanoEntrada();
            ['nombres', 'apellidos', 'cedula-tipo', 'cedula-numero', 'telefono-codigo', 'telefono-numero', 'ciudadano_email', 'direccion', 'estado_id', 'municipio_id', 'fecha_nacimiento', 'institucion', 'institucion-otro', 'area_id'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'loading-input');
            });
            ['estado-search-button', 'municipio-search-button', 'institucion-search-button'].forEach(id => {
                document.getElementById(id)?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'loading-input');
            });
            if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                flatpickrFechaNacimiento.altInput.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable');
            }
            document.getElementById('custom-genero-button')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none');
            form.querySelectorAll('.error-message').forEach(div => div.classList.add('hidden'));
            form.querySelectorAll('.input-success, .input-error').forEach(el => {
                el.classList.remove('input-success', 'input-error');
            });
            form.querySelectorAll('.ciudadano-dato-alterado').forEach(el => el.classList.remove('ciudadano-dato-alterado'));
            form.reset();
            quitarProteccionIdentidadCiudadanoEntrada();
            if (flatpickrFechaNacimiento) flatpickrFechaNacimiento.clear();
            else {
                const fn = document.getElementById('fecha_nacimiento');
                if (fn) fn.value = '';
            }
            const finpPost = document.getElementById('fecha_nacimiento');
            if (finpPost && !finpPost.classList.contains('ciudadano-campo-na-editable')) {
                finpPost.setAttribute('readonly', 'readonly');
                if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                    flatpickrFechaNacimiento.altInput.setAttribute('readonly', 'readonly');
                }
            }
            const otroWrapper = document.getElementById('institucion-otro-wrapper');
            if (otroWrapper) otroWrapper.classList.add('hidden');
            const otroInp = document.getElementById('institucion-otro');
            if (otroInp) otroInp.value = '';
            const institucionSel = document.getElementById('institucion');
            if (institucionSel && institucionSel.dataset.personalId) {
                institucionSel.value = institucionSel.dataset.personalId;
                institucionSel.dispatchEvent(new Event('change'));
            }
            const tramBtn = document.getElementById('tramite-search-button');
            if (tramBtn) {
                const selectedText = tramBtn.querySelector('.selected-tramite-text');
                if (selectedText) {
                    selectedText.textContent = 'Seleccione un tipo de trámite';
                    selectedText.className = 'selected-tramite-text tramite-placeholder';
                }
            }
            document.getElementById('tipo_tramite_id').value = '';
            document.getElementById('campos-dinamicos')?.classList.add('hidden');
            const camposCont = document.getElementById('campos-contenido');
            if (camposCont) camposCont.innerHTML = '';
            const tramiteSearchInp = document.getElementById('tramite-search-input');
            if (tramiteSearchInp) tramiteSearchInp.value = '';
            document.getElementById('subtramite-wrapper')?.classList.add('hidden');
            const subSel = document.getElementById('subtramite_id');
            if (subSel) {
                subSel.innerHTML = '<option value="">Seleccione una variante</option>';
                subSel.value = '';
            }
            document.getElementById('requisitos-wrapper')?.classList.add('hidden');
            const reqList = document.getElementById('requisitos-list');
            if (reqList) reqList.innerHTML = '';
            const generoSelect = document.getElementById('genero');
            if (generoSelect) {
                generoSelect.value = '';
                generoSelect.dispatchEvent(new Event('change'));
            }
            document.getElementById('tipo_solicitud').value = 'normal';
            tipoSolicitudActual = 'normal';
            populateMunicipios(document.getElementById('estado_id')?.value || '');
            if (typeof window.cneNuevaSolicitudCombosSyncInstitucionBoton === 'function') {
                window.cneNuevaSolicitudCombosSyncInstitucionBoton();
            }
            document.getElementById('institucion')?.dispatchEvent(new Event('change'));
        }

        function limpiarCamposCiudadanoAntesDeBusquedaEntrada() {
            snapshotDatosOpcionalesCiudadanoEntrada = null;
            quitarProteccionIdentidadCiudadanoEntrada();
            quitarMarcaCamposDesdeCiudadanoEntrada();
            document.getElementById('ciudadano_email')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('direccion')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('fecha_nacimiento')?.classList.remove('input-success');
            if (flatpickrFechaNacimiento && flatpickrFechaNacimiento.altInput) {
                flatpickrFechaNacimiento.altInput.classList.remove('input-success');
            }
            document.getElementById('custom-genero-button')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano');
            document.getElementById('estado_id')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('municipio_id')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('estado-search-button')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('municipio-search-button')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('nombres').value = '';
            document.getElementById('apellidos').value = '';
            document.getElementById('telefono-codigo').value = '0412';
            document.getElementById('telefono-numero').value = '';
            document.getElementById('ciudadano_email').value = '';
            document.getElementById('genero').value = '';
            document.getElementById('genero').dispatchEvent(new Event('change'));
            if (flatpickrFechaNacimiento) flatpickrFechaNacimiento.clear();
            else document.getElementById('fecha_nacimiento').value = '';
            document.getElementById('direccion').value = '';
            if (typeof window.cneNuevaSolicitudCombosSetEstadoValor === 'function') {
                window.cneNuevaSolicitudCombosSetEstadoValor('');
            } else {
                document.getElementById('estado_id').value = '';
                populateMunicipios('');
                document.getElementById('municipio_id').value = '';
            }
            if (typeof window.cneNuevaSolicitudCombosSyncInstitucionBoton === 'function') {
                window.cneNuevaSolicitudCombosSyncInstitucionBoton();
            }
            document.getElementById('institucion')?.dispatchEvent(new Event('change'));
        }

        function inicializarBusquedaCiudadanoPorCedulaEntrada() {
            const numEl = document.getElementById('cedula-numero');
            const tipoEl = document.getElementById('cedula-tipo');
            if (!numEl) return;
            numEl.addEventListener('blur', function() {
                ejecutarBusquedaCiudadanoPorCedulaEntrada();
            });
            numEl.addEventListener('input', function() {
                const tipo = document.getElementById('cedula-tipo')?.value || 'V';
                if (!cedulaNumeroTieneContenidoBusquedaEntrada(tipo, this.value)) {
                    clearTimeout(debounceTimerBusquedaCedulaEntrada);
                }
            });
            tipoEl?.addEventListener('change', function() {
                if (cedulaNumeroTieneContenidoBusquedaEntrada(this.value, numEl.value)) {
                    programarBusquedaCiudadanoPorCedulaEntrada(80);
                }
            });
        }

        function programarBusquedaCiudadanoPorCedulaEntrada(delayMs) {
            clearTimeout(debounceTimerBusquedaCedulaEntrada);
            debounceTimerBusquedaCedulaEntrada = setTimeout(() => {
                ejecutarBusquedaCiudadanoPorCedulaEntrada();
            }, typeof delayMs === 'number' ? delayMs : 320);
        }

        function ejecutarBusquedaCiudadanoPorCedulaEntrada() {
            const cedulaTipo = document.getElementById('cedula-tipo').value;
            const cedulaNumero = document.getElementById('cedula-numero').value.trim().replace(/[\s.\-]/g, '');
            if (!cedulaNumeroTieneContenidoBusquedaEntrada(cedulaTipo, cedulaNumero)) {
                return;
            }
            const cedulaInput = document.getElementById('cedula-numero');
            if (busquedaCiudadanoAbortEntrada) {
                try { busquedaCiudadanoAbortEntrada.abort(); } catch (e) {}
            }
            busquedaCiudadanoAbortEntrada = new AbortController();
            const seq = ++busquedaCiudadanoSeqEntrada;
            cedulaInput.classList.add('loading-input');

            const url = 'ajax/buscar_ciudadano.php?cedula_tipo=' + encodeURIComponent(cedulaTipo) + '&cedula_numero=' + encodeURIComponent(cedulaNumero);
            fetch(url, { signal: busquedaCiudadanoAbortEntrada.signal, credentials: 'same-origin' })
                .then(async response => {
                    if (!response.ok) {
                        const errText = await response.text().catch(() => '');
                        const err = new Error('HTTP ' + response.status + ' ' + response.statusText + (errText ? ' — ' + errText : ''));
                        console.error('[BuscaCiudadano] Error en la petición (HTTP):', err.message, errText);
                        throw err;
                    }
                    return response.json();
                })
                .then(data => {
                    if (seq !== busquedaCiudadanoSeqEntrada) return;
                    console.log('[BuscaCiudadano] Datos recibidos:', data);
                    cedulaInput.classList.remove('loading-input');

                    const encontrado = data.success === true && (data.encontrado === true || data.ciudadano_identificacion);

                    if (encontrado) {
                        limpiarCamposCiudadanoAntesDeBusquedaEntrada();

                        document.getElementById('nombres').value = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(data.ciudadano_nombres || '') : (data.ciudadano_nombres || '');
                        document.getElementById('apellidos').value = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(data.ciudadano_apellidos || '') : (data.ciudadano_apellidos || '');

                        const telefonoCompleto = data.ciudadano_telefono || '';
                        if (telefonoCompleto && !cneEsValorNATexto(telefonoCompleto)) {
                            const partes = telefonoCompleto.split('-');
                            if (partes.length >= 2) {
                                document.getElementById('telefono-codigo').value = partes[0];
                                document.getElementById('telefono-numero').value = partes.slice(1).join('-');
                            }
                        } else {
                            document.getElementById('telefono-codigo').value = '0412';
                            document.getElementById('telefono-numero').value = '';
                        }

                        if (data.ciudadano_genero) {
                            const g = String(data.ciudadano_genero).trim().toLowerCase();
                            if (g === 'masculino' || g === 'femenino') {
                                const generoSelect = document.getElementById('genero');
                                generoSelect.value = g;
                                generoSelect.dispatchEvent(new Event('change'));
                            }
                        }

                        if (data.ciudadano_fecha_nacimiento && !cneEsValorNATexto(data.ciudadano_fecha_nacimiento)) {
                            fechaNacimientoEntradaProgrammatic = true;
                            if (flatpickrFechaNacimiento) flatpickrFechaNacimiento.setDate(data.ciudadano_fecha_nacimiento);
                            else document.getElementById('fecha_nacimiento').value = data.ciudadano_fecha_nacimiento;
                            requestAnimationFrame(() => { fechaNacimientoEntradaProgrammatic = false; });
                        }

                        const eidRawEnt = data.estado_id != null ? parseInt(String(data.estado_id), 10) : 0;
                        if (!isNaN(eidRawEnt) && eidRawEnt > 0) {
                            if (typeof window.cneNuevaSolicitudCombosSetEstadoValor === 'function') {
                                window.cneNuevaSolicitudCombosSetEstadoValor(String(eidRawEnt));
                            } else {
                                const estadoSelect = document.getElementById('estado_id');
                                if (estadoSelect) {
                                    estadoSelect.value = String(eidRawEnt);
                                    estadoSelect.dispatchEvent(new Event('change'));
                                }
                            }
                        }
                        const midNumEnt = data.municipio_id != null ? parseInt(String(data.municipio_id), 10) : 0;
                        const mid = !isNaN(midNumEnt) && midNumEnt > 0 ? String(midNumEnt) : '';
                        if (mid) {
                            const aplicarMunicipio = () => {
                                if (seq !== busquedaCiudadanoSeqEntrada) return;
                                if (typeof window.cneNuevaSolicitudCombosSetMunicipioValor === 'function') {
                                    window.cneNuevaSolicitudCombosSetMunicipioValor(mid);
                                } else {
                                    const municipioSelect = document.getElementById('municipio_id');
                                    if (municipioSelect) municipioSelect.value = mid;
                                }
                            };
                            requestAnimationFrame(aplicarMunicipio);
                            setTimeout(aplicarMunicipio, 50);
                        }

                        const dirEnt = document.getElementById('direccion');
                        if (dirEnt) dirEnt.value = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(data.ciudadano_direccion != null ? String(data.ciudadano_direccion) : '') : (data.ciudadano_direccion != null ? String(data.ciudadano_direccion) : '');

                        if (data.ciudadano_email) {
                            document.getElementById('ciudadano_email').value = data.ciudadano_email;
                        }

                        validarNombre(document.getElementById('nombres'));
                        validarApellido(document.getElementById('apellidos'));
                        validarTelefono(document.getElementById('telefono-numero'));
                        validarGenero(document.getElementById('genero'));
                        aplicarMarcaCamposDesdeCiudadanoEntrada(data);
                        aplicarProteccionIdentidadCiudadanoEntrada(data);
                        setTimeout(() => {
                            if (seq !== busquedaCiudadanoSeqEntrada) return;
                            guardarSnapshotOpcionalesCiudadanoEntrada(data);
                            actualizarAdvertenciaCamposOpcionalesVsSnapshotEntrada();
                        }, 100);

                        if (data.message) {
                            mostrarMensajeInfo(data.message);
                        }
                    } else {
                        const msg = data && data.message ? String(data.message) : '';
                        if (msg.includes('No se encontraron') || msg.includes('inválidos') || msg.includes('incompletos')) {
                            limpiarCamposCiudadanoAntesDeBusquedaEntrada();
                        }
                    }
                })
                .catch(error => {
                    if (error.name === 'AbortError') return;
                    console.error('[BuscaCiudadano] Error en la petición:', error && error.message ? error.message : error, error);
                    if (seq === busquedaCiudadanoSeqEntrada) {
                        cedulaInput.classList.remove('loading-input');
                    }
                });
        }

        function buscarPorCedula() {
            programarBusquedaCiudadanoPorCedulaEntrada();
        }
        
        function mostrarMensajeInfo(mensaje) {
            let mensajeDiv = document.getElementById('mensaje-info-cedula');
            if (!mensajeDiv) {
                mensajeDiv = document.createElement('div');
                mensajeDiv.id = 'mensaje-info-cedula';
                mensajeDiv.className = 'absolute top-0 left-1/2 transform -translate-x-1/2 -translate-y-full px-3 py-1 bg-blue-500 text-white text-xs rounded-lg shadow-lg z-50 whitespace-nowrap';
                mensajeDiv.innerHTML = `<span>${mensaje}</span> <span class="absolute bottom-0 left-1/2 transform -translate-x-1/2 translate-y-1/2 w-2 h-2 bg-blue-500 rotate-45"></span>`;
                document.getElementById('cedula-container').appendChild(mensajeDiv);
            } else {
                mensajeDiv.querySelector('span').textContent = mensaje;
            }
            mensajeDiv.classList.remove('hidden');
            
            setTimeout(() => {
                mensajeDiv.classList.add('hidden');
            }, 2000);
        }
        
        function cargarTiposTramite(areaId) {
            const button = document.getElementById('tramite-search-button');
            const dropdown = document.getElementById('tramite-search-dropdown');
            const searchInput = document.getElementById('tramite-search-input');
            const resultsContainer = document.getElementById('tramite-search-results');
            const selectedText = button.querySelector('.selected-tramite-text');
            const camposDinamicos = document.getElementById('campos-dinamicos');
            const camposContenido = document.getElementById('campos-contenido');
            
            if (!areaId) {
                selectedText.textContent = 'Seleccione una coordinación primero';
                selectedText.className = 'selected-tramite-text tramite-placeholder';
                document.getElementById('tipo_tramite_id').value = '';
                camposDinamicos.classList.add('hidden');
                button.classList.remove('open');
                dropdown.classList.remove('open');
                
                resultsContainer.innerHTML = `
                    <div class="tramite-search-option" data-value="">
                        <span class="tramite-placeholder">Seleccione una coordinación primero</span>
                    </div>
                `;
                
                fuseInstance = null;
                currentTramiteList = [];
                return;
            }
            
            selectedText.textContent = 'Cargando tipos de trámite...';
            selectedText.className = 'selected-tramite-text';
            resultsContainer.innerHTML = `
                <div class="searching-indicator">
                    <div class="loading mx-auto mb-2"></div>
                    <p>Cargando tipos de trámite...</p>
                </div>
            `;
            
            fetch(`ajax/obtener_tipos_tramite.php?area_id=${areaId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error en la respuesta del servidor');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        resultsContainer.innerHTML = `
                            <div class="tramite-search-option" data-value="">
                                <span class="text-red-500">${data.error}</span>
                            </div>
                        `;
                        selectedText.textContent = 'Error al cargar';
                        selectedText.className = 'selected-tramite-text';
                        camposDinamicos.classList.add('hidden');
                        
                        fuseInstance = null;
                        currentTramiteList = [];
                        return;
                    }
                    
                    tiposTramiteData = data;
                    currentTramiteList = data;
                    
                    if (data.length === 0) {
                        resultsContainer.innerHTML = `
                            <div class="tramite-search-option" data-value="">
                                <span class="tramite-placeholder">No hay tipos de trámite disponibles</span>
                            </div>
                        `;
                        selectedText.textContent = 'No hay tipos de trámite disponibles';
                        selectedText.className = 'selected-tramite-text tramite-placeholder';
                        document.getElementById('tipo_tramite_id').value = '';
                        camposDinamicos.classList.add('hidden');
                        
                        fuseInstance = null;
                        return;
                    }
                    
                    const options = {
                        keys: ['nombre'],
                        threshold: 0.3,
                        distance: 100,
                        includeScore: true,
                        includeMatches: true,
                        minMatchCharLength: 1,
                        getFn: (obj, path) => {
                            const normalizeText = (text) => {
                                return text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
                            };
                            
                            const value = obj[path];
                            if (typeof value === 'string') {
                                return normalizeText(value);
                            }
                            return value;
                        }
                    };
                    
                    fuseInstance = new Fuse(data, options);
                    
                    let optionsHtml = '';
                    
                    data.forEach(tramite => {
                        optionsHtml += `
                            <div class="tramite-search-option" data-value="${tramite.id}" data-nombre="${tramite.nombre}">
                                <span>${tramite.nombre}</span>
                            </div>
                        `;
                    });
                    
                    resultsContainer.innerHTML = optionsHtml;
                    
                    resultsContainer.querySelectorAll('.tramite-search-option').forEach(option => {
                        option.addEventListener('click', function() {
                            seleccionarTramiteOption(this);
                        });
                    });
                    
                    selectedText.textContent = 'Seleccione un tipo de trámite';
                    selectedText.className = 'selected-tramite-text tramite-placeholder';
                    document.getElementById('tipo_tramite_id').value = '';
                    
                    dropdown.classList.remove('open');
                    button.classList.remove('open');
                })
                .catch(error => {
                    console.error('Error:', error);
                    resultsContainer.innerHTML = `
                        <div class="tramite-search-option" data-value="">
                            <span class="text-red-500">Error al cargar tipos de trámite</span>
                        </div>
                    `;
                    selectedText.textContent = 'Error al cargar';
                    selectedText.className = 'selected-tramite-text';
                    camposDinamicos.classList.add('hidden');
                    
                    fuseInstance = null;
                    currentTramiteList = [];
                });
        }
        
        function generarCamposDinamicos(campos) {
            const container = document.getElementById('campos-contenido');
            let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
            
            campos.forEach((campo, index) => {
                html += `
                    <div>
                        <label class="block mb-2 font-medium text-gray-700 capitalize">${campo.replace(/_/g, ' ')}</label>
                        <input type="text" 
                               name="campo_${campo}" 
                               class="w-full p-3 border-2 border-gray-300 rounded-lg"
                               placeholder="Ingrese ${campo.replace(/_/g, ' ')}">
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        function mostrarModalConfirmacion() {
            const modal = document.getElementById('confirmModal');
            const detalles = document.getElementById('confirm-details');
            
            const nombres = document.getElementById('nombres')?.value.trim() || '';
            const apellidos = document.getElementById('apellidos')?.value.trim() || '';
            const cedulaTipo = document.getElementById('cedula-tipo')?.value || 'V';
            const cedulaNumero = document.getElementById('cedula-numero')?.value.trim() || '';
            const telefonoCodigo = document.getElementById('telefono-codigo')?.value || '0412';
            const telefonoNumero = document.getElementById('telefono-numero')?.value.trim() || '';
            const generoSelect = document.getElementById('genero');
            const generoValue = generoSelect?.value || '';
            let generoText = 'No seleccionado';
            if (generoValue === 'masculino') generoText = 'Masculino';
            else if (generoValue === 'femenino') generoText = 'Femenino';
           
            const institucionSelect = document.getElementById('institucion');
            let institucion = institucionSelect?.options[institucionSelect.selectedIndex]?.text || 'No seleccionada';
            if (typeof cneMayusCiudadanoTexto === 'function') institucion = cneMayusCiudadanoTexto(institucion);
            if (institucionSelect?.value === 'otro') {
                const otroNombre = document.getElementById('institucion-otro')?.value.trim() || '';
                if (otroNombre) institucion = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(otroNombre) : otroNombre;
            }
            const areaSelect = document.getElementById('area_id');
            const area = areaSelect?.options[areaSelect.selectedIndex]?.text || 'No seleccionada';
            const tipoTramiteId = document.getElementById('tipo_tramite_id')?.value || '';
            
            let tipoTramite = '';
            if (tipoTramiteId) {
                tipoTramite = tiposTramiteData.find(t => t.id == tipoTramiteId)?.nombre || '';
                if (!tipoTramite) {
                    tipoTramite = currentTramiteList.find(t => t.id == tipoTramiteId)?.nombre || '';
                }
            }
            
            const nombreCompleto = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(`${nombres} ${apellidos}`.trim()) : `${nombres} ${apellidos}`.trim();
            const cedulaCompleta = cedulaNumero ? `${cedulaTipo}-${cedulaNumero}` : '';
            const telefonoCompleto = telefonoNumero ? `${telefonoCodigo}-${telefonoNumero}` : '';
            
            detalles.innerHTML = `
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Nombre completo:</span>
                        <span class="font-semibold">${nombreCompleto || 'No especificado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Cédula:</span>
                        <span class="font-mono font-semibold">${cedulaCompleta || 'No especificada'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Teléfono:</span>
                        <span class="font-semibold">${telefonoCompleto || 'No especificado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Género:</span>
                        <span class="font-semibold">${generoText || 'No especificado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Institución:</span>
                        <span class="font-semibold">${institucion}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Coordinación:</span>
                        <span class="font-semibold">${area}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Tipo de Trámite:</span>
                        <span class="font-semibold">${tipoTramite || 'No seleccionado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Fecha y Hora:</span>
                        <span class="font-semibold">${new Date().toLocaleString('es-ES', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        })}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Estado Inicial:</span>
                        <span class="font-semibold text-blue-600">PENDIENTE</span>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
            
            document.getElementById('cancelBtn').onclick = () => {
                modal.classList.remove('active');
            };
            
            document.getElementById('confirmBtn').onclick = () => {
                modal.classList.remove('active');
                enviarFormulario('normal');
            };
        }
        
        function mostrarModalConfirmacionCompletado() {
            const modal = document.getElementById('confirmCompletadoModal');
            const detalles = document.getElementById('confirm-completado-details');
            
            const nombres = document.getElementById('nombres')?.value.trim() || '';
            const apellidos = document.getElementById('apellidos')?.value.trim() || '';
            const cedulaTipo = document.getElementById('cedula-tipo')?.value || 'V';
            const cedulaNumero = document.getElementById('cedula-numero')?.value.trim() || '';
            const telefonoCodigo = document.getElementById('telefono-codigo')?.value || '0412';
            const telefonoNumero = document.getElementById('telefono-numero')?.value.trim() || '';
            const generoSelect = document.getElementById('genero');
            const generoValue = generoSelect?.value || '';
            let generoText = 'No seleccionado';
            if (generoValue === 'masculino') generoText = 'Masculino';
            else if (generoValue === 'femenino') generoText = 'Femenino';
           
            const institucionSelect = document.getElementById('institucion');
            let institucion = institucionSelect?.options[institucionSelect.selectedIndex]?.text || 'No seleccionada';
            if (typeof cneMayusCiudadanoTexto === 'function') institucion = cneMayusCiudadanoTexto(institucion);
            if (institucionSelect?.value === 'otro') {
                const otroNombre = document.getElementById('institucion-otro')?.value.trim() || '';
                if (otroNombre) institucion = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(otroNombre) : otroNombre;
            }
            const areaSelect = document.getElementById('area_id');
            const area = areaSelect?.options[areaSelect.selectedIndex]?.text || 'No seleccionada';
            const tipoTramiteId = document.getElementById('tipo_tramite_id')?.value || '';
            
            let tipoTramite = '';
            if (tipoTramiteId) {
                tipoTramite = tiposTramiteData.find(t => t.id == tipoTramiteId)?.nombre || '';
                if (!tipoTramite) {
                    tipoTramite = currentTramiteList.find(t => t.id == tipoTramiteId)?.nombre || '';
                }
            }
            
            const nombreCompleto = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(`${nombres} ${apellidos}`.trim()) : `${nombres} ${apellidos}`.trim();
            const cedulaCompleta = cedulaNumero ? `${cedulaTipo}-${cedulaNumero}` : '';
            const telefonoCompleto = telefonoNumero ? `${telefonoCodigo}-${telefonoNumero}` : '';
            const reqsMarcados = obtenerRequisitosMarcadosEntrada();
            const bloqueRequisitos = (tipoSolicitudActual === 'inmediato' && reqsMarcados.length)
                ? `<div class="pt-2 border-t border-gray-100"><span class="text-gray-600 block mb-1">Requisitos marcados:</span><ul class="list-disc pl-5 text-sm font-semibold text-gray-800 text-left">${reqsMarcados.map(r => `<li>${(r.nombre || '').replace(/</g, '&lt;')}</li>`).join('')}</ul></div>`
                : '';
            
            detalles.innerHTML = `
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Nombre completo:</span>
                        <span class="font-semibold">${nombreCompleto || 'No especificado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Cédula:</span>
                        <span class="font-mono font-semibold">${cedulaCompleta || 'No especificada'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Teléfono:</span>
                        <span class="font-semibold">${telefonoCompleto || 'No especificado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Género:</span>
                        <span class="font-semibold">${generoText || 'No especificado'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Institución:</span>
                        <span class="font-semibold">${institucion}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Coordinación:</span>
                        <span class="font-semibold">${area}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Tipo de Trámite:</span>
                        <span class="font-semibold">${tipoTramite || 'No seleccionado'}</span>
                    </div>
                    ${bloqueRequisitos}
                    <div class="flex justify-between">
                        <span class="text-gray-600">Fecha y Hora:</span>
                        <span class="font-semibold">${new Date().toLocaleString('es-ES', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        })}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Estado Final:</span>
                        <span class="font-semibold text-green-600">COMPLETADO</span>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
        }
        
        function enviarFormulario(tipo) {
            if (!cneAlertaGeneroObligatorioEntrada()) {
                return;
            }
            if (tipo === 'inmediato' && !validarRequisitosTramiteInmediatoEntrada()) {
                return;
            }
            const form = document.getElementById('tramitante-form');
            const estEl = document.getElementById('estado_id');
            const munEl = document.getElementById('municipio_id');
            const estWasDis = !!(estEl && estEl.disabled);
            const munWasDis = !!(munEl && munEl.disabled);
            if (estEl) estEl.disabled = false;
            if (munEl) munEl.disabled = false;
            const formData = new FormData(form);
            
            if (tipo === 'inmediato') {
                document.getElementById('tipo_solicitud').value = 'inmediato';
            } else {
                document.getElementById('tipo_solicitud').value = 'normal';
            }
            
            formData.set('tipo_solicitud', tipo);
            formData.set('tipo_tramite_id', document.getElementById('tipo_tramite_id').value);
            if (tipo === 'inmediato') {
                const detalle = obtenerRequisitosMarcadosEntrada();
                const ids = detalle.map(x => x.id);
                formData.set('requisitos_seleccionados', JSON.stringify(ids));
                formData.set('requisitos_seleccionados_detalle', JSON.stringify(detalle));
                formData.set('requisitos_marcados_nombres', JSON.stringify(obtenerNombresRequisitosMarcadosEntrada()));
            }
            
            let confirmBtn;
            if (tipo === 'normal') {
                confirmBtn = document.getElementById('confirmBtn');
            } else {
                confirmBtn = document.getElementById('confirmCompletadoBtn');
            }
            
            const originalText = confirmBtn.innerHTML;
            
            confirmBtn.innerHTML = '<div class="loading mx-auto"></div> Procesando...';
            confirmBtn.disabled = true;
            
            let envioEntradaExito = false;
            fetch('procesar_solicitud.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    envioEntradaExito = true;
                    document.getElementById('numero-seguimiento-generado').textContent = data.numero_seguimiento;
                    
                    if (tipo === 'inmediato') {
                        document.getElementById('success-message').textContent = 'El trámite se ha registrado y completado exitosamente';
                        document.getElementById('estado-info').innerHTML = `
                            <div class="flex items-center justify-center gap-2 mt-2">
                                <span class="badge-completado">
                                    <i class="fas fa-check-circle"></i>
                                    COMPLETADO
                                </span>
                            </div>
                        `;
                    } else {
                        document.getElementById('success-message').textContent = 'La solicitud ha sido registrada exitosamente en el sistema';
                        document.getElementById('estado-info').innerHTML = `
                            <div class="flex items-center justify-center gap-2 mt-2">
                                <span class="status-badge status-pendiente">PENDIENTE</span>
                            </div>
                        `;
                    }
                    
                    document.getElementById('successModal').classList.add('active');
                    limpiarFormularioNuevaSolicitudEntrada();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al procesar la solicitud');
            })
            .finally(() => {
                if (!envioEntradaExito) {
                    if (estEl && estWasDis) estEl.disabled = true;
                    if (munEl && munWasDis) munEl.disabled = true;
                }
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });
        }
        
        function cargarMisSolicitudes() {
            const container = document.getElementById('mis-solicitudes-content');
            const contador = document.getElementById('contador-solicitudes');
            const mostrandoContador = document.getElementById('mostrando-contador');
            const totalContador = document.getElementById('total-contador');
            
            if (!container) return;
            
            container.innerHTML = '<tr><td colspan="7" class="px-6 py-8 text-center"><div class="flex flex-col items-center justify-center"><div class="loading mb-4"></div><p class="text-gray-500">Cargando solicitudes...</p></div></td></tr>';
            
            const filtros = {
                cedula: document.getElementById('filtro-cedula').value,
                area: document.getElementById('filtro-area').value,
                estado: document.getElementById('filtro-estado').value,
                tipo_tramite: document.getElementById('filtro-tipo-tramite').value,
                subtramite: document.getElementById('filtro-subtramite') ? document.getElementById('filtro-subtramite').value : '',
                institucion: document.getElementById('filtro-institucion').value,
                fecha_desde: document.getElementById('filtro-fecha-desde').value,
                fecha_hasta: document.getElementById('filtro-fecha-hasta').value
            };
            
            const params = new URLSearchParams(filtros).toString();
            
            fetch(`ajax/obtener_mis_solicitudes.php?${params}`)
                .then(response => response.text())
                .then(html => {
                    container.innerHTML = html;
                    
                    const filas = container.querySelectorAll('tbody tr');
                    const total = filas.length - (filas.length > 0 && filas[0].querySelector('td[colspan]') ? 1 : 0);
                    
                    if (contador) contador.textContent = total;
                    if (mostrandoContador) mostrandoContador.textContent = total;
                    if (totalContador) totalContador.textContent = total;
                    
                    const ahora = new Date();
                    const hora = ahora.toLocaleTimeString('es-ES');
                    const ultimaActualizacion = document.getElementById('ultima-actualizacion');
                    if (ultimaActualizacion) ultimaActualizacion.textContent = hora;
                })
                .catch(error => {
                    container.innerHTML = '<tr><td colspan="7" class="px-6 py-8 text-center"><div class="text-red-500">Error al cargar las solicitudes</div></td></tr>';
                    if (contador) contador.textContent = '0';
                    if (mostrandoContador) mostrandoContador.textContent = '0';
                    if (totalContador) totalContador.textContent = '0';
                });
        }
        
        function escapeHtmlBusquedaTramite(str) {
            if (str == null || str === '') return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function claseBadgeEstadoBusqueda(estado) {
            const mapa = {
                pendiente: 'status-pendiente',
                en_revision: 'status-proceso',
                aprobada: 'status-proceso',
                rechazada: 'status-vencido',
                vencida: 'status-vencido',
                completada: 'status-completado',
                redirigida: 'status-redirigido'
            };
            return mapa[estado] || 'status-pendiente';
        }

        function buscarTramite() {
            const numero = document.getElementById('numero-seguimiento').value.trim();
            const resultado = document.getElementById('resultado-busqueda');
            const sinResultados = document.getElementById('sin-resultados');
            const contenido = document.getElementById('contenido-resultado-busqueda');
            const resumen = document.getElementById('resumen-busqueda-tramite');
            const btnBuscar = document.getElementById('btn-buscar-tramite');
            
            if (!numero) {
                resultado.classList.add('hidden');
                sinResultados.classList.remove('hidden');
                sinResultados.innerHTML = `
                    <i class="fas fa-search text-gray-300 text-4xl mb-4"></i>
                    <p class="text-gray-500">Ingrese un número de seguimiento o cédula para buscar</p>
                `;
                return;
            }
            
            btnBuscar.innerHTML = '<div class="loading mx-auto"></div> Buscando...';
            btnBuscar.disabled = true;
            
            fetch(`ajax/buscar_tramite.php?numero_seguimiento=${encodeURIComponent(numero)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error en la respuesta del servidor');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && Array.isArray(data.tramites) && data.tramites.length > 0) {
                        const lista = data.tramites;
                        const cneMB = (typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto : (x) => (x == null ? '' : String(x)));
                        const filas = lista.map((t) => {
                            const badgeClass = claseBadgeEstadoBusqueda(t.estado);
                            return `
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 whitespace-nowrap font-mono text-sm font-semibold text-blue-700">${escapeHtmlBusquedaTramite(t.numero_seguimiento)}</td>
                                    <td class="px-4 py-3 whitespace-nowrap"><span class="status-badge ${badgeClass}">${escapeHtmlBusquedaTramite(t.estado_display)}</span></td>
                                    <td class="px-4 py-3 text-sm text-gray-800 max-w-[200px]">${escapeHtmlBusquedaTramite(cneMB(t.ciudadano_nombre))}</td>
                                    <td class="px-4 py-3 whitespace-nowrap font-mono text-sm text-gray-800">${escapeHtmlBusquedaTramite(cneMB(t.ciudadano_identificacion))}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">${escapeHtmlBusquedaTramite(cneMB(t.ciudadano_genero))}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700 max-w-[160px]">${escapeHtmlBusquedaTramite(t.area_nombre)}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700 max-w-[200px]">${escapeHtmlBusquedaTramite(t.tipo_tramite_nombre)}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700 max-w-[160px]">${escapeHtmlBusquedaTramite(t.creado_por)}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">${escapeHtmlBusquedaTramite(t.fecha_registro)}</td>
                                </tr>`;
                        }).join('');

                        contenido.innerHTML = `
                            <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
                                <div class="overflow-x-auto">
                                    <table class="cne-tabla-ciudadano-mayus-dir-buscar min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">N° seguimiento</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ciudadano</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Género</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Coordinación</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo de trámite</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registrado por</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha registro</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">${filas}</tbody>
                                    </table>
                                </div>
                            </div>`;

                        if (resumen) {
                            if (lista.length > 1) {
                                resumen.textContent = `Se encontraron ${lista.length} trámites (ordenados por fecha, del más reciente al más antiguo).`;
                                resumen.classList.remove('hidden');
                            } else {
                                resumen.classList.add('hidden');
                            }
                        }

                        resultado.classList.remove('hidden');
                        sinResultados.classList.add('hidden');
                    } else {
                        if (resumen) resumen.classList.add('hidden');
                        const msg = escapeHtmlBusquedaTramite(data.message || 'No se encontraron resultados');
                        contenido.innerHTML = `
                            <div class="rounded-xl border border-amber-100 bg-amber-50 px-6 py-10 text-center">
                                <i class="fas fa-folder-open text-amber-500 text-4xl mb-4"></i>
                                <p class="text-gray-800 font-medium">${msg}</p>
                            </div>`;
                        resultado.classList.remove('hidden');
                        sinResultados.classList.add('hidden');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (resumen) resumen.classList.add('hidden');
                    contenido.innerHTML = `
                        <div class="rounded-xl border border-red-100 bg-red-50 px-6 py-10 text-center">
                            <i class="fas fa-exclamation-circle text-red-500 text-4xl mb-4"></i>
                            <p class="text-red-700 font-medium">Error al buscar el trámite</p>
                            <p class="text-gray-600 text-sm mt-2">Verifique su conexión o intente nuevamente</p>
                        </div>`;
                    resultado.classList.remove('hidden');
                    sinResultados.classList.add('hidden');
                })
                .finally(() => {
                    btnBuscar.innerHTML = '<i class="fas fa-search"></i> <span class="ml-2">Buscar</span>';
                    btnBuscar.disabled = false;
                });
        }
        
        function buscarTramiteEspecifico(numeroSeguimiento) {
            tramiteParaBuscar = numeroSeguimiento;
            document.querySelector('[data-section="buscar-tramite"]').click();
        }
        
        function verDetalleSolicitud(numeroSeguimiento) {
            alert(`Mostrando detalles de la solicitud: ${numeroSeguimiento}`);
        }
        
        // Eventos de los modales
        document.getElementById('btn-nueva-solicitud')?.addEventListener('click', function() {
            document.getElementById('successModal').classList.remove('active');
            document.querySelector('[data-section="nueva-solicitud"]').click();
        });
        
        document.getElementById('btn-imprimir')?.addEventListener('click', function() {
            window.print();
        });
        
        // Manejar redimensionamiento de ventana
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                document.getElementById('sidebar').classList.remove('mobile-hidden');
                document.getElementById('sidebar').classList.add('mobile-visible');
                document.getElementById('menu-overlay').classList.remove('active');
            } else {
                document.getElementById('sidebar').classList.remove('mobile-visible');
                document.getElementById('sidebar').classList.add('mobile-hidden');
            }
        });
    </script>
</body>
</html>
