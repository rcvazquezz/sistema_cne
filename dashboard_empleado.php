<?php
session_start();
require_once 'config/database.php';

// Verificar autenticación y rol por rol_id (2 = Funcionario)
if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 2) {
    header('Location: auth/login.php');
    exit();
}

$db = getDB();
$usuario_id = $_SESSION['user_id'];
limpiarSesionesExpiradas();
actualizarSesionUltimaActividad($usuario_id);
$usuario = obtenerUsuario($usuario_id);
require_once __DIR__ . '/includes/cne_admin_view_context.php';
cneAplicarContextoAdminView($usuario);
$coordinacion_nombre = $usuario['coordinacion_nombre'] ?? ($_SESSION['coordinacion_nombre'] ?? '');
$coordinacion_id = $usuario['coordinacion_id'] ?? ($_SESSION['acoordinacion_id'] ?? null);

// Tipos de trámite (padre) para filtros del historial — mismo criterio que dashboard_entrada
$stmt = $db->query("SELECT tramite_id as id, tramite_nombre as nombre FROM tramite WHERE tramite_padre_id IS NULL ORDER BY tramite_nombre");
$tipos_tramite = $stmt->fetchAll();

// Coordinaciones elegibles como destino de redirección (sin Oficina de Atención al Ciudadano)
$stmt = $db->query(
    "SELECT coordinacion_id as id, coordinacion_nombre as nombre FROM coordinacion WHERE coordinacion_estado = 'activo' AND " . sqlCoordinacionesRedireccionPermitidas() . " ORDER BY coordinacion_nombre"
);
$coordinaciones = $stmt->fetchAll();

// Áreas activas para Nueva Solicitud (todas las coordinaciones excepto OAC — alineado con dashboard_entrada)
$stmt = $db->query("SELECT coordinacion_id as id, coordinacion_nombre as nombre FROM coordinacion WHERE coordinacion_estado = 'activo' AND coordinacion_nombre NOT LIKE 'Oficina de Atención al Ciudadano' ORDER BY coordinacion_nombre");
$areas_solicitud = $stmt->fetchAll();

// Datos para formulario Nueva Solicitud
$stmt = $db->query("SELECT institucion_id as id, institucion_nombre as nombre FROM institucion ORDER BY institucion_nombre");
$instituciones = $stmt->fetchAll();
$stmt = $db->query("SELECT estado_id as id, estado_nombre as nombre FROM estados ORDER BY estado_nombre");
$estados = $stmt->fetchAll();
$stmt = $db->query("SELECT municipio_id as id, municipio_nombre as nombre, estado_id FROM municipios ORDER BY municipio_nombre");
$municipios = $stmt->fetchAll();
$municipios_por_estado = [];
foreach ($municipios as $m) {
    $eid = $m['estado_id'];
    if (!isset($municipios_por_estado[$eid])) $municipios_por_estado[$eid] = [];
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
$CNE_RT = ['dashboard' => 'empleado', 'coord' => (int) ($coordinacion_id ?? 0)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/includes/head_viewport.php'; ?>
    <title>Sistema CNE - Dashboard Funcionario</title>
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
        .section.hidden { display: none; }
        .menu-item.active { border-left-color: #3b82f6; background-color: rgba(59,130,246,0.12); }
        .status-badge { padding: 6px 10px; border-radius: 8px; font-weight: 600; font-size: 12px; display:inline-block; }
        .status-pendiente { background:#fff7ed; color:#c2410c; border:1px solid #fdba74; }
        .status-proceso { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; }
        .status-completado { background:#ecfdf5; color:#065f46; border:1px solid #6ee7b7; }
        .status-cancelado { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; }
        .status-redirigido { background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe; }
        /* Vencido: gris (diferenciado de completado / verde) */
        .status-vencido { background:#e9ecef; color:#343a40; border:1px solid #6c757d; }
        .status-invalidada { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .status-en-caracas { background:#ccfbf1; color:#0d9488; border:1px solid #5eead4; }
        /* Historial: badges compactos sin ensanchar la tabla */
        #tabla-historial-empleado .status-badge { padding: 2px 6px; font-size: 11px; border-radius: 6px; font-weight: 600; line-height: 1.25; }
        #tabla-historial-empleado .btn { padding: 6px 10px; font-size: 0.75rem; gap: 6px; border-radius: 8px; }
        #tabla-historial-empleado { table-layout: auto; }
        #tabla-historial-empleado thead th,
        #tabla-historial-empleado tbody td { padding: 0.45rem 1rem; line-height: 1.35; vertical-align: top; }
        #tabla-historial-empleado .historial-col-tipo { max-width: 40%; }
        .table-responsive { overflow-x: auto; }
        .historial-table-wrap { overflow-x: auto; max-width: 100%; min-width: 0; }
        .custom-scrollbar { scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f1f1; }
        .custom-scrollbar::-webkit-scrollbar { height: 8px; width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.4); z-index: 50; }
        .modal.active { display: flex; }
        /* Detalles de solicitud: base del apilamiento; por encima del sidebar (40) y overlay móvil (30) */
        #solicitud-modal.modal { z-index: 50; }
        /* Redirigir: encima de Detalles (50) y del historial (60); fondo oscuro adicional en capa interna */
        #redirigir-modal.modal {
            z-index: 70;
            background: transparent;
        }
        #redirigir-modal .modal-backdrop-redirigir {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
        }
        #redirigir-modal .modal-content-redirigir {
            max-width: 28rem;
            width: 95%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        #invalidar-modal.modal {
            z-index: 70;
            background: transparent;
        }
        #invalidar-modal .modal-backdrop-invalidar {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
        }
        #invalidar-modal .modal-content-invalidar {
            max-width: 28rem;
            width: 95%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        }
        .modal-content { background: #fff; width: 95%; max-width: 900px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
        #solicitud-modal .modal-content-solicitud { display: flex; flex-direction: column; max-height: 90vh; min-height: 0; overflow: hidden; }
        /* Timeline historial (alineado con dashboard director) */
        .timeline-emp { position: relative; margin-left: 8px; }
        .timeline-emp-item { position: relative; padding-left: 1.75rem; padding-bottom: 1.35rem; border-left: 2px solid #e2e8f0; }
        .timeline-emp-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-emp-dot { position: absolute; left: -7px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #3b82f6; border: 2px solid #fff; box-shadow: 0 0 0 2px #bfdbfe; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; font-weight:600; }
        .btn-primary { background:#3b82f6; color:#fff; }
        .btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
        .btn-danger { background:#ef4444; color:#fff; }
        .btn-success { background:#10b981; color:#fff; }
        .btn-warning { background:#f59e0b; color:#fff; }
        /* Transición para toasts */
        .toast {
            transition: opacity 0.3s ease-in-out;
        }
        .toast.fade-out {
            opacity: 0;
        }

        /* ===== INICIO: Estilos del menú responsive (copiados de dashboard_entrada.php) ===== */
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
        /* ===== FIN: Estilos del menú responsive ===== */
        .input-error { border-color: #ef4444 !important; }
        .error-message { color: #ef4444; font-size: 12px; margin-top: 4px; }
        .input-success { border-color: #10b981 !important; }
        .campos-desde-ciudadano,
        .custom-select-button.campos-desde-ciudadano {
            border-color: #10b981 !important;
        }
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
        .btn-tramite-inmediato { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; transition: all 0.3s; }
        .btn-tramite-inmediato:hover:not(:disabled) { background: linear-gradient(135deg, #059669 0%, #047857 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-tramite-inmediato:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        #seccion-nueva-solicitud .select2-container { width: 100% !important; max-width: 100%; }
        #seccion-nueva-solicitud .select2-selection__rendered {
            white-space: normal !important; overflow: visible !important; text-overflow: clip !important;
            min-height: 45px; height: auto !important; line-height: 1.35; padding-right: 2.25rem !important; box-sizing: border-box;
        }
        .tramite-search-wrapper { position: relative; width: 100% !important; max-width: 100%; min-width: 0; box-sizing: border-box; }
        .tramite-search-button {
            width: 100%; padding: 10px 2.5rem 10px 12px; border: 2px solid #d1d5db; border-radius: 0.5rem; background: #fff; text-align: left;
            display: flex; align-items: center; justify-content: space-between; cursor: pointer; min-height: 45px; height: auto; transition: all 0.2s; box-sizing: border-box;
        }
        .tramite-search-button:hover { border-color: #3b82f6; }
        .tramite-search-button:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        .tramite-search-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; border: 1px solid #e5e7eb; border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 50; max-height: 350px; overflow: hidden; display: none; background: #fff;
        }
        .tramite-search-dropdown.open { display: block; }
        .tramite-search-input { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 14px; transition: all 0.2s; }
        .tramite-search-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        .tramite-search-option { padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background-color 0.2s; }
        .tramite-search-option:last-child { border-bottom: none; }
        .tramite-search-option:hover { background: #f3f4f6; }
        .tramite-placeholder { color: #9ca3af; font-style: italic; }
        .loading-input { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%233b82f6' stroke-width='2'%3E%3Cpath d='M21 12a9 9 0 1 1-6.219-8.56'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; background-size: 20px; padding-right: 40px !important; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .cedula-tipo-compact { min-width: 60px; }
        .telefono-codigo-compact { min-width: 80px; }
        .cedula-input-compact { min-width: 120px; }
        .telefono-input-compact { min-width: 120px; }
        @media (max-width: 768px) {
            .cedula-tipo-compact { min-width: 50px; }
            .telefono-codigo-compact { min-width: 70px; }
            .cedula-input-compact { min-width: 100px; }
            .telefono-input-compact { min-width: 100px; }
        }

        .tramite-search-button .selected-tramite-content { display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1; padding-right: 0.25rem; }
        .tramite-search-button .selected-tramite-text {
            white-space: normal !important; overflow: visible !important; text-overflow: clip !important;
            word-break: break-word; line-height: 1.35; max-width: none !important;
        }
        .tramite-search-button .chevron { flex-shrink: 0; margin-left: 0.5rem; transition: transform 0.3s; }
        .tramite-search-button.open .chevron { transform: rotate(180deg); }
        .tramite-search-button.input-error { border-color: #ef4444 !important; }
        .tramite-search-button.input-success { border-color: #10b981 !important; }
        .tramite-search-button.campos-desde-ciudadano { border-color: #10b981 !important; }
        .tramite-search-button.ciudadano-dato-alterado { border-color: #ef4444 !important; }
        .tramite-search-button.ciudadano-campo-protegido { border-color: #10b981 !important; }
        .tramite-search-button.ciudadano-campo-na-editable { border-color: #f59e0b !important; }
        .tramite-search-input-container { padding: 12px; border-bottom: 1px solid #e5e7eb; background-color: #f9fafb; position: sticky; top: 0; z-index: 10; }
        .tramite-search-results { max-height: 250px; overflow-y: auto; }
        .tramite-search-option.selected { background-color: #eff6ff; color: #1e40af; font-weight: 500; position: relative; }
        .tramite-search-option.selected::after { content: "✓"; position: absolute; right: 12px; color: #1e40af; font-weight: bold; }
        .no-results-message { padding: 20px; text-align: center; color: #6b7280; font-style: italic; }
        .searching-indicator { padding: 12px; text-align: center; color: #6b7280; font-style: italic; }
        .search-highlight { background-color: #fef3c7; padding: 0 2px; border-radius: 2px; }
        @media (max-width: 768px) {
            .tramite-search-dropdown { max-height: 300px; }
            .tramite-search-results { max-height: 200px; }
        }

        #confirmModalEmp, #successModalEmp, #confirmCompletadoModalEmp {
            position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 9999;
            display: none; align-items: center; justify-content: center; padding: 1rem; animation: fadeIn 0.3s ease;
        }
        #confirmModalEmp.active, #successModalEmp.active, #confirmCompletadoModalEmp.active { display: flex; }
        #confirmModalEmp .modal-content-emp, #successModalEmp .modal-content-emp, #confirmCompletadoModalEmp .modal-content-emp {
            background: #fff; border-radius: 12px; padding: 1.5rem; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); animation: slideUp 0.3s ease; margin: 0 auto;
        }
        #successModalEmp .modal-content-emp { padding: 2rem; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 768px) {
            #confirmModalEmp .modal-content-emp, #successModalEmp .modal-content-emp, #confirmCompletadoModalEmp .modal-content-emp {
                margin: 0; max-width: 95%; padding: 1.25rem;
            }
        }

        /* ===== INICIO: Select personalizado para género ===== */
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
        /* ===== FIN: Select personalizado para género ===== */
    </style>
</head>
<body class="font-sans antialiased">
    <?php if (!empty($_SESSION['is_admin_viewing'])): ?>
    <a href="auth/admin_exit_view.php" class="fixed bottom-4 right-4 z-[100] inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold shadow-lg hover:bg-indigo-700 border border-indigo-400/40 transition-colors" title="Restaurar sesión de administrador">
        <i class="fas fa-arrow-left"></i> Volver a Admin
    </a>
    <?php endif; ?>
    <!-- Overlay para menú móvil (agregado) -->
    <div class="menu-overlay" id="menu-overlay"></div>

    <div class="flex layout-shell w-full min-h-screen min-w-0">
        <!-- Sidebar (modificado: se añade clase mobile-hidden por defecto) -->
        <aside class="sidebar bg-gray-800 text-white flex flex-col mobile-hidden" id="sidebar">
            <div class="sidebar-header px-6 py-5 border-b border-gray-700 flex justify-between items-center gap-2">
                <div class="logo-container flex items-center justify-center w-full min-w-0">
                    <img src="recursos/Logo.png" alt="Logo CNE" class="logo-img max-w-40 max-h-16 object-contain">
                </div>
                <button type="button" class="menu-close-btn text-white text-lg lg:hidden shrink-0 p-2 rounded-lg hover:bg-white/10" id="menu-close-btn" aria-label="Cerrar menú"><i class="fas fa-times"></i></button>
            </div>
            <nav class="menu flex-1 py-4">
                <ul class="list-none">
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all duration-300 active" data-section="pendientes">
                        <i class="fas fa-hourglass-half w-5 text-center"></i> 
                        <span>Trámites Pendientes</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all duration-300" data-section="nueva-solicitud">
                        <i class="fas fa-plus-circle w-5 text-center"></i> 
                        <span>Nueva Solicitud</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all duration-300" data-section="historial">
                        <i class="fas fa-history w-5 text-center"></i> 
                        <span>Historial</span>
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
            <!-- Header -->
            <header class="header bg-white px-4 md:px-6 py-4 shadow-sm">
                <div class="header-content flex justify-between items-center">
                    <div class="flex items-center">
                        <button class="menu-btn bg-blue-500 text-white p-2 rounded-lg items-center justify-center mr-2 lg:hidden" id="menu-btn">
                            <i class="fas fa-bars text-base"></i>
                        </button>
                        <h1 class="text-lg md:text-xl font-semibold text-gray-800" id="section-title">Trámites Pendientes</h1>
                    </div>
                    
                    <!-- Contenedor derecho reorganizado: texto | notificación | usuario -->
                    <div class="flex items-center gap-3 md:gap-4">
                        <!-- Texto Usuario | Coordinación -->
                        <div class="text-right">
                            <p class="font-semibold text-gray-800 leading-none"><?php echo htmlspecialchars(($_SESSION['nombre_completo'] ?? 'Usuario') . ' | ' . ($coordinacion_nombre ?: 'Sin coordinación')); ?></p>
                            <p class="text-xs text-gray-500">Funcionario</p>
                        </div>
                        
                        <!-- Campana de Notificaciones -->
                        <div class="relative">
                            <button id="btn-notificaciones" class="relative rounded-full w-10 h-10 flex items-center justify-center bg-blue-500 text-white hover:bg-blue-600 transition-colors">
                                <i class="fas fa-bell"></i>
                                <span id="badge-notificaciones" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs px-2 py-0.5 rounded-full hidden">0</span>
                            </button>
                            <div id="panel-notificaciones" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 hidden z-50">
                                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                                    <p class="font-semibold text-gray-700">Notificaciones</p>
                                    <button id="btn-cerrar-panel" class="text-gray-500 hover:text-gray-700">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div id="lista-notificaciones" class="max-h-80 overflow-y-auto">
                                    <div class="px-4 py-6 text-center text-gray-500">Sin notificaciones</div>
                                </div>
                                <div class="px-4 py-2 border-t border-gray-100">
                                    <button id="btn-marcar-todo" class="w-full text-sm text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md px-2 py-2 transition-colors">
                                        Marcar todo como leído
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Botón de Usuario con dropdown -->
                        <div class="relative">
                            <button id="user-dropdown-btn" class="rounded-full w-10 h-10 flex items-center justify-center bg-blue-500 text-white hover:bg-blue-600 transition-colors">
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
            <script>
                const MI_COORDINACION_ID = <?php echo $coordinacion_id ? (int)$coordinacion_id : 'null'; ?>;
                /** Igual que plazo en rowHistorial / backend (cneEmpleadoDiasPlazoVencimientoTramite). */
                function esTramiteVencidoNoGestion(s) {
                    if (!s) return false;
                    if (s.estado_para_reporte === 'vencida') return true;
                    if (s.solicitud_estado === 'vencida' || s.solicitud_estado === 'rechazada') return true;
                    const isCompletada = s.solicitud_estado === 'completada';
                    const isRedirigida = s.solicitud_estado === 'redirigida';
                    if (s.recibido_fecha && !isCompletada && !isRedirigida) {
                        const rec = new Date(String(s.recibido_fecha).replace(' ', 'T'));
                        const venc = new Date(rec.getTime() + 60 * 24 * 60 * 60 * 1000);
                        if (new Date() > venc) return true;
                    }
                    return false;
                }
            </script>

            <!-- Sección: Nueva Solicitud (alineada con dashboard_entrada) -->
            <section class="section hidden" id="seccion-nueva-solicitud">
                <div class="form-container bg-white rounded-xl shadow-lg">
                    <div class="max-w-6xl mx-auto p-6 md:p-8">
                        <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-6 pb-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                            <span class="flex items-center gap-3 min-w-0">
                                <i class="fas fa-file-alt text-blue-500 shrink-0"></i>
                                <span>Registrar Nueva Solicitud</span>
                            </span>
                            <button type="button" id="btn-limpiar-formulario-empleado" class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 flex items-center gap-2 shrink-0" title="Vaciar el formulario y desbloquear campos">
                                <i class="fas fa-broom" aria-hidden="true"></i>
                                <span>Limpiar</span>
                            </button>
                        </h2>
                        <form id="tramitante-form-empleado" method="POST" autocomplete="off">
                            <input type="hidden" id="tipo_solicitud-empleado" name="tipo_solicitud" value="normal">
                            <input type="hidden" name="id_coordinacion_destino" id="id_coordinacion_destino-empleado" value="">

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-6">
                                <div id="cedula-container-empleado" class="relative">
                                    <label class="block mb-2 font-semibold text-gray-700">Cédula <span class="text-gray-500 font-normal">(opcional)</span></label>
                                    <div class="flex flex-row items-center gap-2">
                                        <select id="cedula-tipo-empleado" name="cedula_tipo" class="cedula-tipo-compact p-3 border-2 border-gray-300 rounded-lg font-bold">
                                            <option value="V">V</option>
                                            <option value="E">E</option>
                                            <option value="J">J</option>
                                            <option value="G">G</option>
                                        </select>
                                        <span class="font-bold text-gray-500">-</span>
                                        <input type="text" id="cedula-numero-empleado" name="cedula_numero"
                                            class="cedula-input-compact flex-1 min-w-0 p-3 border-2 border-gray-300 rounded-lg font-mono"
                                            placeholder="12345678" maxlength="8"
                                            oninput="validarCedulaEmpleado(this); programarBusquedaCiudadanoPorCedulaEmpleado();">
                                    </div>
                                    <div id="error-cedula-empleado" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label class="block mb-2 font-semibold text-gray-700">Teléfono <span class="text-gray-500 font-normal">(opcional)</span></label>
                                    <div class="flex flex-row items-center gap-2">
                                        <select id="telefono-codigo-empleado" name="telefono_codigo" class="telefono-codigo-compact p-3 border-2 border-gray-300 rounded-lg">
                                            <option value="0412">0412</option>
                                            <option value="0414">0414</option>
                                            <option value="0416">0416</option>
                                            <option value="0424">0424</option>
                                            <option value="0426">0426</option>
                                        </select>
                                        <span class="font-bold text-gray-500">-</span>
                                        <input type="text" id="telefono-numero-empleado" name="telefono_numero"
                                            class="telefono-input-compact flex-1 min-w-0 p-3 border-2 border-gray-300 rounded-lg"
                                            placeholder="1234567" maxlength="7" oninput="validarTelefonoEmpleado(this)">
                                    </div>
                                    <div id="error-telefono-empleado" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label for="genero-empleado" class="block mb-2 font-semibold text-gray-700">Género del Documento *</label>
                                    <div class="custom-select-wrapper">
                                        <select id="genero-empleado" name="genero" class="hidden" required>
                                            <option value="">Seleccione un género</option>
                                            <option value="masculino">Masculino</option>
                                            <option value="femenino">Femenino</option>
                                        </select>
                                        <button type="button" class="custom-select-button" id="custom-genero-button-emp">
                                            <span class="selected-content">
                                                <span class="selected-icon text-gray-400" id="selected-icon-emp">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M7 11a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M19 3l-5 5" /><path d="M15 3h4v4" /><path d="M11 16v6" /><path d="M8 19h6" /></svg>
                                                </span>
                                                <span id="selected-text-emp" class="text-gray-400">Seleccione un género</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        <div class="custom-select-dropdown" id="custom-genero-dropdown-emp">
                                            <div class="custom-select-option" data-value="">
                                                <div class="option-icon text-gray-400">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M7 11a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M19 3l-5 5" /><path d="M15 3h4v4" /><path d="M11 16v6" /><path d="M8 19h6" /></svg>
                                                </div>
                                                <span>Seleccione un género</span>
                                            </div>
                                            <div class="custom-select-option" data-value="masculino">
                                                <div class="option-icon text-blue-500">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10 16v5" /><path d="M14 16v5" /><path d="M9 9h6l-1 7h-4l-1 -7" /><path d="M5 11c1.333 -1.333 2.667 -2 4 -2" /><path d="M19 11c-1.333 -1.333 -2.667 -2 -4 -2" /><path d="M10 4a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /></svg>
                                                </div>
                                                <span>Masculino</span>
                                            </div>
                                            <div class="custom-select-option" data-value="femenino">
                                                <div class="option-icon text-pink-500">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10 16v5" /><path d="M14 16v5" /><path d="M8 16h8l-2 -7h-4l-2 7" /><path d="M5 11c1.667 -1.333 3.333 -2 5 -2" /><path d="M19 11c-1.667 -1.333 -3.333 -2 -5 -2" /><path d="M10 4a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /></svg>
                                                </div>
                                                <span>Femenino</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="error-genero-empleado" class="error-message hidden"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <div>
                                    <label for="nombres-empleado" class="block mb-2 font-semibold text-gray-700">Nombres</label>
                                    <input type="text" id="nombres-empleado" name="nombres"
                                        class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        placeholder="Ingrese los nombres" oninput="validarNombreEmpleado(this)">
                                    <div id="error-nombres-empleado" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label for="apellidos-empleado" class="block mb-2 font-semibold text-gray-700">Apellidos</label>
                                    <input type="text" id="apellidos-empleado" name="apellidos"
                                        class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                        placeholder="Ingrese los apellidos" oninput="validarApellidoEmpleado(this)">
                                    <div id="error-apellidos-empleado" class="error-message hidden"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <div>
                                    <label for="fecha_nacimiento-empleado" class="block mb-2 font-semibold text-gray-700">Fecha de Nacimiento</label>
                                    <div class="relative">
                                        <input type="text" id="fecha_nacimiento-empleado" name="fecha_nacimiento" readonly
                                            class="w-full p-3 md:p-4 pr-10 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                            placeholder="dd/mm/aaaa">
                                        <i class="fas fa-calendar-alt absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                                    </div>
                                </div>
                                <div>
                                    <label for="estado-search-button-empleado" class="block mb-2 font-semibold text-gray-700">Estado</label>
                                    <div class="tramite-search-wrapper">
                                        <button type="button" class="tramite-search-button" id="estado-search-button-empleado" aria-haspopup="listbox">
                                            <span class="selected-tramite-content">
                                                <span class="selected-tramite-text tramite-placeholder">Seleccione un estado</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        <div class="tramite-search-dropdown" id="estado-search-dropdown-empleado">
                                            <div class="tramite-search-input-container">
                                                <input type="text" class="tramite-search-input" id="estado-search-input-empleado" placeholder="Buscar estado..." autocomplete="off" aria-label="Buscar estado">
                                            </div>
                                            <div class="tramite-search-results" id="estado-search-results-empleado"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="estado_id-empleado" name="estado_id" value="">
                                    <div id="error-estado-empleado" class="error-message hidden"></div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <div class="space-y-4">
                                    <div>
                                        <label for="municipio-search-button-empleado" class="block mb-2 font-semibold text-gray-700">Municipio</label>
                                        <div class="tramite-search-wrapper">
                                            <button type="button" class="tramite-search-button" id="municipio-search-button-empleado" disabled aria-haspopup="listbox">
                                                <span class="selected-tramite-content">
                                                    <span class="selected-tramite-text tramite-placeholder">Seleccione un municipio</span>
                                                </span>
                                                <i class="fas fa-chevron-down chevron"></i>
                                            </button>
                                            <div class="tramite-search-dropdown" id="municipio-search-dropdown-empleado">
                                                <div class="tramite-search-input-container">
                                                    <input type="text" class="tramite-search-input" id="municipio-search-input-empleado" placeholder="Buscar municipio..." autocomplete="off" aria-label="Buscar municipio" disabled>
                                                </div>
                                                <div class="tramite-search-results" id="municipio-search-results-empleado"></div>
                                            </div>
                                        </div>
                                        <input type="hidden" id="municipio_id-empleado" name="municipio_id" value="" disabled>
                                        <div id="error-municipio-empleado" class="error-message hidden"></div>
                                    </div>
                                    <div>
                                        <label for="ciudadano_email-empleado" class="block mb-2 font-semibold text-gray-700">Correo electrónico</label>
                                        <input type="email" id="ciudadano_email-empleado" name="ciudadano_email"
                                            class="w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                            placeholder="Opcional">
                                    </div>
                                </div>
                                <div>
                                    <label for="direccion-empleado" class="block mb-2 font-semibold text-gray-700">Dirección / Punto de Referencia</label>
                                    <textarea id="direccion-empleado" name="direccion" rows="3"
                                        class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 min-h-[120px]"
                                        placeholder="Especifique dirección o punto de referencia"></textarea>
                                </div>
                            </div>

                            <?php
                            $personalInstEmp = null;
                            $otrasInstEmp = [];
                            foreach ($instituciones as $inst) {
                                if (strcasecmp($inst['nombre'], 'Personal') === 0) {
                                    $personalInstEmp = $inst;
                                } else {
                                    $otrasInstEmp[] = $inst;
                                }
                            }
                            ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                                <div>
                                    <label for="institucion-search-button-empleado" class="block mb-2 font-semibold text-gray-700">Institución *</label>
                                    <div class="tramite-search-wrapper">
                                        <button type="button" class="tramite-search-button" id="institucion-search-button-empleado" aria-haspopup="listbox">
                                            <span class="selected-tramite-content">
                                                <span class="selected-tramite-text tramite-placeholder">Seleccione una institución</span>
                                            </span>
                                            <i class="fas fa-chevron-down chevron"></i>
                                        </button>
                                        <div class="tramite-search-dropdown" id="institucion-search-dropdown-empleado">
                                            <div class="tramite-search-input-container">
                                                <input type="text" class="tramite-search-input" id="institucion-search-input-empleado" placeholder="Buscar institución..." autocomplete="off" aria-label="Buscar institución">
                                            </div>
                                            <div class="tramite-search-results" id="institucion-search-results-empleado"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="institucion-empleado" name="institucion" value="<?php echo $personalInstEmp ? htmlspecialchars((string)$personalInstEmp['id']) : ''; ?>" required data-personal-id="<?php echo $personalInstEmp['id'] ?? ''; ?>">
                                    <div id="error-institucion-empleado" class="error-message hidden"></div>
                                    <div id="institucion-otro-wrapper-empleado" class="mt-3 hidden">
                                        <input type="text" id="institucion-otro-empleado" name="institucion_otro" placeholder="Ingrese el nombre de la institución"
                                            class="cne-mayus-ciudadano-live w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200" />
                                        <div id="error-institucion-otro-empleado" class="error-message hidden"></div>
                                    </div>
                                </div>
                                <div>
                                    <label for="area_id-empleado" class="block mb-2 font-semibold text-gray-700">Coordinación destino *</label>
                                    <select id="area_id-empleado" name="area_id" required
                                        class="w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                        <option value="">Seleccione una coordinación</option>
                                        <?php foreach ($areas_solicitud as $area): ?>
                                            <option value="<?php echo (int)$area['id']; ?>"<?php echo ($coordinacion_id && (int)$area['id'] === (int)$coordinacion_id) ? ' selected' : ''; ?>><?php echo htmlspecialchars($area['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="error-area-empleado" class="error-message hidden"></div>
                                </div>
                            </div>

                            <div class="mb-6">
                                <label class="block mb-2 font-semibold text-gray-700">Tipo de Trámite *</label>
                                <div class="tramite-search-wrapper">
                                    <button type="button" class="tramite-search-button" id="tramite-search-button-empleado">
                                        <span class="selected-tramite-content">
                                            <span class="selected-tramite-text tramite-placeholder" id="tramite-selected-label-empleado">Seleccione un tipo de trámite</span>
                                        </span>
                                        <i class="fas fa-chevron-down chevron"></i>
                                    </button>
                                    <div class="tramite-search-dropdown" id="tramite-search-dropdown-empleado">
                                        <div class="tramite-search-input-container">
                                            <input type="text" class="tramite-search-input" id="tramite-search-input-empleado" placeholder="Buscar tipo de trámite..." autocomplete="off">
                                        </div>
                                        <div class="tramite-search-results" id="tramite-search-results-empleado">
                                            <div class="tramite-search-option" data-value="">
                                                <span class="tramite-placeholder">Seleccione una coordinación primero</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" id="tipo_tramite_id-empleado" name="tipo_tramite_id" value="">
                                <div id="error-tipo-tramite-empleado" class="error-message hidden">Debe seleccionar un tipo de trámite</div>
                            </div>

                            <div id="subtramite-wrapper-empleado" class="mb-6 hidden">
                                <label for="subtramite_id-empleado" class="block mb-2 font-semibold text-gray-700">Variante o Subtrámite</label>
                                <select id="subtramite_id-empleado" class="w-full p-3 md:p-4 border-2 border-gray-300 rounded-lg transition-all duration-300 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                    <option value="">Seleccione una variante</option>
                                </select>
                                <div id="error-subtramite-empleado" class="error-message hidden">Debe seleccionar una variante</div>
                            </div>

                            <div id="requisitos-wrapper-empleado" class="mb-6 hidden">
                                <h3 class="text-gray-800 font-semibold mb-4 pb-2 border-b border-gray-200">Requisitos del Trámite</h3>
                                <p class="text-sm text-gray-600 mb-3">En <strong>trámite inmediato</strong> debe marcar al menos un ítem. En <strong>solicitud normal</strong> puede dejarlos sin marcar.</p>
                                <div id="requisitos-list-empleado" class="space-y-2 text-gray-700"></div>
                            </div>

                            <div id="campos-dinamicos-empleado" class="mb-6 hidden">
                                <h3 class="text-gray-800 font-semibold mb-4 pb-2 border-b border-gray-200">Información Adicional</h3>
                                <div id="campos-contenido-empleado"></div>
                            </div>

                            <div class="text-center mt-8 pt-6 border-t border-gray-200">
                                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                    <button type="button" id="btn-tramite-inmediato-empleado" class="btn-tramite-inmediato py-3 px-6 rounded-lg font-medium flex items-center gap-2">
                                        <i class="fas fa-bolt"></i>
                                        <span>Realizar Trámite Inmediato</span>
                                    </button>
                                    <p id="hint-tramite-inmediato-empleado" class="hidden text-xs text-amber-800 max-w-xs text-center leading-snug" role="status">Solo puede realizar trámites inmediatos en su propia coordinación</p>
                                    </div>
                                    <div class="text-gray-400 hidden sm:block">|</div>
                                    <button type="submit" id="btn-solicitud-normal-empleado" class="bg-blue-500 text-white py-3 px-6 rounded-lg font-medium hover:bg-blue-600 transition duration-300 flex items-center gap-2">
                                        <i class="fas fa-paper-plane"></i>
                                        <span>Enviar Solicitud Normal</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Sección: Pendientes -->
            <section class="section" id="seccion-pendientes">
                <div class="max-w-7xl mx-auto w-full p-4 md:p-6">
                    <div class="bg-gradient-to-r from-yellow-50 to-orange-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-yellow-100">
                        <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                            <i class="fas fa-hourglass text-yellow-600"></i>
                            <span>Trámites Pendientes de la Coordinación</span>
                        </h2>
                        <p class="text-sm text-gray-600">Listado filtrado por tu coordinación: <?php echo htmlspecialchars($coordinacion_nombre ?: 'N/D'); ?></p>
                    </div>
                    <div id="pendientes-container" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"></div>
                </div>
            </section>

            <!-- Sección: Historial -->
            <section class="section hidden" id="seccion-historial">
                <div class="max-w-7xl mx-auto w-full p-4 md:p-6">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-history text-blue-600"></i> 
                                    <span>Historial de Trámites</span>
                                </h2>
                                <p class="text-sm text-gray-600">Trámites procesados por tu coordinación</p>
                            </div>
                            <div class="bg-white px-4 py-3 rounded-lg shadow-sm">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-chart-line text-blue-500"></i>
                                    <div>
                                        <p class="text-xs text-gray-500">Total</p>
                                        <p class="text-lg font-bold text-gray-800" id="contador-historial">0</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow mb-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-id-card mr-1"></i> Cédula
                                </label>
                                <input type="text" id="filtro-cedula" 
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500"
                                       placeholder="Ej: V-12345678">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-flag mr-1"></i> Estado
                                </label>
                                <select id="filtro-estado" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todos</option>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="en_revision">En Proceso</option>
                                    <option value="completada">Completada</option>
                                    <option value="redirigida">Redirigida</option>
                                    <option value="vencida">Vencido</option>
                                    <option value="invalidada">Invalidada</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-file-alt mr-1"></i> Tipo de Trámite
                                </label>
                                <select id="filtro-tipo-tramite" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todos</option>
                                    <?php foreach ($tipos_tramite as $tipo): ?>
                                        <option value="<?php echo $tipo['id']; ?>"><?php echo htmlspecialchars($tipo['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-calendar mr-1"></i> Fecha desde
                                </label>
                                <input type="date" id="filtro-fecha-desde" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-calendar mr-1"></i> Fecha hasta
                                </label>
                                <input type="date" id="filtro-fecha-hasta" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                        </div>
                        <div class="flex justify-end gap-4">
                            <button id="btn-reset-filtros" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Limpiar
                            </button>
                            <button id="btn-aplicar-filtros" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Aplicar filtros
                            </button>
                        </div>
                    </div>

                    <!-- Tabla (sin scroll horizontal; texto largo hace wrap) -->
                    <div class="bg-white rounded-xl p-3 md:p-4 shadow">
                        <div class="historial-table-wrap custom-scrollbar">
                            <table id="tabla-historial-empleado" class="cne-tabla-ciudadano-mayus w-full table-auto border-collapse divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Cédula</th>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Ciudadano</th>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Estado</th>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide historial-col-tipo">Tipo de Trámite</th>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Número</th>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Fecha</th>
                                        <th class="text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="historial-body" class="bg-white divide-y divide-gray-200">
                                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">Sin datos</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Modales Nueva Solicitud (funcionario) -->
    <div id="confirmModalEmp">
        <div class="modal-content-emp">
            <div class="flex items-center gap-3 mb-4">
                <div class="bg-blue-100 p-2 rounded-full"><i class="fas fa-check-circle text-blue-500 text-xl"></i></div>
                <h3 class="text-lg font-semibold">Confirmar Envío de Solicitud</h3>
            </div>
            <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                <div class="flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    <p class="text-sm text-blue-700">Esta solicitud iniciará en estado <strong>PENDIENTE</strong> para seguimiento</p>
                </div>
            </div>
            <div id="confirm-details-emp" class="cne-bloque-datos-ciudadano mb-6 bg-gray-50 p-4 rounded-lg space-y-3 text-left"></div>
            <div class="flex justify-end gap-3">
                <button type="button" id="cancelBtnEmp" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Cancelar</button>
                <button type="button" id="confirmBtnEmp" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 flex items-center gap-2">
                    <span>Confirmar</span><i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="confirmCompletadoModalEmp">
        <div class="modal-content-emp">
            <div class="flex items-center gap-3 mb-4">
                <div class="bg-green-100 p-2 rounded-full"><i class="fas fa-bolt text-green-500 text-xl"></i></div>
                <h3 class="text-lg font-semibold">Confirmar Trámite Inmediato</h3>
            </div>
            <div class="mb-4 p-3 bg-green-50 rounded-lg">
                <div class="flex items-center gap-2">
                    <i class="fas fa-info-circle text-green-500"></i>
                    <p class="text-sm text-green-700">Este trámite se marcará como <strong>COMPLETADO</strong> automáticamente</p>
                </div>
            </div>
            <div id="confirm-completado-details-emp" class="cne-bloque-datos-ciudadano mb-6 bg-gray-50 p-4 rounded-lg space-y-3 text-left"></div>
            <div class="flex justify-end gap-3">
                <button type="button" id="cancelCompletadoBtnEmp" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Cancelar</button>
                <button type="button" id="confirmCompletadoBtnEmp" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 flex items-center gap-2">
                    <span>Confirmar</span><i class="fas fa-bolt"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="successModalEmp">
        <div class="modal-content-emp text-center">
            <div class="bg-green-100 text-green-500 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold mb-2">¡Solicitud Registrada!</h3>
            <p class="text-gray-600 mb-4" id="success-message-emp">La solicitud ha sido registrada exitosamente en el sistema</p>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-left w-full">
                <p class="text-sm font-medium text-blue-800 mb-1">Número de Seguimiento:</p>
                <p id="numero-seguimiento-generado-emp" class="text-xl font-bold text-blue-600 font-mono"></p>
                <div id="estado-info-emp" class="mt-2"></div>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 justify-center w-full">
                <button type="button" id="btn-nueva-solicitud-emp" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">Nueva Solicitud</button>
                <button type="button" id="btn-imprimir-emp" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"><i class="fas fa-print mr-2"></i> Imprimir</button>
            </div>
        </div>
    </div>

    <!-- Modal Solicitud -->
    <div id="solicitud-modal" class="modal p-3 sm:p-4">
        <div class="modal-content modal-content-solicitud w-full">
            <div class="shrink-0 px-4 py-3 sm:px-6 sm:py-4 border-b border-gray-200 flex items-center justify-between gap-3">
                <h3 class="text-base sm:text-lg font-semibold">Detalles de la Solicitud</h3>
                <button id="modal-close" class="text-gray-500 hover:text-gray-700 shrink-0" type="button" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain custom-scrollbar px-4 py-4 sm:px-6 sm:py-5 space-y-4">
                <div id="modal-datos" class="cne-bloque-datos-ciudadano grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4"></div>
                <div>
                    <h4 class="text-gray-800 font-semibold mb-2">Requisitos</h4>
                    <div id="modal-requisitos-toolbar" class="hidden mb-3 flex flex-wrap items-center gap-2 sm:gap-3">
                        <button type="button" id="btn-modal-seleccionar-todos-requisitos" class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-900 shadow-sm transition hover:bg-blue-100 hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-500/40 active:scale-[0.98]">
                            <i class="fas fa-check-double text-blue-700" aria-hidden="true"></i>
                            Seleccionar todos los requisitos
                        </button>
                        <p class="text-xs text-gray-500 leading-snug max-w-md">Asesoría no se incluye: debe marcarse manualmente.</p>
                    </div>
                    <div id="modal-requisitos" class="space-y-2"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Código Interno</label>
                        <input type="text" id="modal-codigo" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500" placeholder="Ingrese código interno">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Observaciones *</label>
                        <textarea id="modal-observaciones" rows="3" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500" placeholder="Escriba observaciones (mín. 10 caracteres)"></textarea>
                        <p id="obs-counter" class="text-xs mt-1 text-gray-500">Mínimo 10 caracteres: 0/10</p>
                    </div>
                </div>
            </div>
            <div class="shrink-0 px-4 py-3 sm:px-6 sm:py-4 border-t border-gray-200 flex flex-wrap gap-2 sm:gap-3 justify-end bg-white">
                <button class="btn btn-primary opacity-50 cursor-not-allowed" id="btn-iniciar" disabled><i class="fas fa-play"></i> Iniciar</button>
                <button class="btn btn-success opacity-50 cursor-not-allowed" id="btn-completar" disabled><i class="fas fa-check"></i> Completar</button>
                <button class="btn btn-secondary opacity-50 cursor-not-allowed" id="btn-enviar" disabled><i class="fas fa-paper-plane"></i> Enviar a Caracas</button>
                <button class="btn btn-secondary opacity-50 cursor-not-allowed" id="btn-recibir" disabled><i class="fas fa-inbox"></i> Recibido de Caracas</button>
                <button class="btn btn-warning opacity-50 cursor-not-allowed" id="btn-redirigir" disabled><i class="fas fa-share"></i> Redirigir</button>
                <button type="button" class="btn btn-danger opacity-50 cursor-not-allowed" id="btn-invalidar" disabled><i class="fas fa-ban"></i> Invalidar</button>
                <button class="btn btn-secondary" id="btn-detalles"><i class="fas fa-info-circle"></i> Detalles</button>
            </div>
        </div>
    </div>

    <!-- Modal línea de tiempo (historial / auditoría) — encima del modal de gestión -->
    <div id="modal-historial-tramite" class="fixed inset-0 z-[60] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="modal-historial-titulo">
        <div class="absolute inset-0 bg-slate-900/55" data-close-historial-modal></div>
        <div class="relative bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[88vh] flex flex-col border border-gray-200 overflow-hidden">
            <div id="modal-historial-header" class="flex justify-between items-center px-5 py-4 bg-gradient-to-r from-blue-900 via-blue-800 to-blue-600 text-white shrink-0">
                <h3 id="modal-historial-titulo" class="font-semibold text-base md:text-lg pr-4 flex flex-wrap items-center gap-y-1">
                    <span><i class="fas fa-route mr-2 opacity-90"></i>Seguimiento — <span id="modal-historial-numero" class="font-mono font-bold"></span></span>
                    <span id="modal-historial-estado-wrap" class="hidden"><span id="modal-historial-estado-badge" class="status-badge status-vencido text-xs"></span></span>
                </h3>
                <button type="button" class="p-2 rounded-lg hover:bg-white/15 transition shrink-0" data-close-historial-modal aria-label="Cerrar"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="overflow-y-auto custom-scrollbar p-5 md:p-6 flex-1 bg-gray-50/80" id="modal-historial-timeline"></div>
        </div>
    </div>

    <!-- Modal Redirigir (sub-tarea sobre Detalles; no cerrar el modal de solicitud al abrir) -->
    <div id="redirigir-modal" class="modal p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="redirigir-modal-titulo">
        <div class="modal-backdrop-redirigir" id="redirigir-backdrop" aria-hidden="true"></div>
        <div class="modal-content modal-content-redirigir relative z-[1] flex flex-col max-h-[90vh] min-h-0 overflow-hidden rounded-xl border border-amber-200/80">
            <div class="px-5 py-3 border-b border-amber-100 bg-amber-50/80 flex items-center justify-between gap-2 shrink-0">
                <div>
                    <p class="text-xs font-medium text-amber-800/90 uppercase tracking-wide">Sub-tarea</p>
                    <h3 id="redirigir-modal-titulo" class="text-base font-semibold text-gray-900">Redirigir Trámite</h3>
                </div>
                <button type="button" id="redirigir-close" class="text-gray-500 hover:text-gray-700 p-1 rounded-lg hover:bg-white/80 shrink-0" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-5 sm:p-6 space-y-4 overflow-y-auto custom-scrollbar flex-1 min-h-0">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Seleccionar Coordinación destino</label>
                    <select id="redirigir-select" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        <option value="">Seleccione una coordinación</option>
                        <?php foreach ($coordinaciones as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars(presentarNombreCoordinacionUi($c['nombre'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">No se mostrará la coordinación actual del trámite.</p>
                </div>
            </div>
            <div class="px-5 py-3 sm:px-6 sm:py-4 border-t border-gray-200 bg-gray-50/90 flex flex-wrap gap-3 justify-end shrink-0">
                <button type="button" class="btn btn-warning" id="redirigir-confirm"><i class="fas fa-share"></i> Confirmar Redirección</button>
            </div>
        </div>
    </div>

    <!-- Modal Invalidar (motivo obligatorio; mismo apilamiento que Redirigir) -->
    <div id="invalidar-modal" class="modal p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="invalidar-modal-titulo">
        <div class="modal-backdrop-invalidar" id="invalidar-backdrop" aria-hidden="true"></div>
        <div class="modal-content modal-content-invalidar relative z-[1] flex flex-col max-h-[90vh] min-h-0 overflow-hidden rounded-xl border border-red-200/80">
            <div class="px-5 py-3 border-b border-red-100 bg-red-50/80 flex items-center justify-between gap-2 shrink-0">
                <div>
                    <p class="text-xs font-medium text-red-800/90 uppercase tracking-wide">Acción irreversible</p>
                    <h3 id="invalidar-modal-titulo" class="text-base font-semibold text-gray-900">Invalidar trámite</h3>
                </div>
                <button type="button" id="invalidar-close" class="text-gray-500 hover:text-gray-700 p-1 rounded-lg hover:bg-white/80 shrink-0" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-5 sm:p-6 space-y-3 overflow-y-auto custom-scrollbar flex-1 min-h-0">
                <p class="text-sm text-gray-600">Indique el <strong>motivo</strong> de la invalidación. Se guardará en observaciones y en el historial del trámite.</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="invalidar-motivo">Motivo *</label>
                    <textarea id="invalidar-motivo" rows="4" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-200 focus:border-red-400 text-sm" placeholder="Describa el motivo (mínimo 5 caracteres)" required></textarea>
                    <p id="invalidar-motivo-hint" class="text-xs mt-1 text-gray-500">Mínimo 5 caracteres: 0/5</p>
                </div>
            </div>
            <div class="px-5 py-3 sm:px-6 sm:py-4 border-t border-gray-200 bg-gray-50/90 flex flex-wrap gap-3 justify-end shrink-0">
                <button type="button" class="btn btn-secondary" id="invalidar-cancelar">Cancelar</button>
                <button type="button" class="btn btn-danger" id="invalidar-confirm"><i class="fas fa-ban"></i> Confirmar invalidación</button>
            </div>
        </div>
    </div>

    <script src="recursos/js/cne_nueva_solicitud_combos_busqueda.js?v=1"></script>
    <script>
        // ===== INICIO: Función de inicialización del menú (copiada de dashboard_entrada.php) =====
        function inicializarMenu() {
            const sidebar = document.getElementById('sidebar');
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

            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    sidebar.classList.remove('mobile-hidden');
                    sidebar.classList.add('mobile-visible');
                    menuOverlay.classList.remove('active');
                } else {
                    sidebar.classList.remove('mobile-visible');
                    sidebar.classList.add('mobile-hidden');
                }
            });
        }
        // ===== FIN: Función de inicialización del menú =====

        // ===== NUEVA FUNCIÓN: Select personalizado de género =====
        function inicializarCustomSelectGenero() {
            const select = document.getElementById('genero-empleado');
            const button = document.getElementById('custom-genero-button-emp');
            const dropdown = document.getElementById('custom-genero-dropdown-emp');
            if (!select || !button || !dropdown) return;
            const options = dropdown.querySelectorAll('.custom-select-option');
            const selectedIcon = document.getElementById('selected-icon-emp');
            const selectedText = document.getElementById('selected-text-emp');

            function updateButton(value, text) {
                if (!selectedIcon || !selectedText) return;
                if (value === '') {
                    selectedIcon.className = 'selected-icon text-gray-400';
                    selectedIcon.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-gender-bigender">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M7 11a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                            <path d="M19 3l-5 5" />
                            <path d="M15 3h4v4" />
                            <path d="M11 16v6" /><path d="M8 19h6" />
                        </svg>
                    `;
                    selectedText.textContent = 'Seleccione un género';
                    selectedText.className = 'text-gray-400';
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
                    selectedText.className = 'text-gray-900';
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
                    selectedText.className = 'text-gray-900';
                }
            }

            // Estado inicial
            const initialValue = select.value;
            const initialOption = select.querySelector(`option[value="${initialValue}"]`);
            if (initialOption) {
                updateButton(initialValue, initialOption.textContent);
            }

            // Abrir/cerrar dropdown
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = dropdown.classList.contains('open');
                document.querySelectorAll('.custom-select-dropdown.open').forEach(d => d.classList.remove('open'));
                document.querySelectorAll('.custom-select-button.open').forEach(b => b.classList.remove('open'));
                if (!isOpen) {
                    dropdown.classList.add('open');
                    button.classList.add('open');
                }
            });

            // Seleccionar opción
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

                    // Llamar a la validación existente
                    if (typeof validarGeneroEmpleado === 'function') {
                        validarGeneroEmpleado(select);
                    }
                });
            });

            // Cerrar al hacer clic fuera
            document.addEventListener('click', function() {
                dropdown.classList.remove('open');
                button.classList.remove('open');
            });

            dropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            // Sincronizar cambios externos (ej. cuando se carga por cédula)
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
        // ===== FIN NUEVA FUNCIÓN =====

        let currentSolicitud = null;
        const modal = document.getElementById('solicitud-modal');
        const REDIRIGIR_MODAL = document.getElementById('redirigir-modal');
        const INVALIDAR_MODAL = document.getElementById('invalidar-modal');
        const COORDINACIONES = <?php echo json_encode(array_map(function ($c) {
            return ['id' => (int) $c['id'], 'nombre' => presentarNombreCoordinacionUi($c['nombre'])];
        }, $coordinaciones), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        // Contenedor de toasts (se crea dinámicamente)
        const toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'fixed top-20 right-4 z-40 space-y-2'; // top-20 para dejar espacio al header
        document.body.appendChild(toastContainer);

        const MUNICIPIOS_POR_ESTADO_EMP = <?php echo json_encode($municipios_por_estado ?? [], JSON_UNESCAPED_UNICODE); ?>;
        const CNE_NS_ESTADOS_EMP = <?php echo json_encode($estados_lista_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const CNE_NS_INST_EMP = <?php echo json_encode($inst_lista_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const userCoordinacionId = <?php echo (int) ($_SESSION['coordinacion_id'] ?? $coordinacion_id ?? 0); ?>;
        const MI_AREA_DEFAULT_EMP = userCoordinacionId;
        let tiposTramiteDataEmp = [];
        let fuseInstanceEmpleado = null;
        let currentTramiteListEmpleado = [];
        let tramiteSearchDebounceTimerEmp = null;
        let tipoSolicitudActualEmp = 'normal';
        let flatpickrFechaNacimientoEmpleado = null;
        let fechaNacimientoEmpleadoProgrammatic = false;
        let busquedaCiudadanoAbortEmp = null;
        let busquedaCiudadanoSeqEmp = 0;
        let debounceTimerBusquedaCedulaEmp = null;
        let snapshotDatosOpcionalesCiudadanoEmpleado = null;

        document.addEventListener('DOMContentLoaded', function() {
            inicializarMenu(); // Llamada a la función del menú (agregada)

            // Inicializar select personalizado de género
            inicializarCustomSelectGenero();

            // Flatpickr para Fecha de Nacimiento (español, d/m/Y, máx. hoy)
            const inpFnEmp = document.getElementById('fecha_nacimiento-empleado');
            if (inpFnEmp && typeof flatpickr !== 'undefined') {
                flatpickrFechaNacimientoEmpleado = flatpickr(inpFnEmp, {
                    locale: (flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default',
                    dateFormat: 'Y-m-d',
                    altFormat: 'd/m/Y',
                    altInput: true,
                    allowInput: false,
                    maxDate: 'today',
                    disableMobile: true,
                    onChange: function() {
                        if (fechaNacimientoEmpleadoProgrammatic) return;
                        quitarMarcaCamposDesdeCiudadanoEmpleado();
                    }
                });
            }

            // ===== NUEVO: Manejo de clics en los ítems del menú =====
            const menuItems = document.querySelectorAll('.menu-item');
            const sections = {
                'nueva-solicitud': document.getElementById('seccion-nueva-solicitud'),
                'pendientes': document.getElementById('seccion-pendientes'),
                'historial': document.getElementById('seccion-historial')
            };
            const sectionTitles = {
                'nueva-solicitud': 'Nueva Solicitud',
                'pendientes': 'Trámites Pendientes',
                'historial': 'Historial'
            };
            const sectionTitleEl = document.getElementById('section-title');

            menuItems.forEach(item => {
                item.addEventListener('click', function() {
                    const section = this.dataset.section;
                    if (!section) return;

                    // Desactivar todos los items y ocultar todas las secciones
                    menuItems.forEach(i => i.classList.remove('active'));
                    Object.values(sections).forEach(sec => sec.classList.add('hidden'));

                    // Activar el item actual y mostrar la sección correspondiente
                    this.classList.add('active');
                    if (sections[section]) sections[section].classList.remove('hidden');

                    // Actualizar título del header
                    if (sectionTitleEl) sectionTitleEl.textContent = sectionTitles[section] || section;

                    // Cargar datos si es necesario
                    if (section === 'historial') {
                        cargarHistorial();
                    } else if (section === 'pendientes') {
                        cargarPendientes();
                    }

                    // En móvil, cerrar el sidebar después de seleccionar
                    if (window.innerWidth < 1024) {
                        sidebar.classList.remove('mobile-visible');
                        sidebar.classList.add('mobile-hidden');
                        menuOverlay.classList.remove('active');
                    }
                });
            });
            // ===== FIN NUEVO =====

            cargarPendientes();
            cargarHistorial();
            inicializarAccionesModal();
            document.querySelectorAll('[data-close-historial-modal]').forEach(function(el) {
                el.addEventListener('click', cerrarModalHistorialTramite);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                const rm = document.getElementById('redirigir-modal');
                if (rm && rm.classList.contains('active')) {
                    rm.classList.remove('active');
                    e.preventDefault();
                    return;
                }
                const mh = document.getElementById('modal-historial-tramite');
                if (mh && !mh.classList.contains('hidden')) cerrarModalHistorialTramite();
            });
            inicializarNotificaciones();
            inicializarFormularioNuevaSolicitud();
            inicializarValidacionesFormEmpleado();
            inicializarModalesSolicitudEmp();
            setInterval(fetchNotificaciones, 30000);
            fetch('ajax/ping_actividad.php', { credentials: 'same-origin' }).catch(() => {});
            setInterval(function() {
                fetch('ajax/ping_actividad.php', { credentials: 'same-origin' }).catch(() => {});
            }, 60000);
        });

        function syncIdCoordinacionDestinoEmpleado() {
            const a = document.getElementById('area_id-empleado');
            const h = document.getElementById('id_coordinacion_destino-empleado');
            if (a && h) h.value = a.value || '';
        }

        function actualizarEstadoBotonTramiteInmediatoEmpleado() {
            const btn = document.getElementById('btn-tramite-inmediato-empleado');
            const hint = document.getElementById('hint-tramite-inmediato-empleado');
            const areaSel = document.getElementById('area_id-empleado');
            if (!btn) return;
            const sel = parseInt(String(areaSel && areaSel.value ? areaSel.value : '0'), 10);
            const own = parseInt(String(userCoordinacionId || 0), 10);
            const permitir = own > 0 && sel > 0 && sel === own;
            btn.disabled = !permitir;
            btn.setAttribute('aria-disabled', permitir ? 'false' : 'true');
            const msgBloqueo = 'Solo puede realizar trámites inmediatos en su propia coordinación';
            btn.title = permitir ? '' : msgBloqueo;
            if (hint) {
                hint.classList.toggle('hidden', permitir);
            }
        }

        function escapeRegExpEmp(s) {
            return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function cargarTiposTramiteEmpleadoDropdown(areaId) {
            const button = document.getElementById('tramite-search-button-empleado');
            const dropdown = document.getElementById('tramite-search-dropdown-empleado');
            const searchInput = document.getElementById('tramite-search-input-empleado');
            const resultsContainer = document.getElementById('tramite-search-results-empleado');
            const labelEl = document.getElementById('tramite-selected-label-empleado');
            const camposDin = document.getElementById('campos-dinamicos-empleado');
            if (!button || !dropdown || !resultsContainer || !labelEl) return;

            const resetTramiteUi = () => {
                document.getElementById('tipo_tramite_id-empleado').value = '';
                labelEl.textContent = 'Seleccione un tipo de trámite';
                labelEl.className = 'selected-tramite-text tramite-placeholder';
                document.getElementById('subtramite-wrapper-empleado')?.classList.add('hidden');
                document.getElementById('requisitos-wrapper-empleado')?.classList.add('hidden');
                if (camposDin) {
                    camposDin.classList.add('hidden');
                    const cc = document.getElementById('campos-contenido-empleado');
                    if (cc) cc.innerHTML = '';
                }
            };

            if (!areaId) {
                labelEl.textContent = 'Seleccione una coordinación primero';
                labelEl.className = 'selected-tramite-text tramite-placeholder';
                resultsContainer.innerHTML = '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione una coordinación primero</span></div>';
                fuseInstanceEmpleado = null;
                currentTramiteListEmpleado = [];
                tiposTramiteDataEmp = [];
                resetTramiteUi();
                return;
            }

            labelEl.textContent = 'Cargando tipos de trámite...';
            labelEl.className = 'selected-tramite-text';
            resultsContainer.innerHTML = '<div class="searching-indicator"><div class="loading mx-auto mb-2"></div><p>Cargando tipos de trámite...</p></div>';

            fetch('ajax/obtener_tipos_tramite.php?area_id=' + encodeURIComponent(areaId))
                .then(r => (r.ok ? r.json() : Promise.reject()))
                .then(data => {
                    if (data && data.error) {
                        resultsContainer.innerHTML = '<div class="tramite-search-option" data-value=""><span class="text-red-500">' + data.error + '</span></div>';
                        labelEl.textContent = 'Error al cargar';
                        fuseInstanceEmpleado = null;
                        currentTramiteListEmpleado = [];
                        return;
                    }
                    const lista = Array.isArray(data) ? data : [];
                    tiposTramiteDataEmp = lista;
                    currentTramiteListEmpleado = lista;
                    if (!lista.length) {
                        resultsContainer.innerHTML = '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">No hay tipos de trámite disponibles</span></div>';
                        labelEl.textContent = 'No hay tipos de trámite disponibles';
                        labelEl.className = 'selected-tramite-text tramite-placeholder';
                        fuseInstanceEmpleado = null;
                        resetTramiteUi();
                        return;
                    }
                    fuseInstanceEmpleado = new Fuse(lista, {
                        keys: ['nombre'],
                        threshold: 0.3,
                        distance: 100,
                        includeScore: true,
                        includeMatches: true,
                        minMatchCharLength: 1,
                        getFn: (obj, path) => {
                            const v = obj[path];
                            if (typeof v === 'string') {
                                return v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                            }
                            return v;
                        }
                    });
                    let html = '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione un tipo de trámite</span></div>';
                    lista.forEach(t => {
                        html += '<div class="tramite-search-option" data-value="' + t.id + '" data-nombre="' + String(t.nombre || '').replace(/"/g, '&quot;') + '"><span>' + (t.nombre || '') + '</span></div>';
                    });
                    resultsContainer.innerHTML = html;
                    resultsContainer.querySelectorAll('.tramite-search-option').forEach(opt => {
                        opt.addEventListener('click', function() { seleccionarTramiteOptionEmp(this); });
                    });
                    labelEl.textContent = 'Seleccione un tipo de trámite';
                    labelEl.className = 'selected-tramite-text tramite-placeholder';
                    document.getElementById('tipo_tramite_id-empleado').value = '';
                    dropdown.classList.remove('open');
                    button.classList.remove('open');
                    if (searchInput) searchInput.value = '';
                })
                .catch(() => {
                    resultsContainer.innerHTML = '<div class="tramite-search-option" data-value=""><span class="text-red-500">Error al cargar tipos de trámite</span></div>';
                    labelEl.textContent = 'Error al cargar';
                    fuseInstanceEmpleado = null;
                    currentTramiteListEmpleado = [];
                });
        }

        function generarCamposDinamicosEmp(campos) {
            const container = document.getElementById('campos-contenido-empleado');
            const wrap = document.getElementById('campos-dinamicos-empleado');
            if (!container || !wrap) return;
            let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
            campos.forEach((campo) => {
                const lab = String(campo).replace(/_/g, ' ');
                html += '<div><label class="block mb-2 font-medium text-gray-700 capitalize">' + lab + '</label>' +
                    '<input type="text" name="campo_' + campo + '" class="w-full p-3 border-2 border-gray-300 rounded-lg" placeholder="Ingrese ' + lab + '"></div>';
            });
            html += '</div>';
            container.innerHTML = html;
            wrap.classList.remove('hidden');
        }

        function seleccionarTramiteOptionEmp(optionElement) {
            const value = optionElement.getAttribute('data-value');
            const nombre = optionElement.getAttribute('data-nombre') || (optionElement.querySelector('span') && optionElement.querySelector('span').textContent) || '';
            const button = document.getElementById('tramite-search-button-empleado');
            const dropdown = document.getElementById('tramite-search-dropdown-empleado');
            const searchInput = document.getElementById('tramite-search-input-empleado');
            const resultsContainer = document.getElementById('tramite-search-results-empleado');
            const labelEl = document.getElementById('tramite-selected-label-empleado');
            if (!value) return;

            resultsContainer.querySelectorAll('.tramite-search-option').forEach(opt => opt.classList.remove('selected'));
            optionElement.classList.add('selected');
            if (labelEl) {
                labelEl.textContent = nombre;
                labelEl.className = 'selected-tramite-text';
            }
            document.getElementById('tipo_tramite_id-empleado').value = value;
            dropdown.classList.remove('open');
            button.classList.remove('open');
            if (searchInput) searchInput.value = '';
            document.getElementById('error-tipo-tramite-empleado')?.classList.add('hidden');
            validarTipoTramiteEmpleado();

            const tipoSel = currentTramiteListEmpleado.find(t => String(t.id) === String(value));
            const camposDin = document.getElementById('campos-dinamicos-empleado');
            if (tipoSel && tipoSel.config && tipoSel.config.campos && tipoSel.config.campos.length > 0) {
                generarCamposDinamicosEmp(tipoSel.config.campos);
            } else if (camposDin) {
                camposDin.classList.add('hidden');
                const cc = document.getElementById('campos-contenido-empleado');
                if (cc) cc.innerHTML = '';
            }

            cargarSubtramitesEmpleado(value);
        }

        function inicializarFormularioNuevaSolicitud() {
            if (typeof window.cneNuevaSolicitudCombosInit === 'function') {
                window.cneNuevaSolicitudCombosInit({
                    estadosLista: CNE_NS_ESTADOS_EMP,
                    institucionesLista: CNE_NS_INST_EMP,
                    municipiosPorEstado: MUNICIPIOS_POR_ESTADO_EMP,
                    ids: {
                        estado: { h: 'estado_id-empleado', b: 'estado-search-button-empleado', d: 'estado-search-dropdown-empleado', i: 'estado-search-input-empleado', r: 'estado-search-results-empleado' },
                        municipio: { h: 'municipio_id-empleado', b: 'municipio-search-button-empleado', d: 'municipio-search-dropdown-empleado', i: 'municipio-search-input-empleado', r: 'municipio-search-results-empleado' },
                        institucion: { h: 'institucion-empleado', b: 'institucion-search-button-empleado', d: 'institucion-search-dropdown-empleado', i: 'institucion-search-input-empleado', r: 'institucion-search-results-empleado' }
                    }
                });
            }
            const instSelect = document.getElementById('institucion-empleado');
            const otroWrap = document.getElementById('institucion-otro-wrapper-empleado');
            instSelect?.addEventListener('change', function() {
                otroWrap?.classList.toggle('hidden', this.value !== 'otro');
                if (this.value !== 'otro') {
                    const io = document.getElementById('institucion-otro-empleado');
                    if (io) io.value = '';
                }
            });

            const areaSel = document.getElementById('area_id-empleado');
            areaSel?.addEventListener('change', function() {
                syncIdCoordinacionDestinoEmpleado();
                cargarTiposTramiteEmpleadoDropdown(this.value);
                actualizarEstadoBotonTramiteInmediatoEmpleado();
            });
            syncIdCoordinacionDestinoEmpleado();
            if (areaSel && areaSel.value) {
                cargarTiposTramiteEmpleadoDropdown(areaSel.value);
            } else {
                cargarTiposTramiteEmpleadoDropdown('');
            }
            actualizarEstadoBotonTramiteInmediatoEmpleado();

            const btn = document.getElementById('tramite-search-button-empleado');
            const dd = document.getElementById('tramite-search-dropdown-empleado');
            const inp = document.getElementById('tramite-search-input-empleado');
            btn?.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = dd.classList.contains('open');
                document.querySelectorAll('.tramite-search-dropdown.open').forEach(d => d.classList.remove('open'));
                document.querySelectorAll('.tramite-search-button.open').forEach(b => b.classList.remove('open'));
                if (!isOpen) {
                    dd.classList.add('open');
                    btn.classList.add('open');
                    setTimeout(() => { inp?.focus(); inp?.select(); }, 100);
                }
            });
            inp?.addEventListener('input', function() {
                clearTimeout(tramiteSearchDebounceTimerEmp);
                tramiteSearchDebounceTimerEmp = setTimeout(() => {
                    const q = this.value.trim();
                    const resultsContainer = document.getElementById('tramite-search-results-empleado');
                    if (!fuseInstanceEmpleado || !currentTramiteListEmpleado.length) {
                        if (resultsContainer && !q) {
                            resultsContainer.querySelectorAll('.tramite-search-option').forEach(o => o.classList.remove('selected'));
                        }
                        return;
                    }
                    if (!q) {
                        let html = '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione un tipo de trámite</span></div>';
                        currentTramiteListEmpleado.forEach(t => {
                            html += '<div class="tramite-search-option" data-value="' + t.id + '" data-nombre="' + String(t.nombre || '').replace(/"/g, '&quot;') + '"><span>' + (t.nombre || '') + '</span></div>';
                        });
                        resultsContainer.innerHTML = html;
                        resultsContainer.querySelectorAll('.tramite-search-option').forEach(opt => {
                            opt.addEventListener('click', function() { seleccionarTramiteOptionEmp(this); });
                        });
                        return;
                    }
                    const resultados = fuseInstanceEmpleado.search(q);
                    if (resultados.length === 0) {
                        resultsContainer.innerHTML = '<div class="no-results-message"><i class="fas fa-search mb-2"></i><p>No se encontraron trámites que coincidan con la búsqueda</p></div>';
                        return;
                    }
                    let optionsHtml = '';
                    resultados.forEach(res => {
                        const tramite = res.item;
                        let nombreDisplay = tramite.nombre;
                        const regex = new RegExp('(' + escapeRegExpEmp(q) + ')', 'gi');
                        nombreDisplay = nombreDisplay.replace(regex, '<span class="search-highlight">$1</span>');
                        optionsHtml += '<div class="tramite-search-option" data-value="' + tramite.id + '" data-nombre="' + String(tramite.nombre || '').replace(/"/g, '&quot;') + '"><span>' + nombreDisplay + '</span></div>';
                    });
                    resultsContainer.innerHTML = optionsHtml;
                    resultsContainer.querySelectorAll('.tramite-search-option').forEach(opt => {
                        opt.addEventListener('click', function() { seleccionarTramiteOptionEmp(this); });
                    });
                }, 300);
            });
            document.addEventListener('click', function(e) {
                if (!btn || !dd) return;
                if (!btn.contains(e.target) && !dd.contains(e.target)) {
                    dd.classList.remove('open');
                    btn.classList.remove('open');
                }
            });
            dd?.addEventListener('click', e => e.stopPropagation());

            document.getElementById('subtramite_id-empleado')?.addEventListener('change', function() {
                const v = this.value;
                const tid = document.getElementById('tipo_tramite_id-empleado');
                const reqW = document.getElementById('requisitos-wrapper-empleado');
                document.getElementById('error-subtramite-empleado')?.classList.add('hidden');
                if (!v) {
                    if (tid) tid.value = '';
                    reqW?.classList.add('hidden');
                    validarTipoTramiteEmpleado();
                    return;
                }
                if (tid) tid.value = v;
                validarTipoTramiteEmpleado();
                cargarRequisitosEmpleado(v);
            });

            document.getElementById('estado_id-empleado')?.addEventListener('change', function() {
                this.classList.remove('campos-desde-ciudadano');
                document.getElementById('municipio_id-empleado')?.classList.remove('campos-desde-ciudadano');
                validarEstadoEmpleado(this);
                populateMunicipiosEmpleado(this.value);
                setTimeout(() => actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado(), 80);
            });
            document.getElementById('municipio_id-empleado')?.addEventListener('change', function() {
                this.classList.remove('campos-desde-ciudadano');
                validarMunicipioEmpleado(this);
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado();
            });
            document.getElementById('ciudadano_email-empleado')?.addEventListener('input', actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado);
            document.getElementById('direccion-empleado')?.addEventListener('input', actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado);

            inicializarBusquedaCiudadanoPorCedulaEmpleado();

            document.getElementById('btn-tramite-inmediato-empleado')?.addEventListener('click', async function(e) {
                e.preventDefault();
                if (this.disabled) return;
                tipoSolicitudActualEmp = 'inmediato';
                const ts = document.getElementById('tipo_solicitud-empleado');
                if (ts) ts.value = 'inmediato';
                if (await validarFormularioCompletoEmpleado()) {
                    if (!validarRequisitosTramiteInmediatoEmpleado()) {
                        return;
                    }
                    mostrarModalConfirmacionCompletadoEmp();
                }
            });

            document.getElementById('btn-limpiar-formulario-empleado')?.addEventListener('click', function() {
                limpiarFormularioNuevaSolicitud();
            });
        }

        function inicializarValidacionesFormEmpleado() {
            document.getElementById('tramitante-form-empleado')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                tipoSolicitudActualEmp = 'normal';
                const ts = document.getElementById('tipo_solicitud-empleado');
                if (ts) ts.value = 'normal';
                if (await validarFormularioCompletoEmpleado()) {
                    mostrarModalConfirmacionEmp();
                }
            });
        }

        function inicializarModalesSolicitudEmp() {
            document.getElementById('cancelBtnEmp')?.addEventListener('click', () => {
                document.getElementById('confirmModalEmp')?.classList.remove('active');
            });
            document.getElementById('cancelCompletadoBtnEmp')?.addEventListener('click', () => {
                document.getElementById('confirmCompletadoModalEmp')?.classList.remove('active');
            });
            document.getElementById('btn-nueva-solicitud-emp')?.addEventListener('click', function() {
                window.location.reload();
            });
            document.getElementById('btn-imprimir-emp')?.addEventListener('click', function() {
                window.print();
            });
        }

        function cargarSubtramitesEmpleado(tramiteId) {
            const w = document.getElementById('subtramite-wrapper-empleado');
            const sel = document.getElementById('subtramite_id-empleado');
            if (!w || !sel) return;
            w.classList.add('hidden');
            sel.innerHTML = '<option value="">Seleccione una variante</option>';
            sel.value = '';
            fetch(`ajax/obtener_subtramites.php?padre_id=${tramiteId}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success && Array.isArray(d.subtramites) && d.subtramites.length > 0) {
                        sel.innerHTML = '<option value="">Seleccione una variante</option>' + d.subtramites.map(s => `<option value="${s.id}">${s.nombre}</option>`).join('');
                        w.classList.remove('hidden');
                        document.getElementById('tipo_tramite_id-empleado').value = '';
                        document.getElementById('requisitos-wrapper-empleado')?.classList.add('hidden');
                        document.getElementById('error-subtramite-empleado')?.classList.add('hidden');
                        validarTipoTramiteEmpleado();
                    } else {
                        sel.innerHTML = '<option value="">Seleccione una variante</option>';
                        w.classList.add('hidden');
                        document.getElementById('tipo_tramite_id-empleado').value = tramiteId;
                        cargarRequisitosEmpleado(tramiteId);
                    }
                })
                .catch(() => {
                    w.classList.add('hidden');
                    document.getElementById('tipo_tramite_id-empleado').value = tramiteId;
                    cargarRequisitosEmpleado(tramiteId);
                });
        }

        function cargarRequisitosEmpleado(tramiteId) {
            const w = document.getElementById('requisitos-wrapper-empleado');
            const list = document.getElementById('requisitos-list-empleado');
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
                            label.className = 'flex items-start gap-2 text-sm cursor-pointer';
                            label.setAttribute('data-req-id', String(r.id));
                            const cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.className = 'req-item-empleado mt-0.5 w-4 h-4 shrink-0 border-gray-300 rounded';
                            cb.dataset.id = String(r.id);
                            cb.dataset.name = nombre;
                            cb.dataset.asesoria = esAsesoria ? '1' : '0';
                            const span = document.createElement('span');
                            span.textContent = nombre;
                            label.appendChild(cb);
                            label.appendChild(span);
                            list.appendChild(label);
                        });
                        inicializarReglaRequisitosEmpleado(list);
                        w.classList.remove('hidden');
                    } else {
                        w.classList.add('hidden');
                    }
                })
                .catch(() => { list.innerHTML = ''; w.classList.add('hidden'); });
        }

        /**
         * Trámite inmediato: al cargar, todos los requisitos excepto Asesoría quedan marcados.
         * Asesoría marcada → desmarca y deshabilita el resto (opacidad reducida).
         * Asesoría desmarcada → habilita el resto y los marca todos (el funcionario puede desmarcar manualmente).
         */
        function inicializarReglaRequisitosEmpleado(container) {
            const inputs = Array.from(container.querySelectorAll('input.req-item-empleado'));
            if (!inputs.length) return;
            const asesoria = inputs.find(i => i.dataset.asesoria === '1' || /^asesor[ií]a$/i.test((i.dataset.name || '').trim()));
            const otros = asesoria ? inputs.filter(i => i !== asesoria) : inputs;

            const aplicarEstiloDeshabilitado = (input, deshabilitado) => {
                const label = input.closest('label');
                if (!label) return;
                if (deshabilitado) {
                    label.classList.add('opacity-50', 'grayscale', 'cursor-not-allowed', 'pointer-events-none');
                } else {
                    label.classList.remove('opacity-50', 'grayscale', 'cursor-not-allowed', 'pointer-events-none');
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

        function obtenerNombresRequisitosMarcadosEmpleado() {
            return obtenerRequisitosMarcadosEmpleado().map(x => x.nombre).filter(Boolean);
        }

        function requisitosInmediatosVisiblesEmpleado() {
            const w = document.getElementById('requisitos-wrapper-empleado');
            return w && !w.classList.contains('hidden') && (document.querySelectorAll('#requisitos-list-empleado input.req-item-empleado').length > 0);
        }

        function obtenerRequisitosMarcadosEmpleado() {
            return Array.from(document.querySelectorAll('#requisitos-list-empleado input.req-item-empleado:checked'))
                .map(i => ({
                    id: parseInt(i.dataset.id, 10),
                    nombre: (i.dataset.name || '').trim()
                }))
                .filter(x => !isNaN(x.id) && x.id > 0);
        }

        function validarRequisitosTramiteInmediatoEmpleado() {
            if (!requisitosInmediatosVisiblesEmpleado()) {
                return true;
            }
            const marcados = obtenerRequisitosMarcadosEmpleado();
            if (marcados.length < 1) {
                mostrarToast('Debe marcar al menos un requisito entregado o Asesoría para realizar el trámite inmediato');
                const list = document.getElementById('requisitos-list-empleado');
                list?.classList.add('ring-2', 'ring-amber-400', 'rounded-lg', 'p-2');
                setTimeout(() => list?.classList.remove('ring-2', 'ring-amber-400', 'rounded-lg', 'p-2'), 2500);
                return false;
            }
            return true;
        }

        function mostrarErrorEmpleado(input, errId, msg) {
            if (input) {
                input.classList.remove('input-success');
                input.classList.add('input-error');
            }
            const e = document.getElementById(errId);
            if (e) { e.textContent = msg; e.classList.remove('hidden'); }
        }
        function limpiarErrorEmpleado(input, errId) {
            if (input) input.classList.remove('input-error');
            const e = document.getElementById(errId);
            if (e) e.classList.add('hidden');
        }
        function mostrarExitoEmpleado(input, errId) {
            if (input) {
                input.classList.remove('input-error');
                input.classList.add('input-success');
            }
            const e = document.getElementById(errId);
            if (e) e.classList.add('hidden');
        }
        function validarCedulaEmpleado(input) {
            const v = (input?.value || '').trim();
            if (!v) { mostrarExitoEmpleado(input, 'error-cedula-empleado'); return true; }
            if (!/^\d+$/.test(v)) { mostrarErrorEmpleado(input, 'error-cedula-empleado', 'Solo se permiten números'); return false; }
            if (v.length > 8) { mostrarErrorEmpleado(input, 'error-cedula-empleado', 'Máximo 8 dígitos'); return false; }
            mostrarExitoEmpleado(input, 'error-cedula-empleado');
            return true;
        }
        function validarTelefonoEmpleado(input) {
            const v = (input?.value || '').trim();
            if (!v) { mostrarExitoEmpleado(input, 'error-telefono-empleado'); return true; }
            if (!/^\d+$/.test(v)) { mostrarErrorEmpleado(input, 'error-telefono-empleado', 'Solo se permiten números'); return false; }
            if (v.length !== 7) { mostrarErrorEmpleado(input, 'error-telefono-empleado', 'El teléfono debe tener 7 dígitos'); return false; }
            mostrarExitoEmpleado(input, 'error-telefono-empleado');
            return true;
        }
        function validarNombreEmpleado(input) {
            const v = (input?.value || '').trim();
            if (!v) { mostrarExitoEmpleado(input, 'error-nombres-empleado'); return true; }
            if (v.length < 2) { mostrarErrorEmpleado(input, 'error-nombres-empleado', 'El nombre debe tener al menos 2 caracteres'); return false; }
            mostrarExitoEmpleado(input, 'error-nombres-empleado');
            return true;
        }
        function validarApellidoEmpleado(input) {
            const v = (input?.value || '').trim();
            if (!v) { mostrarExitoEmpleado(input, 'error-apellidos-empleado'); return true; }
            if (v.length < 2) { mostrarErrorEmpleado(input, 'error-apellidos-empleado', 'El apellido debe tener al menos 2 caracteres'); return false; }
            mostrarExitoEmpleado(input, 'error-apellidos-empleado');
            return true;
        }
        function cneAlertaGeneroObligatorioEmpleado() {
            const g = document.getElementById('genero-empleado');
            const v = g ? String(g.value).trim().toLowerCase() : '';
            if (v !== 'masculino' && v !== 'femenino') {
                alert('Debe seleccionar género (Masculino o Femenino).');
                return false;
            }
            return true;
        }

        function validarGeneroEmpleado(sel) {
            const select = sel || document.getElementById('genero-empleado');
            const err = document.getElementById('error-genero-empleado');
            const btn = document.getElementById('custom-genero-button-emp');
            const valor = String(select?.value || '').trim().toLowerCase();
            if (valor !== 'masculino' && valor !== 'femenino') {
                mostrarErrorEmpleado(select, 'error-genero-empleado', 'Debe seleccionar género (Masculino o Femenino)');
                if (btn) {
                    btn.classList.remove('input-success', 'campos-desde-ciudadano');
                    btn.classList.add('input-error');
                }
                return false;
            }
            if (err) err.classList.add('hidden');
            if (btn) {
                btn.classList.remove('input-error');
                btn.classList.add('input-success');
                btn.classList.remove('campos-desde-ciudadano');
            }
            return true;
        }
        function validarInstitucionEmpleado() {
            const sel = document.getElementById('institucion-empleado');
            const vis = document.getElementById('institucion-search-button-empleado');
            if (!sel?.value) { mostrarErrorEmpleado(vis || sel, 'error-institucion-empleado', 'La institución es obligatoria'); return false; }
            if (sel.value === 'otro') {
                const otroInput = document.getElementById('institucion-otro-empleado');
                const nombre = (otroInput?.value || '').trim();
                if (!nombre) {
                    mostrarErrorEmpleado(otroInput, 'error-institucion-otro-empleado', 'Debe ingresar el nombre de la institución');
                    return false;
                }
                mostrarExitoEmpleado(otroInput, 'error-institucion-otro-empleado');
            }
            mostrarExitoEmpleado(vis || sel, 'error-institucion-empleado');
            return true;
        }
        function validarAreaEmpleado() {
            const sel = document.getElementById('area_id-empleado');
            if (!sel?.value) {
                mostrarErrorEmpleado(sel, 'error-area-empleado', 'La coordinación es obligatoria');
                return false;
            }
            mostrarExitoEmpleado(sel, 'error-area-empleado');
            return true;
        }
        function validarEstadoEmpleado(select) {
            const err = document.getElementById('error-estado-empleado');
            const vis = document.getElementById('estado-search-button-empleado');
            if (!err || !select) return true;
            if (!select.value) {
                limpiarErrorEmpleado(vis || select, 'error-estado-empleado');
                return true;
            }
            mostrarExitoEmpleado(vis || select, 'error-estado-empleado');
            return true;
        }
        function validarMunicipioEmpleado(select) {
            if (!select) return true;
            const vis = document.getElementById('municipio-search-button-empleado');
            mostrarExitoEmpleado(vis || select, 'error-municipio-empleado');
            return true;
        }
        function validarTipoTramiteEmpleado() {
            const tid = document.getElementById('tipo_tramite_id-empleado')?.value;
            const subWrapper = document.getElementById('subtramite-wrapper-empleado');
            const subSelect = document.getElementById('subtramite_id-empleado');
            const subVisible = subWrapper && !subWrapper.classList.contains('hidden');
            const subVal = subSelect?.value || '';
            if (!tid) {
                document.getElementById('error-tipo-tramite-empleado')?.classList.remove('hidden');
                return false;
            }
            document.getElementById('error-tipo-tramite-empleado')?.classList.add('hidden');
            if (subVisible && !subVal) {
                document.getElementById('error-subtramite-empleado')?.classList.remove('hidden');
                return false;
            }
            document.getElementById('error-subtramite-empleado')?.classList.add('hidden');
            return true;
        }
        function populateMunicipiosEmpleado(estadoId) {
            if (typeof window.cneNuevaSolicitudCombosRefrescarMunicipios === 'function') {
                window.cneNuevaSolicitudCombosRefrescarMunicipios(estadoId);
            }
        }

        function cedulaNumeroTieneContenidoBusquedaEmpleado(tipo, raw) {
            const t = String(tipo || 'V').toUpperCase();
            const n = String(raw || '').trim().replace(/[\s.\-]/g, '');
            if (!n) return false;
            if (t === 'J' || t === 'G') return /[0-9A-Za-z]/.test(n);
            return /\d/.test(n);
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
        function quitarProteccionUbicacionCiudadanoEmpleado() {
            const clsProt = ['ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'campos-desde-ciudadano', 'input-success'];
            const est = document.getElementById('estado_id-empleado');
            const mun = document.getElementById('municipio_id-empleado');
            const estBtn = document.getElementById('estado-search-button-empleado');
            const munBtn = document.getElementById('municipio-search-button-empleado');
            const munInp = document.getElementById('municipio-search-input-empleado');
            const dir = document.getElementById('direccion-empleado');
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
        function aplicarProteccionUbicacionCiudadanoEmpleado(d) {
            quitarProteccionUbicacionCiudadanoEmpleado();
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
            const estEl = document.getElementById('estado_id-empleado');
            const munEl = document.getElementById('municipio_id-empleado');
            const estBtn = document.getElementById('estado-search-button-empleado');
            const munBtn = document.getElementById('municipio-search-button-empleado');
            const munInp = document.getElementById('municipio-search-input-empleado');
            const dirEl = document.getElementById('direccion-empleado');
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
        function quitarProteccionIdentidadCiudadanoEmpleado() {
            quitarProteccionUbicacionCiudadanoEmpleado();
            const clsProt = ['ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'campos-desde-ciudadano', 'input-success'];
            const limpiarEl = (el) => {
                if (!el) return;
                el.removeAttribute('readonly');
                el.classList.remove(...clsProt, 'pointer-events-none');
            };
            limpiarEl(document.getElementById('cedula-tipo-empleado'));
            limpiarEl(document.getElementById('cedula-numero-empleado'));
            limpiarEl(document.getElementById('nombres-empleado'));
            limpiarEl(document.getElementById('apellidos-empleado'));
            limpiarEl(document.getElementById('telefono-codigo-empleado'));
            limpiarEl(document.getElementById('telefono-numero-empleado'));
            limpiarEl(document.getElementById('fecha_nacimiento-empleado'));
            if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                limpiarEl(flatpickrFechaNacimientoEmpleado.altInput);
            }
            const genBtn = document.getElementById('custom-genero-button-emp');
            if (genBtn) {
                genBtn.classList.remove('ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
            }
        }
        function aplicarProteccionIdentidadCiudadanoEmpleado(d) {
            quitarProteccionIdentidadCiudadanoEmpleado();
            if (!d) return;
            const idFull = d.ciudadano_identificacion || ((document.getElementById('cedula-tipo-empleado')?.value || 'V') + '-' + (document.getElementById('cedula-numero-empleado')?.value || '').trim().replace(/[\s.\-]/g, ''));
            const marcaProt = 'ciudadano-campo-protegido';
            const marcaNa = 'ciudadano-campo-na-editable';
            const tipoEl = document.getElementById('cedula-tipo-empleado');
            const numEl = document.getElementById('cedula-numero-empleado');
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
            setCampoTexto('nombres-empleado', d.ciudadano_nombres);
            setCampoTexto('apellidos-empleado', d.ciudadano_apellidos);
            const telNA = cneEsValorNATexto(d.ciudadano_telefono);
            ['telefono-codigo-empleado', 'telefono-numero-empleado'].forEach(tid => {
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
            const fechaNA = cneEsValorNATexto(d.ciudadano_fecha_nacimiento);
            const finp = document.getElementById('fecha_nacimiento-empleado');
            if (finp) {
                finp.classList.remove(marcaProt, marcaNa, 'campos-desde-ciudadano', 'input-success');
                if (fechaNA) {
                    finp.removeAttribute('readonly');
                    finp.classList.add(marcaNa);
                    if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                        flatpickrFechaNacimientoEmpleado.altInput.removeAttribute('readonly');
                        flatpickrFechaNacimientoEmpleado.altInput.classList.remove(marcaProt);
                        flatpickrFechaNacimientoEmpleado.altInput.classList.add(marcaNa);
                    }
                } else {
                    finp.setAttribute('readonly', 'readonly');
                    finp.classList.add(marcaProt);
                    if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                        flatpickrFechaNacimientoEmpleado.altInput.setAttribute('readonly', 'readonly');
                        flatpickrFechaNacimientoEmpleado.altInput.classList.add(marcaProt);
                        flatpickrFechaNacimientoEmpleado.altInput.classList.remove(marcaNa);
                    }
                }
            }
            const genNA = cneEsValorNATexto(d.ciudadano_genero);
            const genBtn = document.getElementById('custom-genero-button-emp');
            if (genBtn) {
                genBtn.classList.remove(marcaProt, marcaNa, 'pointer-events-none', 'campos-desde-ciudadano', 'input-success');
                if (genNA) {
                    genBtn.classList.add(marcaNa);
                } else {
                    genBtn.classList.add(marcaProt, 'pointer-events-none');
                }
            }
            aplicarProteccionUbicacionCiudadanoEmpleado(d);
        }

        function limpiarCamposCiudadanoAntesDeBusquedaEmpleado() {
            snapshotDatosOpcionalesCiudadanoEmpleado = null;
            quitarProteccionIdentidadCiudadanoEmpleado();
            quitarMarcaCamposDesdeCiudadanoEmpleado();
            document.getElementById('ciudadano_email-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('direccion-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('fecha_nacimiento-empleado')?.classList.remove('input-success');
            if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                flatpickrFechaNacimientoEmpleado.altInput.classList.remove('input-success');
            }
            document.getElementById('custom-genero-button-emp')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano');
            document.getElementById('estado_id-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('municipio_id-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('estado-search-button-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('municipio-search-button-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
            document.getElementById('nombres-empleado').value = '';
            document.getElementById('apellidos-empleado').value = '';
            document.getElementById('telefono-codigo-empleado').value = '0412';
            document.getElementById('telefono-numero-empleado').value = '';
            document.getElementById('ciudadano_email-empleado').value = '';
            const gen = document.getElementById('genero-empleado');
            if (gen) {
                gen.value = '';
                gen.dispatchEvent(new Event('change'));
            }
            if (flatpickrFechaNacimientoEmpleado) flatpickrFechaNacimientoEmpleado.clear();
            else document.getElementById('fecha_nacimiento-empleado').value = '';
            document.getElementById('direccion-empleado').value = '';
            if (typeof window.cneNuevaSolicitudCombosSetEstadoValor === 'function') {
                window.cneNuevaSolicitudCombosSetEstadoValor('');
            } else {
                document.getElementById('estado_id-empleado').value = '';
                populateMunicipiosEmpleado('');
                document.getElementById('municipio_id-empleado').value = '';
            }
            if (typeof window.cneNuevaSolicitudCombosSyncInstitucionBoton === 'function') {
                window.cneNuevaSolicitudCombosSyncInstitucionBoton();
            }
            document.getElementById('institucion-empleado')?.dispatchEvent(new Event('change'));
        }

        function inicializarBusquedaCiudadanoPorCedulaEmpleado() {
            const numEl = document.getElementById('cedula-numero-empleado');
            const tipoEl = document.getElementById('cedula-tipo-empleado');
            if (!numEl) return;
            numEl.addEventListener('blur', function() {
                ejecutarBusquedaCiudadanoPorCedulaEmpleado();
            });
            numEl.addEventListener('input', function() {
                const tipo = document.getElementById('cedula-tipo-empleado')?.value || 'V';
                if (!cedulaNumeroTieneContenidoBusquedaEmpleado(tipo, this.value)) {
                    clearTimeout(debounceTimerBusquedaCedulaEmp);
                }
            });
            tipoEl?.addEventListener('change', function() {
                if (cedulaNumeroTieneContenidoBusquedaEmpleado(this.value, numEl.value)) {
                    programarBusquedaCiudadanoPorCedulaEmpleado(80);
                }
            });
        }

        function programarBusquedaCiudadanoPorCedulaEmpleado(delayMs) {
            clearTimeout(debounceTimerBusquedaCedulaEmp);
            debounceTimerBusquedaCedulaEmp = setTimeout(() => {
                ejecutarBusquedaCiudadanoPorCedulaEmpleado();
            }, typeof delayMs === 'number' ? delayMs : 320);
        }

        function ejecutarBusquedaCiudadanoPorCedulaEmpleado() {
            const tipo = document.getElementById('cedula-tipo-empleado')?.value || 'V';
            const numRaw = document.getElementById('cedula-numero-empleado')?.value?.trim() || '';
            const num = numRaw.replace(/[\s.\-]/g, '');
            if (!cedulaNumeroTieneContenidoBusquedaEmpleado(tipo, num)) return;
            const inp = document.getElementById('cedula-numero-empleado');
            if (busquedaCiudadanoAbortEmp) {
                try { busquedaCiudadanoAbortEmp.abort(); } catch (e) {}
            }
            busquedaCiudadanoAbortEmp = new AbortController();
            const seq = ++busquedaCiudadanoSeqEmp;
            inp?.classList.add('loading-input');

            const url = 'ajax/buscar_ciudadano.php?cedula_tipo=' + encodeURIComponent(tipo) + '&cedula_numero=' + encodeURIComponent(num);
            fetch(url, { signal: busquedaCiudadanoAbortEmp.signal, credentials: 'same-origin' })
                .then(async response => {
                    if (!response.ok) {
                        const errText = await response.text().catch(() => '');
                        const err = new Error('HTTP ' + response.status + ' ' + response.statusText + (errText ? ' — ' + errText : ''));
                        console.error('[BuscaCiudadano] Error en la petición (HTTP):', err.message, errText);
                        throw err;
                    }
                    return response.json();
                })
                .then(d => {
                    if (seq !== busquedaCiudadanoSeqEmp) return;
                    console.log('[BuscaCiudadano] Datos recibidos:', d);
                    inp?.classList.remove('loading-input');

                    const encontrado = d.success === true && (d.encontrado === true || d.ciudadano_identificacion);

                    if (encontrado) {
                        limpiarCamposCiudadanoAntesDeBusquedaEmpleado();

                        document.getElementById('nombres-empleado').value = cneMayusCiudadanoTexto(d.ciudadano_nombres || '');
                        document.getElementById('apellidos-empleado').value = cneMayusCiudadanoTexto(d.ciudadano_apellidos || '');
                        const telStr = d.ciudadano_telefono || '';
                        if (telStr && !cneEsValorNATexto(telStr)) {
                            const tel = telStr.split('-');
                            if (tel.length >= 2) {
                                document.getElementById('telefono-codigo-empleado').value = tel[0];
                                document.getElementById('telefono-numero-empleado').value = tel.slice(1).join('-');
                            }
                        } else {
                            document.getElementById('telefono-codigo-empleado').value = '0412';
                            document.getElementById('telefono-numero-empleado').value = '';
                        }
                        if (d.ciudadano_genero) {
                            const g = String(d.ciudadano_genero).trim().toLowerCase();
                            if (g === 'masculino' || g === 'femenino') {
                                const generoSelect = document.getElementById('genero-empleado');
                                generoSelect.value = g;
                                generoSelect.dispatchEvent(new Event('change'));
                            }
                        }
                        if (d.ciudadano_fecha_nacimiento && !cneEsValorNATexto(d.ciudadano_fecha_nacimiento)) {
                            fechaNacimientoEmpleadoProgrammatic = true;
                            if (flatpickrFechaNacimientoEmpleado) flatpickrFechaNacimientoEmpleado.setDate(d.ciudadano_fecha_nacimiento);
                            else document.getElementById('fecha_nacimiento-empleado').value = d.ciudadano_fecha_nacimiento;
                            requestAnimationFrame(() => { fechaNacimientoEmpleadoProgrammatic = false; });
                        }
                        const eidRaw = d.estado_id != null ? parseInt(String(d.estado_id), 10) : 0;
                        if (!isNaN(eidRaw) && eidRaw > 0) {
                            if (typeof window.cneNuevaSolicitudCombosSetEstadoValor === 'function') {
                                window.cneNuevaSolicitudCombosSetEstadoValor(String(eidRaw));
                            } else {
                                const estEl = document.getElementById('estado_id-empleado');
                                if (estEl) {
                                    estEl.value = String(eidRaw);
                                    estEl.dispatchEvent(new Event('change'));
                                }
                            }
                        }
                        const midNum = d.municipio_id != null ? parseInt(String(d.municipio_id), 10) : 0;
                        const mid = !isNaN(midNum) && midNum > 0 ? String(midNum) : '';
                        if (mid) {
                            const aplicarMunicipio = () => {
                                if (seq !== busquedaCiudadanoSeqEmp) return;
                                if (typeof window.cneNuevaSolicitudCombosSetMunicipioValor === 'function') {
                                    window.cneNuevaSolicitudCombosSetMunicipioValor(mid);
                                } else {
                                    const munSel = document.getElementById('municipio_id-empleado');
                                    if (munSel) munSel.value = mid;
                                }
                            };
                            requestAnimationFrame(aplicarMunicipio);
                            setTimeout(aplicarMunicipio, 50);
                        }
                        const dirElEmp = document.getElementById('direccion-empleado');
                        if (dirElEmp) dirElEmp.value = cneMayusCiudadanoTexto(d.ciudadano_direccion != null ? String(d.ciudadano_direccion) : '');
                        if (d.ciudadano_email) document.getElementById('ciudadano_email-empleado').value = d.ciudadano_email;

                        validarNombreEmpleado(document.getElementById('nombres-empleado'));
                        validarApellidoEmpleado(document.getElementById('apellidos-empleado'));
                        validarTelefonoEmpleado(document.getElementById('telefono-numero-empleado'));
                        validarGeneroEmpleado(document.getElementById('genero-empleado'));
                        aplicarMarcaCamposDesdeCiudadanoEmpleado(d);
                        aplicarProteccionIdentidadCiudadanoEmpleado(d);
                        setTimeout(() => {
                            if (seq !== busquedaCiudadanoSeqEmp) return;
                            guardarSnapshotOpcionalesCiudadanoEmpleado(d);
                            actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado();
                        }, 100);
                    } else {
                        const msg = d && d.message ? String(d.message) : '';
                        if (msg.includes('No se encontraron') || msg.includes('inválidos') || msg.includes('incompletos')) {
                            limpiarCamposCiudadanoAntesDeBusquedaEmpleado();
                        }
                    }
                })
                .catch(err => {
                    if (err.name === 'AbortError') return;
                    console.error('[BuscaCiudadano] Error en la petición:', err && err.message ? err.message : err, err);
                    inp?.classList.remove('loading-input');
                });
        }

        function buscarPorCedulaEmpleado() {
            programarBusquedaCiudadanoPorCedulaEmpleado();
        }

        function quitarMarcaCamposDesdeCiudadanoEmpleado() {
            document.getElementById('fecha_nacimiento-empleado')?.classList.remove('campos-desde-ciudadano');
            if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                flatpickrFechaNacimientoEmpleado.altInput.classList.remove('campos-desde-ciudadano');
            }
            document.getElementById('custom-genero-button-emp')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('estado_id-empleado')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('municipio_id-empleado')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('estado-search-button-empleado')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('municipio-search-button-empleado')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('ciudadano_email-empleado')?.classList.remove('campos-desde-ciudadano');
            document.getElementById('direccion-empleado')?.classList.remove('campos-desde-ciudadano');
        }

        function aplicarMarcaCamposDesdeCiudadanoEmpleado(d) {
            const marca = 'campos-desde-ciudadano';
            if (d && d.ciudadano_fecha_nacimiento && !cneEsValorNATexto(d.ciudadano_fecha_nacimiento)) {
                const finp = document.getElementById('fecha_nacimiento-empleado');
                if (finp) {
                    finp.classList.add(marca, 'input-success');
                    finp.classList.remove('input-error');
                }
                if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                    flatpickrFechaNacimientoEmpleado.altInput.classList.add(marca, 'input-success');
                    flatpickrFechaNacimientoEmpleado.altInput.classList.remove('input-error');
                }
            }
            if (d && d.ciudadano_genero && !cneEsValorNATexto(d.ciudadano_genero)) {
                const btn = document.getElementById('custom-genero-button-emp');
                if (btn) {
                    btn.classList.add(marca, 'input-success');
                    btn.classList.remove('input-error');
                }
            }
            const eidMarca = d && d.estado_id != null ? parseInt(String(d.estado_id), 10) : 0;
            if (d && !isNaN(eidMarca) && eidMarca > 0) {
                const est = document.getElementById('estado_id-empleado');
                const estB = document.getElementById('estado-search-button-empleado');
                if (est) {
                    est.classList.add(marca, 'input-success');
                    est.classList.remove('input-error');
                }
                if (estB) {
                    estB.classList.add(marca, 'input-success');
                    estB.classList.remove('input-error');
                }
            }
            const midMarca = d && d.municipio_id != null ? parseInt(String(d.municipio_id), 10) : 0;
            if (d && !isNaN(midMarca) && midMarca > 0) {
                const mun = document.getElementById('municipio_id-empleado');
                const munB = document.getElementById('municipio-search-button-empleado');
                if (mun) {
                    mun.classList.add(marca, 'input-success');
                    mun.classList.remove('input-error');
                }
                if (munB) {
                    munB.classList.add(marca, 'input-success');
                    munB.classList.remove('input-error');
                }
            }
            const emailRaw = d && d.ciudadano_email != null ? String(d.ciudadano_email).trim() : '';
            if (emailRaw !== '') {
                const em = document.getElementById('ciudadano_email-empleado');
                if (em) {
                    em.classList.add(marca, 'input-success');
                    em.classList.remove('input-error', 'ciudadano-dato-alterado');
                }
            }
            const dirRaw = d && d.ciudadano_direccion != null ? String(d.ciudadano_direccion).trim() : '';
            if (dirRaw !== '' && !cneEsValorNATexto(dirRaw)) {
                const ta = document.getElementById('direccion-empleado');
                if (ta) {
                    ta.classList.add(marca, 'input-success');
                    ta.classList.remove('input-error', 'ciudadano-dato-alterado');
                }
            }
        }

        function guardarSnapshotOpcionalesCiudadanoEmpleado(d) {
            if (!d) {
                snapshotDatosOpcionalesCiudadanoEmpleado = null;
                return;
            }
            const eSnap = d.estado_id != null ? parseInt(String(d.estado_id), 10) : 0;
            const mSnap = d.municipio_id != null ? parseInt(String(d.municipio_id), 10) : 0;
            snapshotDatosOpcionalesCiudadanoEmpleado = {
                email: (d.ciudadano_email != null ? String(d.ciudadano_email) : '').trim(),
                direccion: cneEsValorNATexto(d.ciudadano_direccion) ? '' : String(d.ciudadano_direccion != null ? d.ciudadano_direccion : '').trim(),
                estado_id: !isNaN(eSnap) && eSnap > 0 ? String(eSnap) : '',
                municipio_id: !isNaN(mSnap) && mSnap > 0 ? String(mSnap) : ''
            };
        }

        function actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado() {
            const clsAlt = 'ciudadano-dato-alterado';
            const marca = 'campos-desde-ciudadano';
            const emailEl = document.getElementById('ciudadano_email-empleado');
            const dirEl = document.getElementById('direccion-empleado');
            const estEl = document.getElementById('estado_id-empleado');
            const munEl = document.getElementById('municipio_id-empleado');
            const estBtn = document.getElementById('estado-search-button-empleado');
            const munBtn = document.getElementById('municipio-search-button-empleado');
            [emailEl, dirEl, estEl, munEl, estBtn, munBtn].forEach(el => {
                if (el) el.classList.remove(clsAlt);
            });
            const snap = snapshotDatosOpcionalesCiudadanoEmpleado;
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

        function marcarConflictoDatosCedulaEmpleado(activo) {
            const ids = ['nombres-empleado', 'apellidos-empleado', 'telefono-codigo-empleado', 'telefono-numero-empleado', 'cedula-numero-empleado', 'estado_id-empleado', 'municipio_id-empleado'];
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
            ['estado-search-button-empleado', 'municipio-search-button-empleado'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (activo) {
                    el.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado');
                    el.classList.add('input-error');
                } else {
                    el.classList.remove('input-error');
                }
            });
            const finp = document.getElementById('fecha_nacimiento-empleado');
            if (finp) {
                if (activo) {
                    finp.classList.remove('input-success', 'campos-desde-ciudadano');
                    finp.classList.add('input-error');
                } else {
                    finp.classList.remove('input-error');
                }
            }
            const alt = flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput;
            if (alt) {
                if (activo) {
                    alt.classList.remove('input-success', 'campos-desde-ciudadano');
                    alt.classList.add('input-error');
                } else {
                    alt.classList.remove('input-error');
                }
            }
            const btn = document.getElementById('custom-genero-button-emp');
            if (btn) {
                if (activo) {
                    btn.classList.remove('input-success', 'campos-desde-ciudadano');
                    btn.classList.add('input-error');
                } else {
                    btn.classList.remove('input-error');
                }
            }
            ['ciudadano_email-empleado', 'direccion-empleado'].forEach(id => {
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

        function obtenerFechaNacimientoEmpleadoYmd() {
            const inp = document.getElementById('fecha_nacimiento-empleado');
            if (!inp) return '';
            if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.selectedDates && flatpickrFechaNacimientoEmpleado.selectedDates[0]) {
                const d = flatpickrFechaNacimientoEmpleado.selectedDates[0];
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
        function cneInterpretarRespuestaVerificarCedulaEmpleado(data, ctx) {
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
                console.log('[Verificar cédula] El servidor marcó conflicto pero los datos coinciden tras normalización (empleado).');
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

        async function validarCedulaDuplicadaEmpleado() {
            const tipo = document.getElementById('cedula-tipo-empleado')?.value || 'V';
            const num = document.getElementById('cedula-numero-empleado')?.value?.trim() || '';
            const nom = document.getElementById('nombres-empleado')?.value?.trim() || '';
            const ap = document.getElementById('apellidos-empleado')?.value?.trim() || '';
            const telCod = document.getElementById('telefono-codigo-empleado')?.value || '';
            const telNum = document.getElementById('telefono-numero-empleado')?.value?.trim() || '';
            const fnYmd = obtenerFechaNacimientoEmpleadoYmd();
            const generoVal = document.getElementById('genero-empleado')?.value || '';
            if (!num) return true;
            const nombreCompleto = (nom || 'N/A') + ' ' + (ap || 'N/A');
            const estId = document.getElementById('estado_id-empleado')?.value || '';
            const munId = document.getElementById('municipio_id-empleado')?.value || '';
            const dirT = document.getElementById('direccion-empleado')?.value || '';
            try {
                const r = await fetch(`ajax/verificar_cedula.php?cedula=${encodeURIComponent(tipo + '-' + num)}&nombre=${encodeURIComponent(nombreCompleto)}&nombres=${encodeURIComponent(nom)}&apellidos=${encodeURIComponent(ap)}&telefono=${encodeURIComponent(telCod + '-' + telNum)}&fecha_nacimiento=${encodeURIComponent(fnYmd)}&genero=${encodeURIComponent(generoVal)}&estado_id=${encodeURIComponent(estId)}&municipio_id=${encodeURIComponent(munId)}&direccion=${encodeURIComponent(dirT)}`);
                const d = await r.json();
                return cneInterpretarRespuestaVerificarCedulaEmpleado(d, {
                    nombreCompleto: nombreCompleto,
                    nombres: nom,
                    apellidos: ap,
                    telefonoCompleto: telCod + '-' + telNum,
                    fechaYmd: fnYmd,
                    genero: generoVal,
                    estado_id: estId,
                    municipio_id: munId,
                    direccion: dirT
                });
            } catch (e) { return true; }
        }

        async function validarFormularioCompletoEmpleado() {
            document.getElementById('error-cedula-empleado')?.classList.add('hidden');
            marcarConflictoDatosCedulaEmpleado(false);

            if (!cneAlertaGeneroObligatorioEmpleado()) {
                validarGeneroEmpleado(document.getElementById('genero-empleado'));
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado();
                return false;
            }

            const okNombre = validarNombreEmpleado(document.getElementById('nombres-empleado'));
            const okAp = validarApellidoEmpleado(document.getElementById('apellidos-empleado'));
            const okCed = validarCedulaEmpleado(document.getElementById('cedula-numero-empleado'));
            const okTel = validarTelefonoEmpleado(document.getElementById('telefono-numero-empleado'));
            const okGen = validarGeneroEmpleado(document.getElementById('genero-empleado'));
            const okInst = validarInstitucionEmpleado();
            const okArea = validarAreaEmpleado();
            validarEstadoEmpleado(document.getElementById('estado_id-empleado'));
            validarMunicipioEmpleado(document.getElementById('municipio_id-empleado'));
            const okTipo = validarTipoTramiteEmpleado();
            if (!okNombre || !okAp || !okCed || !okTel || !okGen || !okInst || !okArea || !okTipo) {
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado();
                return false;
            }
            const cedulaValida = await validarCedulaDuplicadaEmpleado();
            if (!cedulaValida) {
                const err = document.getElementById('error-cedula-empleado');
                if (err) {
                    err.textContent = 'Datos diferentes detectados: ya existe un registro con esta cédula. Verifique nombres, apellidos, teléfono, fecha de nacimiento, género, estado, municipio y dirección.';
                    err.classList.remove('hidden');
                }
                marcarConflictoDatosCedulaEmpleado(true);
                actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado();
                return false;
            }
            marcarConflictoDatosCedulaEmpleado(false);
            actualizarAdvertenciaCamposOpcionalesVsSnapshotEmpleado();
            return true;
        }

        function textoTipoTramiteSeleccionadoEmp() {
            const tipoTramiteId = document.getElementById('tipo_tramite_id-empleado')?.value || '';
            if (!tipoTramiteId) return '';
            const listA = Array.isArray(tiposTramiteDataEmp) ? tiposTramiteDataEmp : [];
            const listB = Array.isArray(currentTramiteListEmpleado) ? currentTramiteListEmpleado : [];
            let nombre = listA.find(t => t && String(t.id) === String(tipoTramiteId))?.nombre;
            if (!nombre) nombre = listB.find(t => t && String(t.id) === String(tipoTramiteId))?.nombre;
            return nombre || '';
        }

        /** Texto visible del botón tipo trámite (institución/estado/municipio: input hidden + buscador). */
        function cneTextoLabelTramiteSearchButtonEmp(buttonId) {
            const btn = document.getElementById(buttonId);
            const span = btn?.querySelector('.selected-tramite-text');
            if (!span) return '';
            if (span.classList.contains('tramite-placeholder')) return '';
            return (span.textContent || '').trim();
        }

        function cneFmtMayusConfirmEmp(s) {
            if (s == null || s === '') return '';
            return typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(String(s)) : String(s);
        }

        function cneConfirmacionTextoEstadoEmp() {
            const id = document.getElementById('estado_id-empleado')?.value;
            const fromBtn = cneTextoLabelTramiteSearchButtonEmp('estado-search-button-empleado');
            if (fromBtn) return fromBtn;
            if (!id) return 'N/A';
            const row = (CNE_NS_ESTADOS_EMP || []).find(e => e && String(e.id) === String(id));
            return row?.nombre ? cneFmtMayusConfirmEmp(row.nombre) : 'N/A';
        }

        function cneConfirmacionTextoMunicipioEmp() {
            const eid = document.getElementById('estado_id-empleado')?.value || '';
            const mid = document.getElementById('municipio_id-empleado')?.value || '';
            const fromBtn = cneTextoLabelTramiteSearchButtonEmp('municipio-search-button-empleado');
            if (fromBtn) return fromBtn;
            if (!mid) return 'N/A';
            const mapa = MUNICIPIOS_POR_ESTADO_EMP || {};
            const list = mapa[String(eid)] || mapa[eid] || [];
            const row = (list || []).find(x => x && String(x.id) === String(mid));
            return row?.nombre ? cneFmtMayusConfirmEmp(row.nombre) : 'N/A';
        }

        function cneConfirmacionTextoInstitucionEmp() {
            const hid = document.getElementById('institucion-empleado');
            const val = hid ? String(hid.value || '').trim() : '';
            if (val === 'otro') {
                const otro = document.getElementById('institucion-otro-empleado')?.value.trim() || '';
                return otro ? cneFmtMayusConfirmEmp(otro) : 'N/A';
            }
            const fromBtn = cneTextoLabelTramiteSearchButtonEmp('institucion-search-button-empleado');
            if (fromBtn) return fromBtn;
            if (!val) return 'N/A';
            const row = (CNE_NS_INST_EMP || []).find(x => x && String(x.id) === val);
            return row?.nombre ? cneFmtMayusConfirmEmp(row.nombre) : 'N/A';
        }

        function armarDetalleConfirmacionEmpHtml(estadoTxt, estadoClass) {
            const nombres = document.getElementById('nombres-empleado')?.value.trim() || '';
            const apellidos = document.getElementById('apellidos-empleado')?.value.trim() || '';
            const cedulaTipo = document.getElementById('cedula-tipo-empleado')?.value || 'V';
            const cedulaNumero = document.getElementById('cedula-numero-empleado')?.value.trim() || '';
            const telefonoCodigo = document.getElementById('telefono-codigo-empleado')?.value || '0412';
            const telefonoNumero = document.getElementById('telefono-numero-empleado')?.value.trim() || '';
            const generoSelect = document.getElementById('genero-empleado');
            const generoValue = generoSelect?.value || '';
            let generoText = 'No seleccionado';
            if (generoValue === 'masculino') generoText = 'Masculino';
            else if (generoValue === 'femenino') generoText = 'Femenino';
            const textoEstadoUbic = cneConfirmacionTextoEstadoEmp();
            const textoMunicipioUbic = cneConfirmacionTextoMunicipioEmp();
            let institucion = cneConfirmacionTextoInstitucionEmp();
            if (!institucion || institucion === 'N/A') institucion = 'No seleccionada';
            const areaSelect = document.getElementById('area_id-empleado');
            let area = 'No seleccionada';
            try {
                const si = areaSelect?.selectedIndex;
                if (areaSelect && areaSelect.options && typeof si === 'number' && si >= 0) {
                    area = areaSelect.options[si]?.text || 'No seleccionada';
                }
            } catch (e) { area = 'No seleccionada'; }
            const tipoTramite = textoTipoTramiteSeleccionadoEmp();
            const nombreCompleto = typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto((nombres + ' ' + apellidos).trim()) : (nombres + ' ' + apellidos).trim();
            const cedulaCompleta = cedulaNumero ? (cedulaTipo + '-' + cedulaNumero) : '';
            const telefonoCompleto = telefonoNumero ? (telefonoCodigo + '-' + telefonoNumero) : '';
            const reqsMarcados = obtenerRequisitosMarcadosEmpleado();
            const bloqueRequisitos = (tipoSolicitudActualEmp === 'inmediato' && reqsMarcados.length)
                ? `<div class="pt-2 border-t border-gray-100"><span class="text-gray-600 block mb-1">Requisitos marcados:</span><ul class="list-disc pl-5 text-sm font-semibold text-gray-800">${reqsMarcados.map(r => `<li>${String(r && r.nombre != null ? r.nombre : '').replace(/</g, '&lt;')}</li>`).join('')}</ul></div>`
                : '';
            return `
                <div class="space-y-3">
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Nombre completo:</span><span class="font-semibold text-right">${nombreCompleto || 'No especificado'}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Cédula:</span><span class="font-mono font-semibold">${cedulaCompleta || 'No especificada'}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Teléfono:</span><span class="font-semibold">${telefonoCompleto || 'No especificado'}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Género:</span><span class="font-semibold">${generoText}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Estado:</span><span class="font-semibold text-right">${textoEstadoUbic || 'N/A'}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Municipio:</span><span class="font-semibold text-right">${textoMunicipioUbic || 'N/A'}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Institución:</span><span class="font-semibold text-right">${institucion}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Coordinación:</span><span class="font-semibold text-right">${area}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Tipo de Trámite:</span><span class="font-semibold text-right">${tipoTramite || 'No seleccionado'}</span></div>
                    ${bloqueRequisitos}
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Fecha y Hora:</span><span class="font-semibold">${new Date().toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span></div>
                    <div class="flex justify-between gap-2"><span class="text-gray-600">Estado final:</span><span class="font-semibold ${estadoClass || ''}">${estadoTxt}</span></div>
                </div>`;
        }

        function mostrarModalConfirmacionEmp() {
            const detalles = document.getElementById('confirm-details-emp');
            if (detalles) detalles.innerHTML = armarDetalleConfirmacionEmpHtml('PENDIENTE', 'text-blue-600');
            document.getElementById('confirmModalEmp')?.classList.add('active');
            const cancel = document.getElementById('cancelBtnEmp');
            const confirm = document.getElementById('confirmBtnEmp');
            if (cancel) cancel.onclick = () => document.getElementById('confirmModalEmp')?.classList.remove('active');
            if (confirm) confirm.onclick = () => {
                document.getElementById('confirmModalEmp')?.classList.remove('active');
                enviarFormularioEmp('normal');
            };
        }

        function mostrarModalConfirmacionCompletadoEmp() {
            const detalles = document.getElementById('confirm-completado-details-emp');
            if (detalles) detalles.innerHTML = armarDetalleConfirmacionEmpHtml('COMPLETADO', 'text-green-600');
            document.getElementById('confirmCompletadoModalEmp')?.classList.add('active');
            const cancel = document.getElementById('cancelCompletadoBtnEmp');
            const confirm = document.getElementById('confirmCompletadoBtnEmp');
            if (cancel) cancel.onclick = () => document.getElementById('confirmCompletadoModalEmp')?.classList.remove('active');
            if (confirm) confirm.onclick = () => {
                document.getElementById('confirmCompletadoModalEmp')?.classList.remove('active');
                enviarFormularioEmp('inmediato');
            };
        }

        function enviarFormularioEmp(tipo) {
            if (!cneAlertaGeneroObligatorioEmpleado()) {
                return;
            }
            syncIdCoordinacionDestinoEmpleado();
            const form = document.getElementById('tramitante-form-empleado');
            if (!form) return;
            if (tipo === 'inmediato') {
                const areaVCheck = parseInt(String(document.getElementById('area_id-empleado')?.value || '0'), 10);
                const own = parseInt(String(userCoordinacionId || 0), 10);
                if (own < 1 || areaVCheck !== own) {
                    mostrarToast('Solo puede realizar trámites inmediatos en su propia coordinación');
                    return;
                }
                if (!validarRequisitosTramiteInmediatoEmpleado()) {
                    return;
                }
            }
            const estEl = document.getElementById('estado_id-empleado');
            const munEl = document.getElementById('municipio_id-empleado');
            const estWasDis = !!(estEl && estEl.disabled);
            const munWasDis = !!(munEl && munEl.disabled);
            if (estEl) estEl.disabled = false;
            if (munEl) munEl.disabled = false;
            const formData = new FormData(form);
            formData.set('tipo_solicitud', tipo);
            const ts = document.getElementById('tipo_solicitud-empleado');
            if (ts) ts.value = tipo;
            formData.set('tipo_tramite_id', document.getElementById('tipo_tramite_id-empleado').value);
            if (tipo === 'inmediato') {
                const detalle = obtenerRequisitosMarcadosEmpleado();
                const ids = detalle.map(x => x.id);
                formData.set('requisitos_seleccionados', JSON.stringify(ids));
                formData.set('requisitos_seleccionados_detalle', JSON.stringify(detalle));
                formData.set('requisitos_marcados_nombres', JSON.stringify(obtenerNombresRequisitosMarcadosEmpleado()));
            }
            const areaV = document.getElementById('area_id-empleado')?.value || '';
            formData.set('area_id', areaV);
            formData.set('id_coordinacion_destino', areaV);

            const confirmBtn = tipo === 'normal' ? document.getElementById('confirmBtnEmp') : document.getElementById('confirmCompletadoBtnEmp');
            const orig = confirmBtn ? confirmBtn.innerHTML : '';
            if (confirmBtn) {
                confirmBtn.innerHTML = '<div class="loading mx-auto" style="width:20px;height:20px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite"></div> Procesando...';
                confirmBtn.disabled = true;
            }

            let envioEmpExito = false;
            fetch('ajax/empleado_registrar_solicitud.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        envioEmpExito = true;
                        const num = data.numero_seguimiento || '';
                        document.getElementById('numero-seguimiento-generado-emp').textContent = num;
                        const msgEl = document.getElementById('success-message-emp');
                        const estadoInfo = document.getElementById('estado-info-emp');
                        if (tipo === 'inmediato') {
                            if (msgEl) msgEl.textContent = 'El trámite se ha registrado y completado exitosamente';
                            if (estadoInfo) estadoInfo.innerHTML = '<div class="flex items-center justify-center gap-2 mt-2"><span class="status-badge status-completado"><i class="fas fa-check-circle"></i> COMPLETADO</span></div>';
                        } else {
                            if (msgEl) msgEl.textContent = 'La solicitud ha sido registrada exitosamente en el sistema';
                            if (estadoInfo) estadoInfo.innerHTML = '<div class="flex items-center justify-center gap-2 mt-2"><span class="status-badge status-pendiente">PENDIENTE</span></div>';
                        }
                        document.getElementById('successModalEmp')?.classList.add('active');
                        limpiarFormularioNuevaSolicitud();
                        cargarPendientes();
                        cargarHistorial();
                        mostrarToast(data.message || (tipo === 'inmediato' ? 'Trámite completado' : 'Solicitud registrada') + (num ? ' — Nº ' + num : ''));
                    } else {
                        alert('Error: ' + (data.message || 'No se pudo registrar'));
                    }
                })
                .catch(() => alert('Error al procesar la solicitud'))
                .finally(() => {
                    if (!envioEmpExito) {
                        if (estEl && estWasDis) estEl.disabled = true;
                        if (munEl && munWasDis) munEl.disabled = true;
                    }
                    if (confirmBtn) {
                        confirmBtn.innerHTML = orig;
                        confirmBtn.disabled = false;
                    }
                });
        }

        function limpiarFormularioNuevaSolicitud() {
            const form = document.getElementById('tramitante-form-empleado');
            if (!form) return;
            snapshotDatosOpcionalesCiudadanoEmpleado = null;
            quitarProteccionIdentidadCiudadanoEmpleado();
            quitarMarcaCamposDesdeCiudadanoEmpleado();
            document.getElementById('fecha_nacimiento-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'input-error');
            if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                flatpickrFechaNacimientoEmpleado.altInput.classList.remove('input-success', 'campos-desde-ciudadano', 'input-error');
            }
            document.getElementById('custom-genero-button-emp')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none');
            document.getElementById('estado_id-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'input-error');
            document.getElementById('municipio_id-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'input-error');
            document.getElementById('estado-search-button-empleado')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'loading-input');
            document.getElementById('municipio-search-button-empleado')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'loading-input');
            document.getElementById('institucion-search-button-empleado')?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'loading-input');
            document.getElementById('ciudadano_email-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'input-error');
            document.getElementById('direccion-empleado')?.classList.remove('input-success', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'input-error');
            ['nombres-empleado', 'apellidos-empleado', 'cedula-tipo-empleado', 'cedula-numero-empleado', 'telefono-codigo-empleado', 'telefono-numero-empleado', 'institucion-empleado', 'institucion-otro-empleado', 'area_id-empleado'].forEach(id => {
                document.getElementById(id)?.classList.remove('input-success', 'input-error', 'campos-desde-ciudadano', 'ciudadano-dato-alterado', 'ciudadano-campo-protegido', 'ciudadano-campo-na-editable', 'pointer-events-none', 'loading-input');
            });
            form.querySelectorAll('.error-message').forEach(div => div.classList.add('hidden'));
            form.querySelectorAll('.input-success, .input-error').forEach(el => el.classList.remove('input-success', 'input-error'));
            form.querySelectorAll('.ciudadano-dato-alterado').forEach(el => el.classList.remove('ciudadano-dato-alterado'));
            form.reset();
            quitarProteccionIdentidadCiudadanoEmpleado();
            if (flatpickrFechaNacimientoEmpleado) flatpickrFechaNacimientoEmpleado.clear();
            const finpEmp = document.getElementById('fecha_nacimiento-empleado');
            if (finpEmp && !finpEmp.classList.contains('ciudadano-campo-na-editable')) {
                finpEmp.setAttribute('readonly', 'readonly');
                if (flatpickrFechaNacimientoEmpleado && flatpickrFechaNacimientoEmpleado.altInput) {
                    flatpickrFechaNacimientoEmpleado.altInput.setAttribute('readonly', 'readonly');
                }
            }
            const areaSel = document.getElementById('area_id-empleado');
            if (areaSel) {
                if (MI_AREA_DEFAULT_EMP) {
                    areaSel.value = String(MI_AREA_DEFAULT_EMP);
                } else {
                    areaSel.value = '';
                }
                syncIdCoordinacionDestinoEmpleado();
                cargarTiposTramiteEmpleadoDropdown(areaSel.value);
            }
            const inst = document.getElementById('institucion-empleado');
            if (inst?.dataset.personalId) {
                inst.value = inst.dataset.personalId;
                inst.dispatchEvent(new Event('change'));
            }
            document.getElementById('institucion-otro-wrapper-empleado')?.classList.add('hidden');
            const io = document.getElementById('institucion-otro-empleado');
            if (io) io.value = '';
            document.getElementById('tipo_tramite_id-empleado').value = '';
            const labelEl = document.getElementById('tramite-selected-label-empleado');
            if (labelEl) {
                labelEl.textContent = 'Seleccione un tipo de trámite';
                labelEl.className = 'selected-tramite-text tramite-placeholder';
            }
            const tramInpEmp = document.getElementById('tramite-search-input-empleado');
            if (tramInpEmp) tramInpEmp.value = '';
            const subW = document.getElementById('subtramite-wrapper-empleado');
            subW?.classList.add('hidden');
            const subSel = document.getElementById('subtramite_id-empleado');
            if (subSel) {
                subSel.innerHTML = '<option value="">Seleccione una variante</option>';
                subSel.value = '';
            }
            document.getElementById('requisitos-wrapper-empleado')?.classList.add('hidden');
            const rq = document.getElementById('requisitos-list-empleado');
            if (rq) rq.innerHTML = '';
            document.getElementById('campos-dinamicos-empleado')?.classList.add('hidden');
            const cc = document.getElementById('campos-contenido-empleado');
            if (cc) cc.innerHTML = '';
            populateMunicipiosEmpleado(document.getElementById('estado_id-empleado')?.value || '');
            if (typeof window.cneNuevaSolicitudCombosSyncInstitucionBoton === 'function') {
                window.cneNuevaSolicitudCombosSyncInstitucionBoton();
            }
            document.getElementById('institucion-empleado')?.dispatchEvent(new Event('change'));
            const ts = document.getElementById('tipo_solicitud-empleado');
            if (ts) ts.value = 'normal';
            tipoSolicitudActualEmp = 'normal';
            actualizarEstadoBotonTramiteInmediatoEmpleado();
            const genSelect = document.getElementById('genero-empleado');
            if (genSelect) {
                genSelect.value = '';
                genSelect.dispatchEvent(new Event('change'));
            }
        }

        // El resto del código JavaScript (notificaciones, modales, etc.) se mantiene igual.
        // A continuación se incluye el código original completo para no perder ninguna función.

        // Notificaciones
        let ultimosIds = new Set();
        function inicializarNotificaciones() {
            const btn = document.getElementById('btn-notificaciones');
            const panel = document.getElementById('panel-notificaciones');
            const cerrar = document.getElementById('btn-cerrar-panel');
            const marcarTodo = document.getElementById('btn-marcar-todo');
            const userDropdown = document.getElementById('dropdown-menu');
            btn?.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.toggle('hidden');
                cerrarTodosToasts();
                if (userDropdown && !userDropdown.classList.contains('hidden')) {
                    userDropdown.classList.add('hidden');
                }
            });
            cerrar?.addEventListener('click', () => panel.classList.add('hidden'));
            document.addEventListener('click', () => panel.classList.add('hidden'));
            marcarTodo?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                console.log('Marcar todo como leído: clic detectado');
                marcarTodasLeidas();
            });
            fetchNotificaciones();
        }

        function fetchNotificaciones() {
            fetch('ajax/empleado_obtener_notificaciones.php')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    actualizarBadge(data.unread);
                    renderNotificaciones(data.notificaciones || []);
                    // Toast por nuevas
                    (data.notificaciones || []).forEach(n => {
                        if (!ultimosIds.has(n.notificacion_id)) {
                            ultimosIds.add(n.notificacion_id);
                            if (n.notificacion_estado === 'no_leido') mostrarToast(n.mensaje);
                        }
                    });
                })
                .catch(() => {});
        }

        function actualizarBadge(count) {
            const badge = document.getElementById('badge-notificaciones');
            if (count > 0) {
                badge.textContent = count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        function renderNotificaciones(lista) {
            const cont = document.getElementById('lista-notificaciones');
            if (!lista.length) {
                cont.innerHTML = '<div class="px-4 py-6 text-center text-gray-500">Sin notificaciones</div>';
                return;
            }
            // Mostrar solo las últimas 4 (ya vienen ordenadas DESC desde el backend)
            const ultimas = (lista || []).slice(0, 4);
            cont.innerHTML = ultimas.map(n => `
                <button class="w-full text-left px-4 py-3 hover:bg-gray-50 border-b border-gray-100 flex items-start gap-3"
                        onclick="clickNotificacion(${n.notificacion_id}, '${n.solicitud_numero || ''}')">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-gray-800">${n.notificacion_titulo || 'Notificación'}</p>
                        <p class="text-sm text-gray-600">${n.mensaje}</p>
                        <p class="text-xs text-gray-400 mt-1">${n.fecha}</p>
                    </div>
                    ${n.notificacion_estado === 'no_leido' ? '<span class="ml-2 h-2 w-2 bg-blue-500 rounded-full mt-1"></span>' : ''}
                </button>
            `).join('');
        }

        function marcarTodasLeidas() {
            console.log('Marcar todo como leído: iniciando petición');
            fetch('ajax/marcar_notificaciones_leidas.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: ''
            })
            .then(r => {
                if (!r.ok) {
                    throw new Error('Error HTTP ' + r.status);
                }
                return r.json();
            })
            .then(data => {
                if (!data?.success) {
                    console.error('No se pudo marcar como leído', data);
                    alert('No se pudo marcar como leído');
                    return;
                }
                actualizarBadge(0);
                const cont = document.getElementById('lista-notificaciones');
                cont?.querySelectorAll('.bg-blue-500').forEach(dot => dot.remove());
                fetchNotificaciones();
            })
            .catch(err => {
                console.error('Error al marcar todas como leídas', err);
                alert('Error al marcar todas como leídas');
            });
        }

        (function inicializarDropdownUsuario() {
            const userBtn = document.getElementById('user-dropdown-btn');
            const menu = document.getElementById('dropdown-menu');
            const notifPanel = document.getElementById('panel-notificaciones');
            if (!userBtn || !menu) return;
            userBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.classList.toggle('hidden');
                if (notifPanel && !notifPanel.classList.contains('hidden')) {
                    notifPanel.classList.add('hidden');
                }
            });
            menu.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', () => menu.classList.add('hidden'));
        })();
        function clickNotificacion(id, numero) {
            fetch('ajax/empleado_marcar_notificacion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ notificacion_id: id }).toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fetchNotificaciones(); // Actualiza badge y panel
                    const num = data.solicitud_numero || numero;
                    if (num) {
                        // Cambiar a la sección de pendientes
                        try {
                            document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                            document.querySelector('.menu-item[data-section="pendientes"]')?.classList.add('active');
                            document.querySelectorAll('.section').forEach(sec => sec.classList.add('hidden'));
                            document.getElementById('seccion-pendientes')?.classList.remove('hidden');
                            document.getElementById('section-title').textContent = 'Trámites Pendientes';
                        } catch (e) {}
                        // Refrescar y resaltar el trámite específico
                        fetch('ajax/empleado_obtener_solicitudes.php?estado=pendiente')
                            .then(r => r.json())
                            .then(d => {
                                const cont = document.getElementById('pendientes-container');
                                if (d.success && d.solicitudes) {
                                    const lista = d.solicitudes.filter(s => s.solicitud_numero === num);
                                    cont.innerHTML = lista.length ? lista.map(s => cardSolicitud(s)).join('') 
                                                                  : '<div class="col-span-full text-center text-gray-500 py-8">No se encontró el trámite</div>';
                                }
                            });
                    }
                } else {
                    console.error('Error al marcar notificación:', data.message);
                    alert('Error al marcar notificación: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error en fetch:', error);
                alert('Error de conexión al marcar notificación');
            });
        }

        // Toast simple con transición
        function mostrarToast(mensaje) {
            const toast = document.createElement('div');
            toast.className = 'toast bg-white border border-blue-200 shadow-lg rounded-md px-4 py-3 text-sm text-gray-800 flex items-start gap-2';
            toast.innerHTML = `<i class="fas fa-bell text-blue-500 mt-0.5"></i><span>${mensaje}</span>`;
            
            // Agregar al contenedor
            toastContainer.appendChild(toast);
            
            // Auto-cerrar después de 5 segundos con transición
            const timeoutId = setTimeout(() => cerrarToast(toast), 5000);
            
            // Guardar el timeout en el elemento para poder cancelarlo si se cierra manualmente
            toast._timeoutId = timeoutId;
        }

        // Cierra un toast específico con animación
        function cerrarToast(toast) {
            // Cancelar el timeout asociado si existe
            if (toast._timeoutId) {
                clearTimeout(toast._timeoutId);
                delete toast._timeoutId;
            }
            
            // Aplicar clase de fade-out
            toast.classList.add('fade-out');
            
            // Eliminar del DOM después de la transición
            setTimeout(() => {
                if (toast.parentNode) toast.remove();
            }, 300);
        }

        // Cierra todos los toasts visibles
        function cerrarTodosToasts() {
            Array.from(toastContainer.children).forEach(toast => cerrarToast(toast));
        }

        function cargarPendientes() {
            fetch('ajax/empleado_obtener_solicitudes.php?estado=pendiente')
                .then(r => r.json())
                .then(data => {
                    const cont = document.getElementById('pendientes-container');
                    if (!data.success || !data.solicitudes || data.solicitudes.length === 0) {
                        cont.innerHTML = '<div class="col-span-full text-center text-gray-500 py-8">No hay trámites pendientes</div>';
                        return;
                    }
                    cont.innerHTML = data.solicitudes.map(s => cardSolicitud(s)).join('');
                })
                .catch(() => {
                    document.getElementById('pendientes-container').innerHTML = '<div class="col-span-full text-center text-red-500 py-8">Error al cargar pendientes</div>';
                });
        }

        function cardSolicitud(s) {
            const noGestionableCard = (s.solicitud_estado === 'redirigida') || (MI_COORDINACION_ID && String(s.coordinacion_id) !== String(MI_COORDINACION_ID));
            const vencido = esTramiteVencidoNoGestion(s);
            const soloDetallesCard = noGestionableCard || s.solicitud_estado === 'completada' || s.solicitud_estado === 'invalidada' || vencido;
            let estadoClass = 'status-pendiente';
            let estadoText = 'Pendiente';
            if (s.solicitud_estado === 'invalidada') {
                estadoClass = 'status-invalidada';
                estadoText = 'Invalidada';
            } else if (vencido) {
                estadoClass = 'status-vencido';
                estadoText = 'VENCIDO';
            } else if (s.solicitud_estado === 'en_revision' || s.solicitud_estado === 'en_proceso') {
                estadoClass = 'status-proceso';
                estadoText = 'En Proceso';
            } else if (s.solicitud_estado === 'completada') {
                estadoClass = 'status-completado';
                estadoText = 'Completada';
            }
            const accionBtn = soloDetallesCard
                ? `<button type="button" class="btn btn-secondary" onclick='mostrarHistorial(${JSON.stringify(s)})'><i class="fas fa-info-circle"></i> Detalles</button>`
                : `<button type="button" class="btn btn-primary" onclick='abrirModal(${JSON.stringify(s)})'><i class="fas fa-eye"></i> Gestionar</button>`;
            return `
                <div class="bg-white rounded-xl shadow p-4 border border-gray-100">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <p class="text-sm text-gray-500">Número</p>
                            <p class="font-mono font-semibold text-blue-600">${s.solicitud_numero}</p>
                        </div>
                        <span class="status-badge ${estadoClass}">${estadoText}</span>
                    </div>
                    <div class="space-y-2 text-sm text-gray-700">
                        <div class="flex justify-between"><span>Ciudadano:</span><span class="font-semibold">${typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_nombre || '') : (s.ciudadano_nombre || '')}</span></div>
                        <div class="flex justify-between"><span>Cédula:</span><span class="font-mono">${typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_identificacion || '') : (s.ciudadano_identificacion || '')}</span></div>
                        <div class="flex justify-between"><span>Trámite:</span><span class="font-semibold">${s.tramite_nombre}</span></div>
                        <div class="flex justify-between"><span>Fecha:</span><span>${s.fecha_registro}</span></div>
                    </div>
                    <div class="mt-4 flex justify-end">
                        ${accionBtn}
                    </div>
                </div>
            `;
        }

        function cargarHistorial() {
            const qs = construirQS();
            fetch('ajax/empleado_obtener_solicitudes.php' + qs)
                .then(r => r.json())
                .then(data => {
                    const tbody = document.getElementById('historial-body');
                    if (!data.success || !data.solicitudes || data.solicitudes.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No hay datos</td></tr>';
                        document.getElementById('contador-historial').textContent = '0';
                        return;
                    }
                    document.getElementById('contador-historial').textContent = data.solicitudes.length;
                    tbody.innerHTML = data.solicitudes.map(s => rowHistorial(s)).join('');
                })
                .catch(() => {
                    document.getElementById('historial-body').innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-sm text-red-500">Error al cargar historial</td></tr>';
                });
        }

        function construirQS() {
            const estado = document.getElementById('filtro-estado').value || '';
            const cedula = document.getElementById('filtro-cedula').value || '';
            const tipo = document.getElementById('filtro-tipo-tramite').value || '';
            const fd = document.getElementById('filtro-fecha-desde').value || '';
            const fh = document.getElementById('filtro-fecha-hasta').value || '';
            const params = new URLSearchParams();
            if (estado) params.set('estado', estado);
            if (cedula) params.set('cedula', cedula);
            if (tipo) params.set('tipo_tramite', tipo);
            if (fd) params.set('fecha_desde', fd);
            if (fh) params.set('fecha_hasta', fh);
            return '?' + params.toString();
        }

        document.getElementById('btn-aplicar-filtros')?.addEventListener('click', cargarHistorial);
        document.getElementById('btn-reset-filtros')?.addEventListener('click', () => {
            document.getElementById('filtro-cedula').value = '';
            document.getElementById('filtro-estado').value = '';
            document.getElementById('filtro-tipo-tramite').value = '';
            document.getElementById('filtro-fecha-desde').value = '';
            document.getElementById('filtro-fecha-hasta').value = '';
            cargarHistorial();
        });

        function celdaCiudadanoHistorial(s) {
            let n = (s.ciudadano_nombres != null && s.ciudadano_nombres !== '') ? String(s.ciudadano_nombres).trim() : '';
            let a = (s.ciudadano_apellidos != null && s.ciudadano_apellidos !== '') ? String(s.ciudadano_apellidos).trim() : '';
            if (!n && !a && s.ciudadano_nombre) {
                const parts = String(s.ciudadano_nombre).trim().split(/\s+/).filter(Boolean);
                if (parts.length) {
                    n = parts.shift();
                    a = parts.join(' ');
                }
            }
            const nEsc = escapeHtmlHistorial(typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(n || '—') : (n || '—'));
            const aEsc = escapeHtmlHistorial(typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(a || '') : (a || ''));
            return `<div class="text-left leading-snug"><span class="block text-sm font-normal text-gray-800">${nEsc}</span><span class="block text-sm font-normal text-gray-600">${aEsc || '—'}</span></div>`;
        }

        function celdaFechaHistorial(fechaRegistro) {
            const raw = (fechaRegistro || '').trim();
            if (!raw) return '<span class="text-gray-400 block text-center">—</span>';
            const m = raw.match(/^(\d{2}\/\d{2}\/\d{4})\s+(.+)$/);
            if (!m) return `<span class="text-gray-500 text-sm font-normal block text-center">${escapeHtmlHistorial(raw)}</span>`;
            return `<div class="text-center text-gray-700"><span class="block text-sm font-normal text-gray-800 leading-tight">${escapeHtmlHistorial(m[1])}</span><span class="block text-sm font-normal text-gray-500 leading-tight">${escapeHtmlHistorial(m[2])}</span></div>`;
        }

        function rowHistorial(s) {
            let estadoClass = 'status-pendiente', estadoText = 'Pendiente';
            const vencido = esTramiteVencidoNoGestion(s);
            if (s.solicitud_estado === 'invalidada') {
                estadoClass = 'status-invalidada';
                estadoText = 'Invalidada';
            } else if (vencido) {
                estadoClass = 'status-vencido';
                estadoText = 'VENCIDO';
            } else {
                switch (s.solicitud_estado) {
                    case 'pendiente': estadoClass = 'status-pendiente'; estadoText = 'Pendiente'; break;
                    case 'en_proceso': estadoClass = 'status-proceso'; estadoText = 'En Proceso'; break;
                    case 'en_revision': estadoClass = 'status-proceso'; estadoText = 'En Proceso'; break;
                    case 'aprobada': estadoClass = 'status-proceso'; estadoText = 'En Proceso'; break;
                    case 'completada': estadoClass = 'status-completado'; estadoText = 'Completada'; break;
                    case 'redirigida': estadoClass = 'status-redirigido'; estadoText = 'Redirigida'; break;
                    case 'rechazada': estadoClass = 'status-vencido'; estadoText = 'VENCIDO'; break;
                    case 'vencida': estadoClass = 'status-vencido'; estadoText = 'VENCIDO'; break;
                    default: estadoClass = 'status-pendiente'; estadoText = (s.solicitud_estado && typeof s.solicitud_estado === 'string') ? (s.solicitud_estado.charAt(0).toUpperCase() + s.solicitud_estado.slice(1).toLowerCase().replace(/_/g, ' ')) : 'Pendiente'; break;
                }
            }
            const noGestionable = (s.solicitud_estado === 'redirigida') || (MI_COORDINACION_ID && String(s.coordinacion_id) !== String(MI_COORDINACION_ID));
            const soloDetalles = noGestionable || s.solicitud_estado === 'completada' || s.solicitud_estado === 'invalidada' || vencido;
            const rowOpacity = noGestionable ? 'opacity-75' : '';
            const tramiteEsc = escapeHtmlHistorial(s.tramite_nombre || '');
            const caracasHtml = s.en_caracas
                ? '<div class="mt-0.5"><span class="status-badge status-en-caracas">En Caracas</span></div>'
                : '';
            return `
                <tr class="${rowOpacity}">
                    <td class="text-left align-top text-sm font-normal text-gray-900"><span class="font-mono break-all leading-tight">${escapeHtmlHistorial(typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_identificacion || '') : (s.ciudadano_identificacion || ''))}</span></td>
                    <td class="text-left align-top min-w-0">${celdaCiudadanoHistorial(s)}</td>
                    <td class="text-center align-middle whitespace-nowrap"><span class="status-badge ${estadoClass}">${estadoText}</span></td>
                    <td class="text-left align-top min-w-0 historial-col-tipo">
                        <div class="text-gray-600 whitespace-normal break-words [word-break:break-word] leading-snug text-sm font-normal">${tramiteEsc}</div>${caracasHtml}
                    </td>
                    <td class="text-center align-top whitespace-nowrap text-sm font-normal text-blue-600"><span class="font-mono">${escapeHtmlHistorial(s.solicitud_numero)}</span></td>
                    <td class="text-center align-top">${celdaFechaHistorial(s.fecha_registro)}</td>
                    <td class="text-center align-middle whitespace-nowrap">
                        ${ soloDetalles
                            ? `<button type="button" class="btn btn-secondary" onclick='mostrarHistorial(${JSON.stringify(s)})'><i class="fas fa-info-circle"></i> Detalles</button>`
                            : `<button type="button" class="btn btn-secondary" onclick='abrirModal(${JSON.stringify(s)})'><i class="fas fa-eye"></i> Gestionar</button>` }
                    </td>
                </tr>
            `;
        }

        function abrirModal(s) {
            if (s.solicitud_estado === 'invalidada') {
                mostrarHistorial(s);
                return;
            }
            if (esTramiteVencidoNoGestion(s)) {
                mostrarHistorial(s);
                return;
            }
            currentSolicitud = s;
            document.getElementById('modal-codigo').value = s.codigo_interno || '';
            document.getElementById('modal-observaciones').value = '';
            document.getElementById('modal-datos').innerHTML = `
                <div><p class="text-sm text-gray-500">Número</p><p class="font-mono font-semibold text-blue-600">${s.solicitud_numero}</p></div>
                <div><p class="text-sm text-gray-500">Ciudadano</p><p class="font-semibold">${typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_nombre || '') : (s.ciudadano_nombre || '')}</p></div>
                <div><p class="text-sm text-gray-500">Cédula</p><p class="font-mono">${typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_identificacion || '') : (s.ciudadano_identificacion || '')}</p></div>
                <div><p class="text-sm text-gray-500">Trámite</p><p class="font-semibold">${s.tramite_nombre}</p></div>
            `;
            cargarDetalles(s.solicitud_id, s.tramite_id);
            // Reset botones a deshabilitado visualmente
            ['btn-iniciar','btn-completar','btn-redirigir','btn-invalidar','btn-enviar','btn-recibir'].forEach(id => {
                const b = document.getElementById(id);
                if (b) { b.disabled = true; b.classList.add('opacity-50','cursor-not-allowed'); }
            });
            // Completada, invalidada, vencida / vencido por plazo o sin permiso: ocultar acciones de gestión
            const noGestionable = (s.solicitud_estado === 'redirigida') || (MI_COORDINACION_ID && String(s.coordinacion_id) !== String(MI_COORDINACION_ID));
            if (s.solicitud_estado === 'completada' || s.solicitud_estado === 'invalidada' || noGestionable || esTramiteVencidoNoGestion(s)) {
                ['btn-iniciar','btn-completar','btn-redirigir','btn-invalidar'].forEach(id => {
                    const b = document.getElementById(id);
                    if (b) b.classList.add('hidden');
                });
            } else {
                ['btn-iniciar','btn-completar','btn-redirigir','btn-invalidar'].forEach(id => {
                    const b = document.getElementById(id);
                    if (b) b.classList.remove('hidden');
                });
            }
            const iniciarBtn = document.getElementById('btn-iniciar');
            if (iniciarBtn) {
                iniciarBtn.innerHTML = (s.solicitud_estado === 'en_revision')
                    ? '<i class="fas fa-forward"></i> Continuar Gestión'
                    : '<i class="fas fa-play"></i> Iniciar';
            }
            const enviarBtn = document.getElementById('btn-enviar');
            const recibirBtn = document.getElementById('btn-recibir');
            if (enviarBtn && recibirBtn) {
                if (esTramiteVencidoNoGestion(s) || s.solicitud_estado === 'completada' || s.solicitud_estado === 'invalidada' || noGestionable) {
                    enviarBtn.classList.add('hidden');
                    recibirBtn.classList.add('hidden');
                } else if (s.solicitud_estado === 'en_revision') {
                    if (s.en_caracas) {
                        enviarBtn.classList.add('hidden');
                        recibirBtn.classList.remove('hidden');
                    } else {
                        enviarBtn.classList.remove('hidden');
                        recibirBtn.classList.add('hidden');
                    }
                } else {
                    enviarBtn.classList.add('hidden');
                    recibirBtn.classList.add('hidden');
                }
            }
            modal.classList.add('active');
        }

        document.getElementById('modal-close')?.addEventListener('click', () => {
            modal.classList.remove('active');
            document.getElementById('modal-requisitos-toolbar')?.classList.add('hidden');
            const obsField = document.getElementById('modal-observaciones');
            if (obsField) { obsField.value = ''; }
            const obsCounter = document.getElementById('obs-counter');
            if (obsCounter) { obsCounter.textContent = 'Mínimo 10 caracteres: 0/10'; obsCounter.classList.remove('text-green-600'); obsCounter.classList.add('text-gray-500'); }
        });

        function cargarRequisitos(tramiteId, seleccionados = []) {
            const reqCont = document.getElementById('modal-requisitos');
            const reqToolbar = document.getElementById('modal-requisitos-toolbar');
            if (reqToolbar) reqToolbar.classList.add('hidden');
            reqCont.innerHTML = '<p class="text-sm text-gray-500">Cargando requisitos...</p>';
            fetch('ajax/obtener_requisitos.php?tramite_id=' + tramiteId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const list = data.requisitos || [];
                        if (reqToolbar) reqToolbar.classList.toggle('hidden', list.length === 0);
                        reqCont.innerHTML = list.map(r => {
                            const nombre = (r.nombre || '').trim();
                            const esAsesoria = /^asesor[ií]a$/i.test(nombre);
                            const checked = seleccionados.includes(r.id) ? 'checked' : '';
                            return `
                            <label class="flex items-center gap-2 text-sm requisito-item" data-req-id="${r.id}" data-name="${nombre}">
                                <input type="checkbox" class="req-item w-4 h-4 border-gray-300 rounded" data-id="${r.id}" data-name="${nombre}" data-asesoria="${esAsesoria ? '1' : '0'}" ${checked}>
                                <span>${nombre}</span>
                            </label>`;
                        }).join('');
                        // Lógica exclusiva para 'Asesoría'
                        inicializarReglaAsesoria(reqCont);
                    } else {
                        if (reqToolbar) reqToolbar.classList.add('hidden');
                        reqCont.innerHTML = '<p class="text-sm text-red-500">No hay requisitos disponibles</p>';
                    }
                })
                .catch(() => {
                    if (reqToolbar) reqToolbar.classList.add('hidden');
                    reqCont.innerHTML = '<p class="text-sm text-red-500">Error al cargar requisitos</p>';
                });
        }

        function seleccionarTodosRequisitosModal() {
            const reqCont = document.getElementById('modal-requisitos');
            if (!reqCont) return;
            reqCont.querySelectorAll('input.req-item').forEach(i => {
                if (i.dataset.asesoria === '1') return;
                if (i.disabled) return;
                i.checked = true;
            });
        }
        function cargarDetalles(sid, tramiteId) {
            fetch('ajax/empleado_obtener_detalles.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ solicitud_id: sid }).toString()
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    if (d.codigo_interno) {
                        document.getElementById('modal-codigo').value = d.codigo_interno || '';
                    }
                    const seleccionados = Array.isArray(d.requisitos_aprobados) ? d.requisitos_aprobados : [];
                    cargarRequisitos(tramiteId, seleccionados);
                } else {
                    cargarRequisitos(tramiteId, []);
                }
            })
            .catch(() => cargarRequisitos(tramiteId, []));
        }
        function inicializarReglaAsesoria(container, itemClass = 'req-item') {
            const inputs = Array.from(container.querySelectorAll('input.' + itemClass));
            if (!inputs.length) return;
            const asesoria = inputs.find(i => i.dataset.asesoria === '1' || (i.dataset.name || '').toLowerCase() === 'asesoría' || (i.dataset.name || '').toLowerCase() === 'asesoria');
            const otros = inputs.filter(i => i !== asesoria);
            const setDisabled = (disabled) => {
                otros.forEach(i => {
                    const label = i.closest('label');
                    if (disabled) {
                        i.checked = false;
                        i.disabled = true;
                        if (label) label.classList.add('opacity-50', 'grayscale');
                    } else {
                        i.disabled = false;
                        if (label) label.classList.remove('opacity-50', 'grayscale');
                    }
                });
            };
            if (asesoria) {
                const sync = () => setDisabled(asesoria.checked);
                asesoria.addEventListener('change', sync);
                otros.forEach(i => i.addEventListener('change', () => {
                    if (i.checked && asesoria.checked) asesoria.checked = false;
                }));
                sync();
            }
        }

        function inicializarAccionesModal() {
            document.getElementById('btn-modal-seleccionar-todos-requisitos')?.addEventListener('click', () => {
                seleccionarTodosRequisitosModal();
            });
            document.getElementById('btn-iniciar')?.addEventListener('click', () => {
                const start = currentSolicitud?.solicitud_estado !== 'en_revision';
                guardarProgreso(start).then(ok => {
                    if (ok && start) {
                        currentSolicitud.solicitud_estado = 'en_revision';
                        const iniciarBtn = document.getElementById('btn-iniciar');
                        if (iniciarBtn) iniciarBtn.innerHTML = '<i class="fas fa-forward"></i> Continuar Gestión';
                    }
                });
            });
            document.getElementById('btn-completar')?.addEventListener('click', () => ejecutarAccion('completar'));
            document.getElementById('btn-redirigir')?.addEventListener('click', () => abrirRedirigir());
            document.getElementById('btn-invalidar')?.addEventListener('click', () => abrirInvalidar());
            document.getElementById('btn-detalles')?.addEventListener('click', () => {
                mostrarHistorial();
            });
            document.getElementById('btn-enviar')?.addEventListener('click', () => guardarProgreso(false, 'enviar'));
            document.getElementById('btn-recibir')?.addEventListener('click', () => guardarProgreso(false, 'recibir'));
            const obs = document.getElementById('modal-observaciones');
            const botonesGestion = ['btn-completar', 'btn-redirigir'].map(id => document.getElementById(id)).filter(Boolean);
            const btnInvalidar = document.getElementById('btn-invalidar');
            const btnIniciar = document.getElementById('btn-iniciar');
            const obsCounter = document.getElementById('obs-counter');
            const btnEnviar = document.getElementById('btn-enviar');
            const btnRecibir = document.getElementById('btn-recibir');
            const toggle = () => {
                const ok = (obs.value || '').trim().length >= 10;
                const deshabilitar = (currentSolicitud?.solicitud_estado === 'redirigida') || (currentSolicitud?.solicitud_estado === 'invalidada') || (MI_COORDINACION_ID && String(currentSolicitud?.coordinacion_id) !== String(MI_COORDINACION_ID)) || esTramiteVencidoNoGestion(currentSolicitud);
                botonesGestion.forEach(b => {
                    const disabled = deshabilitar || !ok;
                    b.disabled = disabled;
                    if (disabled) {
                        b.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        b.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                });
                if (btnInvalidar) {
                    const invHidden = btnInvalidar.classList.contains('hidden');
                    const disabledInv = deshabilitar || invHidden;
                    btnInvalidar.disabled = disabledInv;
                    if (disabledInv) {
                        btnInvalidar.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        btnInvalidar.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                }
                if (btnIniciar) {
                    const disabled = deshabilitar || !ok;
                    btnIniciar.disabled = disabled;
                    if (disabled) {
                        btnIniciar.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        btnIniciar.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                }
                [btnEnviar, btnRecibir].forEach(b => {
                    if (!b) return;
                    const disabled = deshabilitar || !ok;
                    b.disabled = disabled;
                    if (disabled) {
                        b.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        b.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                });
                if (obsCounter) {
                    const len = (obs.value || '').trim().length;
                    obsCounter.textContent = `Mínimo 10 caracteres: ${Math.min(len,10)}/10`;
                    obsCounter.classList.toggle('text-green-600', ok);
                    obsCounter.classList.toggle('text-gray-500', !ok);
                }
            };
            obs?.addEventListener('input', toggle);
            toggle();
        }
        function guardarProgreso(start = false, caracasAction = '') {
            if (!currentSolicitud) return Promise.resolve(false);
            if (esTramiteVencidoNoGestion(currentSolicitud)) {
                alert('Este trámite está vencido y no admite cambios.');
                return Promise.resolve(false);
            }
            const obs = document.getElementById('modal-observaciones');
            const ok = (obs.value || '').trim().length >= 10;
            if (!ok) { alert('Debe escribir al menos 10 caracteres en Observaciones'); return Promise.resolve(false); }
            const codigo = document.getElementById('modal-codigo').value.trim();
            const reqs = Array.from(document.querySelectorAll('#modal-requisitos .req-item'))
                .filter(i => i.checked)
                .map(i => parseInt(i.dataset.id, 10))
                .filter(n => !isNaN(n));
            const body = new URLSearchParams();
            body.set('solicitud_id', currentSolicitud.solicitud_id);
            body.set('codigo_interno', codigo);
            body.set('requisitos', JSON.stringify(reqs));
            body.set('observaciones', (obs.value || '').trim());
            if (start) body.set('start', '1');
            if (caracasAction) body.set('caracas_action', caracasAction);
            fetch('ajax/empleado_guardar_progreso.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    mostrarToast('Progreso guardado correctamente');
                    setTimeout(() => {
                        modal.classList.remove('active');
                        const obsField = document.getElementById('modal-observaciones');
                        if (obsField) { obsField.value = ''; }
                        const obsCounter = document.getElementById('obs-counter');
                        if (obsCounter) { obsCounter.textContent = 'Mínimo 10 caracteres: 0/10'; obsCounter.classList.remove('text-green-600'); obsCounter.classList.add('text-gray-500'); }
                        try {
                            cargarPendientes();
                            cargarHistorial();
                        } catch (e) {}
                    }, 500);
                    return true;
                } else {
                    alert(data.message || 'Error al guardar progreso');
                    return false;
                }
            })
            .catch(() => { alert('Error al guardar progreso'); return false; });
            return Promise.resolve(true);
        }
        function mostrarToast(msg) {
            const t = document.createElement('div');
            t.className = 'fixed bottom-6 right-6 bg-gray-900 text-white px-4 py-2 rounded-lg shadow-lg toast';
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.classList.add('fade-out'); setTimeout(() => t.remove(), 300); }, 1500);
        }
        function abrirRedirigir() {
            const select = document.getElementById('redirigir-select');
            const actualCoord = currentSolicitud?.coordinacion_id || null;
            // Filtrar opciones para ocultar coordinación actual
            Array.from(select.options).forEach(opt => {
                if (!opt.value) return;
                opt.hidden = (String(opt.value) === String(actualCoord));
            });
            REDIRIGIR_MODAL.classList.add('active');
        }
        function cerrarModalRedirigir() {
            REDIRIGIR_MODAL.classList.remove('active');
        }
        document.getElementById('redirigir-close')?.addEventListener('click', cerrarModalRedirigir);
        document.getElementById('redirigir-backdrop')?.addEventListener('click', cerrarModalRedirigir);
        document.getElementById('redirigir-confirm')?.addEventListener('click', () => {
            const dest = document.getElementById('redirigir-select').value;
            if (!dest) { alert('Seleccione una coordinación destino'); return; }
            ejecutarAccion('redirigir', dest);
            REDIRIGIR_MODAL.classList.remove('active');
        });

        function syncInvalidarMotivoHint() {
            const ta = document.getElementById('invalidar-motivo');
            const hint = document.getElementById('invalidar-motivo-hint');
            if (!ta || !hint) return;
            const len = (ta.value || '').trim().length;
            const ok = len >= 5;
            hint.textContent = 'Mínimo 5 caracteres: ' + Math.min(len, 5) + '/5';
            hint.classList.toggle('text-green-600', ok);
            hint.classList.toggle('text-gray-500', !ok);
        }
        function abrirInvalidar() {
            const ta = document.getElementById('invalidar-motivo');
            if (ta) ta.value = '';
            syncInvalidarMotivoHint();
            if (INVALIDAR_MODAL) INVALIDAR_MODAL.classList.add('active');
        }
        function cerrarModalInvalidar() {
            if (INVALIDAR_MODAL) INVALIDAR_MODAL.classList.remove('active');
        }
        document.getElementById('invalidar-close')?.addEventListener('click', cerrarModalInvalidar);
        document.getElementById('invalidar-backdrop')?.addEventListener('click', cerrarModalInvalidar);
        document.getElementById('invalidar-cancelar')?.addEventListener('click', cerrarModalInvalidar);
        document.getElementById('invalidar-motivo')?.addEventListener('input', syncInvalidarMotivoHint);
        document.getElementById('invalidar-confirm')?.addEventListener('click', () => {
            const motivo = (document.getElementById('invalidar-motivo')?.value || '').trim();
            if (motivo.length < 5) {
                alert('Debe indicar un motivo de invalidación (mínimo 5 caracteres).');
                return;
            }
            ejecutarInvalidar(motivo);
        });

        function ejecutarInvalidar(motivo) {
            if (!currentSolicitud) return;
            if (esTramiteVencidoNoGestion(currentSolicitud)) {
                alert('Este trámite está vencido y no admite esta acción.');
                return;
            }
            const codigo = document.getElementById('modal-codigo').value.trim();
            const body = new URLSearchParams();
            body.set('solicitud_id', currentSolicitud.solicitud_id);
            body.set('accion', 'invalidar');
            body.set('codigo_interno', codigo);
            body.set('observaciones', motivo);
            fetch('ajax/empleado_actualizar_solicitud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    cerrarModalInvalidar();
                    modal.classList.remove('active');
                    const ta = document.getElementById('invalidar-motivo');
                    if (ta) ta.value = '';
                    syncInvalidarMotivoHint();
                    cargarPendientes();
                    cargarHistorial();
                } else {
                    alert(data.message || 'Error al invalidar');
                }
            })
            .catch(() => alert('Error al invalidar'));
        }

        function ejecutarAccion(accion, destinoCoord = null) {
            if (!currentSolicitud) return;
            if (esTramiteVencidoNoGestion(currentSolicitud)) {
                alert('Este trámite está vencido y no admite esta acción.');
                return;
            }
            const codigo = document.getElementById('modal-codigo').value.trim();
            const observaciones = document.getElementById('modal-observaciones').value.trim();
            if (observaciones.length < 10) {
                alert('Debe escribir al menos 10 caracteres en Observaciones');
                return;
            }
            const body = new URLSearchParams();
            body.set('solicitud_id', currentSolicitud.solicitud_id);
            body.set('accion', accion);
            body.set('codigo_interno', codigo);
            body.set('observaciones', observaciones);
            if (accion === 'redirigir' && destinoCoord) {
                body.set('destino_coordinacion_id', destinoCoord);
            }
            fetch('ajax/empleado_actualizar_solicitud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    modal.classList.remove('active');
                    cargarPendientes();
                    cargarHistorial();
                } else {
                    alert(data.message || 'Error al actualizar');
                }
            })
            .catch(() => alert('Error al actualizar'));
        }

        function escapeHtmlHistorial(str) {
            if (str == null || str === '') return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function cerrarModalHistorialTramite() {
            window.__CNE_TIMELINE_SOL_ID = null;
            const el = document.getElementById('modal-historial-tramite');
            if (!el) return;
            el.classList.add('hidden');
            el.classList.remove('flex');
            aplicarEstiloHeaderModalHistorial(false);
        }

        function aplicarEstiloHeaderModalHistorial(vencido, etiqueta, invalidada) {
            invalidada = !!invalidada;
            const h = document.getElementById('modal-historial-header');
            const wrap = document.getElementById('modal-historial-estado-wrap');
            const badge = document.getElementById('modal-historial-estado-badge');
            if (!h || !wrap || !badge) return;
            if (invalidada) {
                h.className = 'flex justify-between items-center px-5 py-4 shrink-0 bg-gradient-to-r from-red-900 via-red-800 to-red-600 text-white';
                wrap.classList.remove('hidden');
                badge.textContent = etiqueta || 'Invalidada';
                badge.className = 'status-badge status-invalidada text-xs align-middle';
                return;
            }
            if (vencido) {
                h.className = 'flex justify-between items-center px-5 py-4 shrink-0 bg-gradient-to-r from-slate-600 via-slate-600 to-slate-500 text-white';
                wrap.classList.remove('hidden');
                badge.textContent = etiqueta || 'VENCIDO';
                badge.className = 'status-badge status-vencido text-xs align-middle';
            } else {
                h.className = 'flex justify-between items-center px-5 py-4 bg-gradient-to-r from-blue-900 via-blue-800 to-blue-600 text-white shrink-0';
                wrap.classList.add('hidden');
                badge.textContent = '';
            }
        }

        function abrirModalHistorialTramite(eventos, numeroSeguimiento, opciones) {
            opciones = opciones || {};
            const modal = document.getElementById('modal-historial-tramite');
            const content = document.getElementById('modal-historial-timeline');
            const numEl = document.getElementById('modal-historial-numero');
            if (!modal || !content || !numEl) return;
            aplicarEstiloHeaderModalHistorial(!!opciones.vencido, opciones.estadoEtiqueta, !!opciones.invalidada);
            numEl.textContent = numeroSeguimiento || '—';
            if (!eventos || !eventos.length) {
                content.innerHTML = '<div class="text-center py-10 text-gray-500"><i class="fas fa-inbox text-4xl text-gray-300 mb-3 block"></i>No hay registros de auditoría u observaciones para este trámite.</div>';
            } else {
                content.innerHTML = '<div class="timeline-emp">' + eventos.map(function(ev) {
                    var acc = escapeHtmlHistorial(String(ev.accion || '').replace(/_/g, ' '));
                    var desc = escapeHtmlHistorial(ev.descripcion || '');
                    var fun = escapeHtmlHistorial(ev.funcionario || '');
                    var fh = escapeHtmlHistorial(ev.fecha_hora || '');
                    return '<div class="timeline-emp-item"><span class="timeline-emp-dot" aria-hidden="true"></span>' +
                        '<p class="text-xs font-bold text-blue-800 tracking-wide mb-1">' + acc + '</p>' +
                        '<p class="text-sm text-gray-800 leading-relaxed mb-2">' + desc + '</p>' +
                        '<p class="text-xs text-gray-600"><i class="fas fa-user-tie mr-1 text-blue-500"></i>' + fun + '</p>' +
                        '<p class="text-xs text-gray-500 mt-1"><i class="far fa-clock mr-1"></i>' + fh + '</p>' +
                        '</div>';
                }).join('') + '</div>';
            }
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function mostrarHistorial(s = null) {
            const sol = s || currentSolicitud;
            if (!sol) return;
            window.__CNE_TIMELINE_SOL_ID = sol.solicitud_id != null ? Number(sol.solicitud_id) : null;
            fetch('ajax/empleado_historial_solicitud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ solicitud_id: sol.solicitud_id }).toString()
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Error al obtener historial');
                    return;
                }
                const eventos = data.eventos || [];
                const num = data.solicitud_numero || sol.solicitud_numero || '';
                const inv = (sol.solicitud_estado || '').toLowerCase() === 'invalidada';
                const venc = !inv && esTramiteVencidoNoGestion(sol);
                abrirModalHistorialTramite(eventos, num, {
                    vencido: venc,
                    invalidada: inv,
                    estadoEtiqueta: inv ? 'Invalidada' : 'VENCIDO'
                });
            })
            .catch(() => alert('Error al obtener historial'));
        }

        window.addEventListener('cne:realtime', function(ev) {
            const d = ev.detail && ev.detail.event;
            if (!d || !window.CNE_REALTIME || window.CNE_REALTIME.enabled === false) return;
            if (d.type === 'notification_hint' && typeof fetchNotificaciones === 'function') {
                fetchNotificaciones();
            }
            if (d.type === 'auditoria') {
                const sid = d.solicitud_id != null ? Number(d.solicitud_id) : null;
                if (sid && window.__CNE_TIMELINE_SOL_ID === sid) {
                    const sol = currentSolicitud && Number(currentSolicitud.solicitud_id) === sid ? currentSolicitud : { solicitud_id: sid };
                    mostrarHistorial(sol);
                }
                const hist = document.getElementById('seccion-historial');
                if (hist && !hist.classList.contains('hidden') && typeof cargarHistorial === 'function') {
                    cargarHistorial();
                }
            }
        });
    </script>
</body>
</html>