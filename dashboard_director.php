<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 4) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$usuario_id = $_SESSION['user_id'];
limpiarSesionesExpiradas();
actualizarSesionUltimaActividad($usuario_id);
$usuario = obtenerUsuario($usuario_id);
require_once __DIR__ . '/includes/cne_admin_view_context.php';
cneAplicarContextoAdminView($usuario);
$coordinacion_nombre = $usuario['coordinacion_nombre'] ?? ($_SESSION['coordinacion_nombre'] ?? null);
$coordinacion_id = $usuario['coordinacion_id'] ?? ($_SESSION['coordinacion_id'] ?? null);
$CNE_RT = ['dashboard' => 'director', 'coord' => (int) ($coordinacion_id ?? 0)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/includes/head_viewport.php'; ?>
    <title>Sistema CNE - Dashboard Director</title>
    <link rel="icon" href="recursos/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <?php require __DIR__ . '/includes/realtime_head.php'; ?>
    <?php require __DIR__ . '/includes/cne_ciudadano_mayus.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; }
        body { font-size: 14px; line-height: 1.5; overflow-x: hidden; min-height: 100vh; }
        .section.hidden { display: none; }
        .section.block { display: block; }
        .menu-item.active { border-left-color: #3b82f6; background-color: rgba(59,130,246,0.12); }
        .sidebar { box-shadow: 2px 0 10px rgba(0,0,0,0.1); transition: transform 0.3s ease; width: 260px; position: fixed; height: 100vh; top: 0; left: 0; z-index: 40; overflow-y: auto; }
        .sidebar.mobile-hidden { transform: translateX(-100%); }
        .sidebar.mobile-visible { transform: translateX(0); }
        .menu-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:30; opacity:0; visibility:hidden; transition: opacity 0.3s; }
        .menu-overlay.active { opacity:1; visibility:visible; }
        .layout-shell { width: 100%; max-width: 100%; min-width: 0; overflow-x: hidden; }
        .main-content { width: 100%; max-width: 100%; min-width: 0; transition: margin-left 0.3s ease; padding: 0; display: flex; flex-direction: column; }
        .sidebar.mobile-visible + .main-content { margin-left: 260px; width: calc(100% - 260px); max-width: 100%; }
        @media (min-width: 1024px) {
            .sidebar { transform: translateX(0) !important; }
            .main-content { margin-left: 260px; width: calc(100% - 260px); max-width: 100%; }
            .menu-overlay { display: none !important; }
            .menu-btn, .menu-close-btn { display: none !important; }
        }
        @media (max-width: 1023px) {
            .main-content { width: 100% !important; margin-left: 0 !important; max-width: 100% !important; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 280px; }
            .menu-btn { width: 44px; height: 44px; display: flex !important; align-items: center; justify-content: center; background: #3b82f6; color: white; border-radius: 10px; margin-right: 12px; }
        }
        .dashboard-powerbi { height: calc(100vh - 64px); overflow: hidden; display: flex; flex-direction: column; }
        .kpi-card { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; border-radius: 12px; padding: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .logo-container { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; }
        .status-badge { padding: 6px 10px; border-radius: 8px; font-weight: 600; font-size: 12px; display: inline-block; }
        .status-pendiente { background:#fff7ed; color:#c2410c; border:1px solid #fdba74; }
        .status-proceso { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; }
        .status-completado { background:#ecfdf5; color:#065f46; border:1px solid #6ee7b7; }
        .status-redirigido { background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe; }
        .status-vencido { background:#e9ecef; color:#343a40; border:1px solid #6c757d; }
        .status-activo { background:#ecfdf5; color:#059669; }
        .status-inactivo { background:#f3f4f6; color:#6b7280; }
        .status-suspendido { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
        /* Buscador director: redirigido en azul corporativo */
        .status-dir-redirigida { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
        .modal-seg-director-open { display: flex !important; }
        .timeline-dir { position: relative; margin-left: 8px; }
        .timeline-dir-item { position: relative; padding-left: 1.75rem; padding-bottom: 1.35rem; border-left: 2px solid #e2e8f0; }
        .timeline-dir-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-dir-dot { position: absolute; left: -7px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #3b82f6; border: 2px solid #fff; box-shadow: 0 0 0 2px #bfdbfe; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; font-weight:600; }
        .btn-primary { background:#3b82f6; color:#fff; }
        .btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
        .btn-secondary:hover { background:#e5e7eb; }
        .table-responsive { overflow-x: auto; }
        .custom-scrollbar::-webkit-scrollbar { height: 8px; width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .reporte-grid { border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: #fff; }
        .reporte-estado-badge { padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 11px; display: inline-block; white-space: nowrap; }
        .reporte-estado-pendiente { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
        .reporte-estado-proceso { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
        .reporte-estado-completado { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
        .reporte-estado-vencido { background: #e9ecef; color: #343a40; border: 1px solid #6c757d; }
        .reporte-estado-redirigido { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
        .reporte-estado-default { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
        .metricas-powerbi { height: calc(100vh - 120px); overflow: hidden; display: flex; flex-direction: column; }
        .chart-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); padding: 1rem; flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .chart-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-shrink: 0; }
        .chart-card-title { font-weight: 600; color: #334155; font-size: 0.9rem; }
        .kpi-card-exec { border-radius: 12px; padding: 1rem; box-shadow: 0 2px 12px rgba(0,0,0,0.08); flex: 1; min-width: 0; }
        .palette-blue { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: #fff; }
        .palette-slate { background: linear-gradient(135deg, #475569 0%, #64748b 100%); color: #fff; }
        .palette-success { background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #fff; }
        .palette-alert { background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%); color: #fff; }
        .status-dot { height: 8px; width: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .dot-activo { background-color: #10b981; box-shadow: 0 0 8px #10b981; animation: pulse-green 2s infinite; }
        .dot-inactivo { background-color: #94a3b8; }
        .dot-suspendido { background-color: #ef4444; }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-50">
    <?php if (!empty($_SESSION['is_admin_viewing'])): ?>
    <a href="auth/admin_exit_view.php" class="fixed bottom-4 right-4 z-[100] inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold shadow-lg hover:bg-indigo-700 border border-indigo-400/40 transition-colors" title="Restaurar sesión de administrador">
        <i class="fas fa-arrow-left"></i> Volver a Admin
    </a>
    <?php endif; ?>
    <div class="menu-overlay" id="menu-overlay"></div>
    <div class="flex layout-shell w-full min-h-screen min-w-0">
        <aside class="sidebar bg-gray-800 text-white flex flex-col mobile-hidden" id="sidebar">
            <div class="sidebar-header px-6 py-5 border-b border-gray-700 flex justify-between items-center gap-2">
                <div class="logo-container flex-1 flex justify-center min-w-0">
                    <img src="recursos/Logo.png" alt="Logo CNE" class="logo-img max-w-40 max-h-16 object-contain mx-auto">
                </div>
                <button type="button" class="menu-close-btn text-white text-lg lg:hidden shrink-0 p-2 rounded-lg hover:bg-white/10" id="menu-close-btn" aria-label="Cerrar menú"><i class="fas fa-times"></i></button>
            </div>
            <nav class="menu flex-1 py-4">
                <ul class="list-none">
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all active" data-section="general">
                        <i class="fas fa-chart-line w-5 text-center"></i>
                        <span>General</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="usuarios">
                        <i class="fas fa-users w-5 text-center"></i>
                        <span>Gestión de Usuarios</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="metricas">
                        <i class="fas fa-chart-bar w-5 text-center"></i>
                        <span>Métricas</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="buscar-tramite">
                        <i class="fas fa-search w-5 text-center"></i>
                        <span>Buscar Trámite</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="reportes">
                        <i class="fas fa-file-export w-5 text-center"></i>
                        <span>Reportes</span>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer mt-auto p-4 border-t border-gray-700 text-sm text-gray-400 text-center">
                <p class="mb-1">Sistema de Gestión CNE</p>
                <p class="text-xs">Director v2.0.0</p>
            </div>
        </aside>

        <main class="main-content flex flex-col bg-gray-50 w-full max-w-full min-w-0">
            <header class="header bg-white px-4 md:px-6 py-4 shadow-sm border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <button class="menu-btn bg-blue-500 text-white p-2 rounded-lg mr-2 lg:hidden" id="menu-btn"><i class="fas fa-bars text-base"></i></button>
                        <h1 class="text-lg md:text-xl font-semibold text-gray-800" id="section-title">General</h1>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right hidden sm:block">
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($_SESSION['nombre_completo'] ?? 'Usuario'); ?> | Director</p>
                            <p class="text-xs text-gray-500"><?php 
                                $area_display = $coordinacion_nombre ?: 'Supervisión global';
                                echo htmlspecialchars(str_replace('Atención al Ciudadano', 'Oficina de Atención al Ciudadano', $area_display)); 
                            ?></p>
                        </div>
                        <div class="relative">
                            <button id="user-dropdown-btn" class="rounded-full w-10 h-10 flex items-center justify-center bg-blue-500 text-white"><i class="fas fa-user"></i></button>
                            <div id="dropdown-menu" class="absolute right-0 mt-2 w-40 bg-white rounded-lg shadow-lg border border-gray-200 hidden z-50">
                                <a href="auth/logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt mr-2"></i> Cerrar Sesión</a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Sección General -->
            <section class="section block dashboard-powerbi p-4 md:p-6" id="seccion-general">
                <div class="flex flex-col h-full overflow-hidden">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 flex-shrink-0">
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Total Áreas</p>
                            <p class="text-2xl font-bold" id="kpi-areas">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Usuarios</p>
                            <p class="text-2xl font-bold" id="kpi-usuarios">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Catálogo Trámites</p>
                            <p class="text-2xl font-bold" id="kpi-tramites">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Conectados</p>
                            <p class="text-2xl font-bold" id="kpi-conectados">0</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 flex-1 min-h-0">
                        <div class="bg-white rounded-xl shadow p-4 flex flex-col min-h-0">
                            <h3 class="text-gray-800 font-semibold mb-2">Trámites por Área (7 días)</h3>
                            <div class="flex-1 min-h-[200px]"><canvas id="chart-area"></canvas></div>
                        </div>
                        <div class="bg-white rounded-xl shadow p-4 flex flex-col min-h-0">
                            <h3 class="text-gray-800 font-semibold mb-2">Últimos 5 registros</h3>
                            <div class="flex-1 overflow-auto custom-scrollbar">
                                <table class="cne-tabla-ciudadano-mayus-dir-recientes min-w-full text-sm">
                                    <thead class="bg-gray-50 sticky top-0"><tr><th class="px-3 py-2 text-left text-xs font-medium text-gray-500">N° Seguimiento</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Ciudadano</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Área</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Tipo</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Estado</th><th class="px-3 py-2 text-left text-xs font-medium text-gray-500">Fecha</th></tr></thead>
                                    <tbody id="recientes-tbody" class="divide-y divide-gray-200"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sección Gestión de Usuarios -->
            <section class="section hidden p-4 md:p-6" id="seccion-usuarios">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-white rounded-xl shadow p-6">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4"><i class="fas fa-users text-blue-600 mr-2"></i>Gestión de Usuarios</h2>
                        <div class="mb-4">
                            <input type="text" id="usuarios-buscar" placeholder="Buscar por usuario, rol, área..." class="w-full md:w-80 p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                        </div>
                        <div class="table-responsive overflow-x-auto custom-scrollbar max-h-[60vh] overflow-y-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 sticky top-0"><tr><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rol</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Área</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Última Actividad</th><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado de conexión</th></tr></thead>
                                <tbody id="usuarios-tbody" class="bg-white divide-y divide-gray-200"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sección Métricas (Dashboard Estratégico Power BI) -->
            <section class="section hidden p-4 md:p-6 metricas-powerbi" id="seccion-metricas">
                <div class="flex flex-col h-full overflow-hidden">
                    <!-- Barra de Filtros -->
                    <div class="flex flex-wrap items-end gap-3 mb-3 flex-shrink-0 bg-white rounded-xl shadow-sm p-3 border border-gray-100">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Fecha Desde</label>
                            <input type="text" id="metricas-fecha-desde" placeholder="dd/mm/aaaa" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-36" readonly>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Fecha Hasta</label>
                            <input type="text" id="metricas-fecha-hasta" placeholder="dd/mm/aaaa" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-36" readonly>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Coordinación</label>
                            <select id="metricas-coordinacion" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-44">
                                <option value="">Todas</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Tipo de Trámite</label>
                            <select id="metricas-tramite" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-52">
                                <option value="">Todos</option>
                            </select>
                        </div>
                        <button id="btn-aplicar-metricas" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"><i class="fas fa-sync-alt mr-1"></i> Actualizar</button>
                    </div>
                    <!-- 4 KPI Cards -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3 flex-shrink-0">
                        <div class="kpi-card-exec palette-blue">
                            <p class="text-xs text-blue-100">Total Solicitudes Hoy</p>
                            <p class="text-2xl md:text-3xl font-bold" id="kpi-total-hoy">0</p>
                        </div>
                        <div class="kpi-card-exec palette-slate">
                            <p class="text-xs text-slate-200">Trámites Pendientes</p>
                            <p class="text-2xl md:text-3xl font-bold" id="kpi-pendientes-m">0</p>
                        </div>
                        <div class="kpi-card-exec palette-success">
                            <p class="text-xs text-emerald-100">% Eficiencia Global</p>
                            <p class="text-2xl md:text-3xl font-bold" id="kpi-eficiencia">0%</p>
                        </div>
                        <div class="kpi-card-exec palette-alert">
                            <p class="text-xs text-amber-100">Tiempo Prom. Respuesta</p>
                            <p class="text-2xl md:text-3xl font-bold" id="kpi-tiempo">0 días</p>
                        </div>
                    </div>
                    <!-- Grid de Gráficos ORE Global -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 flex-1 min-h-0">
                        <div class="chart-card lg:col-span-1">
                            <div class="chart-card-header">
                                <span class="chart-card-title"><i class="fas fa-chart-bar text-blue-600 mr-1"></i> Por Coordinación</span>
                                <div class="flex gap-1">
                                    <button class="btn-export-chart text-gray-500 hover:text-blue-600 text-xs px-2 py-1 rounded" data-chart="bar" data-format="excel"><i class="fas fa-file-excel"></i></button>
                                    <button class="btn-export-chart text-gray-500 hover:text-red-600 text-xs px-2 py-1 rounded" data-chart="bar" data-format="pdf"><i class="fas fa-file-pdf"></i></button>
                                </div>
                            </div>
                            <div class="h-64"><canvas id="chart-bar-coord"></canvas></div>
                        </div>
                        <div class="chart-card lg:col-span-1">
                            <div class="chart-card-header">
                                <span class="chart-card-title"><i class="fas fa-chart-pie text-blue-600 mr-1"></i> Por Estado</span>
                                <div class="flex gap-1">
                                    <button class="btn-export-chart text-gray-500 hover:text-blue-600 text-xs px-2 py-1 rounded" data-chart="estados" data-format="excel"><i class="fas fa-file-excel"></i></button>
                                    <button class="btn-export-chart text-gray-500 hover:text-red-600 text-xs px-2 py-1 rounded" data-chart="estados" data-format="pdf"><i class="fas fa-file-pdf"></i></button>
                                </div>
                            </div>
                            <div class="h-64"><canvas id="chart-estados"></canvas></div>
                        </div>
                        <div class="chart-card lg:col-span-1">
                            <div class="chart-card-header">
                                <span class="chart-card-title"><i class="fas fa-users text-blue-600 mr-1"></i> Rendimiento por Usuario (Top 10)</span>
                                <div class="flex gap-1">
                                    <button class="btn-export-chart text-gray-500 hover:text-blue-600 text-xs px-2 py-1 rounded" data-chart="usuarios" data-format="excel"><i class="fas fa-file-excel"></i></button>
                                    <button class="btn-export-chart text-gray-500 hover:text-red-600 text-xs px-2 py-1 rounded" data-chart="usuarios" data-format="pdf"><i class="fas fa-file-pdf"></i></button>
                                </div>
                            </div>
                            <div class="h-64"><canvas id="chart-rendimiento-usuarios"></canvas></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sección Buscar Trámite (supervisión) -->
            <section class="section hidden p-4 md:p-6" id="seccion-buscar-tramite">
                <div class="max-w-7xl mx-auto">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 md:p-8 mb-6">
                        <h2 class="text-gray-800 text-lg font-semibold mb-1 flex items-center gap-2">
                            <i class="fas fa-search text-blue-600"></i>
                            Buscar Trámite
                        </h2>
                        <p class="text-sm text-gray-600 mb-6">Supervisión global: filtre por número de seguimiento o cédula del ciudadano. Revise el historial y abra el seguimiento detallado desde auditoría y observaciones.</p>
                        <label for="director-buscar-tramite-input" class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Seguimiento o cédula</label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="text" id="director-buscar-tramite-input" autocomplete="off"
                                class="flex-1 p-3 border border-gray-200 rounded-lg bg-gray-50 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                                placeholder="Buscar por nro. seguimiento o cédula...">
                            <button type="button" id="btn-director-buscar-tramite" class="btn btn-primary justify-center shadow-md">
                                <i class="fas fa-search"></i> Buscar
                            </button>
                        </div>
                    </div>
                    <div id="director-buscar-resultado" class="hidden">
                        <p id="director-buscar-resumen" class="text-sm text-gray-600 mb-3 hidden"></p>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="table-responsive overflow-x-auto custom-scrollbar">
                                <table class="cne-tabla-ciudadano-mayus-dir-buscar min-w-full text-sm divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Seguimiento</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ciudadano</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Género</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Coordinación</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo de trámite</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registrado por</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Detalle</th>
                                        </tr>
                                    </thead>
                                    <tbody id="director-buscar-tbody" class="bg-white divide-y divide-gray-200"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div id="director-buscar-vacio" class="hidden text-center py-12 text-gray-500">
                        <i class="fas fa-search text-gray-300 text-4xl mb-3"></i>
                        <p>Ingrese un criterio y pulse Buscar</p>
                    </div>
                    <div id="director-buscar-mensaje" class="hidden rounded-xl border border-amber-100 bg-amber-50 px-6 py-8 text-center text-gray-800"></div>
                </div>
            </section>

            <!-- Sección Reportes (5 Grids) -->
            <section class="section hidden p-4 md:p-6" id="seccion-reportes">
                <div class="max-w-7xl mx-auto">
                    <!-- Filtros Globales -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Vista de Reporte</label>
                                <select id="reporte-vista" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none">
                                    <option value="atencion">Oficina de Atención al Ciudadano</option>
                                    <option value="funcionario" selected>Gestión por Funcionario</option>
                                </select>
                            </div>
                            <div>
                                <label id="reporte-filtro-label" class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Área</label>
                                <select id="reporte-area" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none"></select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Desde</label>
                                <input type="date" id="reporte-fecha-desde" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Hasta</label>
                                <input type="date" id="reporte-fecha-hasta" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none">
                            </div>
                            <button id="btn-cargar-reportes" class="w-full py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold flex items-center justify-center gap-2 transition-all shadow-md">
                                <i class="fas fa-sync-alt"></i> Cargar Datos
                            </button>
                        </div>
                    </div>

                    <!-- Grids de Reportes -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <!-- Card 1: Trámites -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center text-blue-600 flex-shrink-0">
                                        <i class="fas fa-file-lines text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-gray-800">Trámites</h3>
                                        <p class="text-xs text-gray-500 leading-relaxed">Exportar información detallada de los trámites realizados.</p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-tramites" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400 italic">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                        <span id="status-tramites">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="exportarReporteDirector('tramites')" class="w-full py-2.5 bg-blue-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-blue-700 transition-all">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>

                        <!-- Card 2: Solicitudes -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600 flex-shrink-0">
                                        <i class="fas fa-clipboard-list text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-gray-800">Solicitudes</h3>
                                        <p class="text-xs text-gray-500 leading-relaxed">Con la vista «Oficina de Atención al Ciudadano» incluye el mostrador OAC y los trámites inmediatos registrados desde esa coordinación; columna «Registro» distingue flujo normal e inmediato.</p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-solicitudes" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400 italic">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                        <span id="status-solicitudes">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="exportarReporteDirector('solicitudes')" class="w-full py-2.5 bg-emerald-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-emerald-700 transition-all">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>

                        <!-- Card 3: Métricas -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600 flex-shrink-0">
                                        <i class="fas fa-chart-pie text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-gray-800">Métricas</h3>
                                        <p class="text-xs text-gray-500 leading-relaxed">Estadísticas de rendimiento y eficiencia por coordinación.</p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-metricas" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-purple-500 outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400 italic">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                        <span id="status-metricas">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="exportarReporteDirector('metricas')" class="w-full py-2.5 bg-purple-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-purple-700 transition-all">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>

                        <!-- Card 4: Usuarios -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-10 h-10 rounded-lg bg-teal-50 flex items-center justify-center text-teal-600 flex-shrink-0">
                                        <i class="fas fa-users-gear text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-gray-800">Usuarios</h3>
                                        <p class="text-xs text-gray-500 leading-relaxed">Reporte de actividad y estados de los usuarios del sistema.</p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-usuarios" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-teal-500 outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400 italic">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                        <span id="status-usuarios">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="exportarReporteDirector('usuarios')" class="w-full py-2.5 bg-emerald-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-emerald-700 transition-all">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>

                        <!-- Card 5: Auditoría -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 flex-shrink-0">
                                        <i class="fas fa-history text-lg"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-gray-800">Auditoría</h3>
                                        <p class="text-xs text-gray-500 leading-relaxed">Registro histórico de acciones y movimientos en el sistema.</p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-auditoria" class="w-full p-2.5 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-slate-500 outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400 italic">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                        <span id="status-auditoria">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="exportarReporteDirector('auditoria')" class="w-full py-2.5 bg-slate-700 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-slate-800 transition-all">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Invisible tables for export logic compatibility -->
                    <div class="hidden">
                        <table id="grid-tramites"><thead></thead><tbody></tbody></table>
                        <table id="grid-solicitudes"><thead></thead><tbody></tbody></table>
                        <table id="grid-metricas"><thead></thead><tbody></tbody></table>
                        <table id="grid-usuarios"><thead></thead><tbody></tbody></table>
                        <table id="grid-auditoria"><thead></thead><tbody></tbody></table>
                    </div>
                </div>
            </section>
        </main>

        <!-- Modal seguimiento (auditoría + observaciones) -->
        <div id="modal-seguimiento-director" class="fixed inset-0 z-[100] hidden items-center justify-center p-4" aria-modal="true" role="dialog" aria-labelledby="modal-seg-titulo">
            <div class="absolute inset-0 bg-slate-900/55" data-close-seg-modal></div>
            <div class="relative bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[88vh] flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex justify-between items-center px-5 py-4 bg-gradient-to-r from-blue-900 via-blue-800 to-blue-600 text-white shrink-0">
                    <h3 id="modal-seg-titulo" class="font-semibold text-base md:text-lg pr-4">
                        <i class="fas fa-route mr-2 opacity-90"></i>
                        Seguimiento — <span id="modal-seg-numero" class="font-mono font-bold"></span>
                    </h3>
                    <button type="button" class="p-2 rounded-lg hover:bg-white/15 transition shrink-0" data-close-seg-modal aria-label="Cerrar"><i class="fas fa-times text-lg"></i></button>
                </div>
                <div class="overflow-y-auto custom-scrollbar p-5 md:p-6 flex-1 bg-gray-50/80" id="modal-seg-timeline-content"></div>
            </div>
        </div>
    </div>

    <script>
        const estLabels = { pendiente: 'Pendiente', en_revision: 'En Proceso', completada: 'Completada', redirigida: 'Redirigida', aprobada: 'En Proceso', rechazada: 'VENCIDO', vencida: 'VENCIDO' };
        const estClass = { pendiente: 'status-pendiente', en_revision: 'status-proceso', completada: 'status-completado', redirigida: 'status-redirigido', aprobada: 'status-proceso', rechazada: 'status-vencido', vencida: 'status-vencido' };

        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.getElementById('menu-btn');
        const menuClose = document.getElementById('menu-close-btn');
        const overlay = document.getElementById('menu-overlay');
        menuBtn?.addEventListener('click', () => { sidebar.classList.remove('mobile-hidden'); sidebar.classList.add('mobile-visible'); overlay.classList.add('active'); });
        menuClose?.addEventListener('click', () => { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay.classList.remove('active'); });
        overlay?.addEventListener('click', () => { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay.classList.remove('active'); });

        const sections = { general: document.getElementById('seccion-general'), usuarios: document.getElementById('seccion-usuarios'), metricas: document.getElementById('seccion-metricas'), 'buscar-tramite': document.getElementById('seccion-buscar-tramite'), reportes: document.getElementById('seccion-reportes') };
        const titles = { general: 'General', usuarios: 'Gestión de Usuarios', metricas: 'Métricas', 'buscar-tramite': 'Buscar Trámite', reportes: 'Reportes' };

        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const s = this.dataset.section;
                document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                Object.values(sections).forEach(sec => { sec.classList.add('hidden'); sec.classList.remove('block'); });
                if (sections[s]) { sections[s].classList.remove('hidden'); sections[s].classList.add('block'); }
                document.getElementById('section-title').textContent = titles[s] || s;
                if (s === 'general') cargarGeneral();
                else if (s === 'usuarios') cargarUsuarios();
                else if (s === 'metricas') setTimeout(cargarMetricas, 50);
                else if (s === 'buscar-tramite') inicializarVistaBuscarTramiteDirector();
                else if (s === 'reportes') rellenarFiltroReporte().then(() => cargarReportes());
                if (window.innerWidth < 1024) { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay.classList.remove('active'); }
            });
        });

        document.getElementById('user-dropdown-btn')?.addEventListener('click', (e) => { e.stopPropagation(); document.getElementById('dropdown-menu').classList.toggle('hidden'); });
        document.addEventListener('click', () => document.getElementById('dropdown-menu').classList.add('hidden'));

        let chartArea = null;
        let chartInstances = {};
        let flatpickrMetricas = null;
        const PALETTE = { blue: '#1e40af', blueLight: '#3b82f6', slate: '#475569', success: '#10b981', alert: '#f59e0b', amber: '#f59e0b', violet: '#8b5cf6' };

        function cargarGeneral() {
            Promise.all([
                fetch('ajax/director_get_kpis.php').then(r => r.json()),
                fetch('ajax/director_get_chart_area.php').then(r => r.json()),
                fetch('ajax/director_get_recientes.php').then(r => r.json())
            ]).then(([kpis, chart, recientes]) => {
                if (kpis.success) {
                    document.getElementById('kpi-areas').textContent = kpis.total_areas ?? 0;
                    document.getElementById('kpi-usuarios').textContent = kpis.total_usuarios ?? 0;
                    document.getElementById('kpi-tramites').textContent = kpis.total_tramites ?? 0;
                    document.getElementById('kpi-conectados').textContent = kpis.total_conectados ?? 0;
                }
                if (chart.success && chart.labels && chart.data) {
                    if (chartArea) chartArea.destroy();
                    chartArea = new Chart(document.getElementById('chart-area'), {
                        type: 'bar',
                        data: { labels: chart.labels, datasets: [{ label: 'Completadas', data: chart.data, backgroundColor: '#3b82f6' }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                    });
                }
                const list = recientes.success ? (recientes.recientes || []) : [];
                const tbody = document.getElementById('recientes-tbody');
                if (!list.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">Sin registros recientes</td></tr>';
                } else {
                    tbody.innerHTML = list.map(r => {
                        const estado = (r.solicitud_estado || '').toLowerCase().replace(/ /g, '_');
                        return `<tr class="hover:bg-gray-50"><td class="px-3 py-2 font-mono text-xs">${r.solicitud_numero || '-'}</td><td class="px-3 py-2">${typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(r.ciudadano_nombre || '-') : (r.ciudadano_nombre || '-')}</td><td class="px-3 py-2">${r.area_nombre || '-'}</td><td class="px-3 py-2">${r.tipo_tramite || '-'}</td><td class="px-3 py-2"><span class="status-badge ${estClass[estado] || 'status-pendiente'}">${estLabels[estado] || (r.solicitud_estado || '-')}</span></td><td class="px-3 py-2">${r.fecha_registro || '-'}</td></tr>`;
                    }).join('');
                }
            });
        }

        function cargarUsuarios() {
            let q = document.getElementById('usuarios-buscar').value.trim();
            fetch('ajax/director_get_usuarios.php?buscar=' + encodeURIComponent(q)).then(r => r.json()).then(d => {
                const list = d.success ? (d.usuarios || []) : [];
                const tbody = document.getElementById('usuarios-tbody');
                if (!list.length) tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Sin usuarios</td></tr>';
                else tbody.innerHTML = list.map(u => {
                    const ec = (u.estado_conexion || '').toString().trim();
                    let badgeClass = 'status-inactivo';
                    let dotClass = 'dot-inactivo';
                    let label = 'Desconectado';
                    if (ec === 'Suspendido') {
                        badgeClass = 'status-suspendido';
                        dotClass = 'dot-suspendido';
                        label = 'Suspendido';
                    } else if (ec === 'En Línea') {
                        badgeClass = 'status-activo';
                        dotClass = 'dot-activo';
                        label = 'En Línea';
                    } else {
                        badgeClass = 'status-inactivo';
                        dotClass = 'dot-inactivo';
                        label = 'Desconectado';
                    }
                    return `<tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">${u.nombre_completo || '-'}</td>
                        <td class="px-4 py-2">${u.rol_nombre || '-'}</td>
                        <td class="px-4 py-2">${u.area_nombre || '-'}</td>
                        <td class="px-4 py-2">${u.ultima_actividad || '-'}</td>
                        <td class="px-4 py-2">
                            <span class="status-badge ${badgeClass} flex items-center w-fit">
                                <span class="status-dot ${dotClass}"></span>
                                ${label}
                            </span>
                        </td>
                    </tr>`;
                }).join('');
            });
        }

        document.getElementById('usuarios-buscar').addEventListener('input', debounce(cargarUsuarios, 400));

        let flatpickrDesde = null, flatpickrHasta = null;
        function initFlatpickrMetricas() {
            if (flatpickrMetricas) return;
            const inpDesde = document.getElementById('metricas-fecha-desde');
            const inpHasta = document.getElementById('metricas-fecha-hasta');
            if (!inpDesde || !inpHasta) return;
            const hace30 = new Date(); hace30.setDate(hace30.getDate() - 30);
            const hoy = new Date();
            const opts = { locale: (typeof flatpickr !== 'undefined' && flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default', dateFormat: 'Y-m-d' };
            flatpickrDesde = flatpickr(inpDesde, { ...opts, defaultDate: hace30, onChange: () => actualizarGraficos() });
            flatpickrHasta = flatpickr(inpHasta, { ...opts, defaultDate: hoy, onChange: () => actualizarGraficos() });
            flatpickrMetricas = true;
        }

        function actualizarGraficos() { cargarMetricasDashboard(); }

        function cargarTramitesPorCoordinacion() {
            const coord = document.getElementById('metricas-coordinacion')?.value || '';
            const tramSelect = document.getElementById('metricas-tramite');
            if (!tramSelect) return Promise.resolve();
            tramSelect.innerHTML = '<option value="">Todos</option>';
            return fetch('ajax/director_tramites_by_coordinacion.php?coordinacion_id=' + encodeURIComponent(coord))
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.tramites) d.tramites.forEach(t => { tramSelect.add(new Option(t.tramite_nombre, t.tramite_id)); });
                })
                .catch(() => {});
        }

        function cargarMetricasDashboard() {
            const fd = document.getElementById('metricas-fecha-desde')?.value || '';
            const fh = document.getElementById('metricas-fecha-hasta')?.value || '';
            let fechaDesde = fd, fechaHasta = fh;
            if (!fd || !fh) {
                const d = new Date();
                d.setDate(d.getDate() - 30);
                fechaDesde = d.toISOString().slice(0, 10);
                fechaHasta = new Date().toISOString().slice(0, 10);
            }
            const coord = document.getElementById('metricas-coordinacion')?.value || '';
            const tramite = document.getElementById('metricas-tramite')?.value || '';
            const p = new URLSearchParams({ fecha_desde: fechaDesde, fecha_hasta: fechaHasta, coordinacion_id: coord, tramite_id: tramite });
            fetch('ajax/director_metricas_dashboard.php?' + p)
                .then(r => r.json())
                .then(d => {
                    console.log('[Director Métricas] Respuesta backend:', d);
                    if (!d.success) { console.warn('[Director Métricas] Error:', d.message); return; }
                    const k = d.kpis || {};
                    document.getElementById('kpi-total-hoy').textContent = k.total_hoy ?? 0;
                    document.getElementById('kpi-pendientes-m').textContent = k.pendientes ?? 0;
                    document.getElementById('kpi-eficiencia').textContent = (k.eficiencia ?? 0) + '%';
                    document.getElementById('kpi-tiempo').textContent = (k.tiempo_promedio ?? 0) + ' días';
                    const coordSelect = document.getElementById('metricas-coordinacion');
                    if (d.coordinaciones && coordSelect && coordSelect.options.length <= 1) {
                        d.coordinaciones.forEach(c => { coordSelect.add(new Option(c.coordinacion_nombre, c.coordinacion_id)); });
                    }
                    const bc = d.bar_coordinaciones || { labels: [], data: [] };
                    if (chartInstances['barCoord']) { chartInstances['barCoord'].destroy(); chartInstances['barCoord'] = null; }
                    const ctxBar = document.getElementById('chart-bar-coord');
                    if (ctxBar) {
                        chartInstances['barCoord'] = new Chart(ctxBar, {
                            type: 'bar',
                            data: {
                                labels: bc.labels,
                                datasets: [{ label: 'Procesadas', data: bc.data, backgroundColor: PALETTE.blueLight }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { y: { grid: { color: '#e2e8f0' }, ticks: { precision: 0 } } }
                            }
                        });
                    }
                    window._metricasBarData = { headers: ['Coordinación','Procesadas'], rows: bc.labels.map((l,i)=>[l, bc.data[i] ?? 0]) };
                    const ce = d.chart_estados || { labels: [], data: [], colors: [] };
                    const coloresEstados = ce.colors && ce.colors.length ? ce.colors : ['#f59e0b','#3b82f6','#10b981','#8b5cf6','#f97316','#06b6d4','#14b8a6'];
                    if (chartInstances['estados']) { chartInstances['estados'].destroy(); chartInstances['estados'] = null; }
                    const ctxEst = document.getElementById('chart-estados');
                    if (ctxEst) {
                        chartInstances['estados'] = new Chart(ctxEst, {
                            type: 'doughnut',
                            data: {
                                labels: ce.labels,
                                datasets: [{ data: ce.data, backgroundColor: coloresEstados }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { position: 'bottom' } }
                            }
                        });
                    }
                    window._metricasEstadosData = { headers: ['Estado','Cantidad'], rows: (ce.labels || []).map((l,i)=>[l, ce.data?.[i] ?? 0]) };
                    const ru = d.chart_rendimiento_usuarios || { labels: [], data: [] };
                    if (chartInstances['rendimientoUsuarios']) { chartInstances['rendimientoUsuarios'].destroy(); chartInstances['rendimientoUsuarios'] = null; }
                    const ctxUsuarios = document.getElementById('chart-rendimiento-usuarios');
                    if (ctxUsuarios) {
                        chartInstances['rendimientoUsuarios'] = new Chart(ctxUsuarios, {
                            type: 'bar',
                            data: {
                                labels: ru.labels,
                                datasets: [{ label: 'Trámites', data: ru.data, backgroundColor: PALETTE.blueLight }]
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { x: { grid: { color: '#e2e8f0' }, ticks: { precision: 0 } } }
                            }
                        });
                    }
                    window._metricasUsuariosData = { headers: ['Usuario','Trámites'], rows: (ru.labels || []).map((l,i)=>[l, ru.data[i] ?? 0]) };
                })
                .catch(err => console.error('[Director Métricas] Error fetch:', err));
        }

        function cargarMetricas() {
            initFlatpickrMetricas();
            cargarTramitesPorCoordinacion();
            cargarMetricasDashboard();
        }

        document.getElementById('btn-aplicar-metricas')?.addEventListener('click', actualizarGraficos);
        document.getElementById('metricas-coordinacion')?.addEventListener('change', function() {
            const tramSelect = document.getElementById('metricas-tramite');
            tramSelect.value = '';
            cargarTramitesPorCoordinacion().then(() => actualizarGraficos());
        });
        document.getElementById('metricas-tramite')?.addEventListener('change', actualizarGraficos);
        document.querySelectorAll('.btn-export-chart').forEach(btn => {
            btn.addEventListener('click', function() {
                const chart = this.dataset.chart;
                const format = this.dataset.format;
                let data = null;
                if (chart === 'bar') data = window._metricasBarData;
                else if (chart === 'estados') data = window._metricasEstadosData;
                else if (chart === 'usuarios') data = window._metricasUsuariosData;
                if (!data || !data.rows) { alert('Cargue las métricas primero.'); return; }
                if (format === 'excel') {
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet([data.headers, ...data.rows]);
                    XLSX.utils.book_append_sheet(wb, ws, chart);
                    XLSX.writeFile(wb, 'Metrica_' + chart + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
                } else {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF();
                    doc.text('CNE - Métricas Director: ' + chart, 14, 15);
                    doc.text('Fecha: ' + new Date().toLocaleString('es-VE'), 14, 22);
                    doc.autoTable({ head: [data.headers], body: data.rows, startY: 28, theme: 'grid', styles: { fontSize: 8 }, headStyles: { fillColor: [37, 99, 235] } });
                    doc.save('Metrica_' + chart + '_' + new Date().toISOString().slice(0,10) + '.pdf');
                }
            });
        });

        const gridIds = { tramites: 'grid-tramites', solicitudes: 'grid-solicitudes', metricas: 'grid-metricas', usuarios: 'grid-usuarios', auditoria: 'grid-auditoria' };

        function rellenarFiltroReporte() {
            return fetch('ajax/director_reporte_opciones.php').then(r => r.json()).then(d => {
                if (!d.success) return;
                const vista = document.getElementById('reporte-vista').value;
                const sel = document.getElementById('reporte-area');
                const lbl = document.getElementById('reporte-filtro-label');
                sel.innerHTML = '';
                if (vista === 'atencion') {
                    if (lbl) lbl.textContent = 'Usuario';
                    sel.add(new Option('Todos los usuarios', ''));
                    (d.usuarios_atencion || []).forEach(u => {
                        const nombre = (u.nombre_completo || '').trim() || (u.user_identificacion || '');
                        sel.add(new Option(nombre, u.user_identificacion || ''));
                    });
                } else {
                    if (lbl) lbl.textContent = 'Área';
                    sel.add(new Option('Todas las áreas', ''));
                    (d.areas || []).forEach(a => {
                        sel.add(new Option(a.coordinacion_nombre, String(a.coordinacion_id)));
                    });
                }
            });
        }

        function cargarReportes() {
            const vista = document.getElementById('reporte-vista').value;
            const filtroVal = document.getElementById('reporte-area').value;
            const fd = document.getElementById('reporte-fecha-desde').value;
            const fh = document.getElementById('reporte-fecha-hasta').value;
            const tipos = ['tramites','solicitudes','metricas','usuarios','auditoria'];
            
            const btn = document.getElementById('btn-cargar-reportes');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Cargando...';

            tipos.forEach(tipo => {
                const statusEl = document.getElementById('status-' + tipo);
                if (statusEl) {
                    statusEl.textContent = 'Actualizando...';
                    statusEl.parentElement.querySelector('span:first-child').className = 'w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse';
                }

                const p = new URLSearchParams({ tipo, vista, fecha_desde: fd, fecha_hasta: fh });
                if (vista === 'atencion') {
                    if (filtroVal) p.set('usuario_id', filtroVal);
                } else if (filtroVal) {
                    p.set('area_id', filtroVal);
                }
                fetch('ajax/director_reporte_data.php?' + p.toString()).then(r => r.json()).then(d => {
                    const tbl = document.getElementById(gridIds[tipo]);
                    if (!d.success) {
                        if (statusEl) {
                            statusEl.textContent = 'Error al cargar';
                            statusEl.parentElement.querySelector('span:first-child').className = 'w-1.5 h-1.5 rounded-full bg-red-500';
                        }
                        return;
                    }
                    const h = d.headers || [];
                    const rows = d.rows || [];
                    
                    if (statusEl) {
                        statusEl.textContent = `${rows.length} registros encontrados`;
                        statusEl.parentElement.querySelector('span:first-child').className = 'w-1.5 h-1.5 rounded-full bg-emerald-500';
                    }

                    tbl.querySelector('thead').innerHTML = '<tr>' + h.map(x => '<th>' + escapeHtmlDir(x) + '</th>').join('') + '</tr>';
                    tbl.querySelector('tbody').innerHTML = rows.map(function(r) {
                        const arr = Array.isArray(r) ? r : Object.values(r);
                        const idxEst = tipo === 'solicitudes' ? indiceColumnaReporteDirector(h, 'estado') : -1;
                        return '<tr>' + arr.map(function(c, i) {
                            let val = c;
                            if (val === null || val === undefined) val = '-';
                            if (tipo === 'solicitudes' && i === idxEst && idxEst >= 0) {
                                return '<td>' + htmlBadgeEstadoReporteDirector(val) + '</td>';
                            }
                            return '<td>' + escapeHtmlDir(val) + '</td>';
                        }).join('') + '</tr>';
                    }).join('');
                    const subRep = (d.subtitulo != null && String(d.subtitulo).trim() !== '') ? String(d.subtitulo).trim() : 'Global';
                    tbl.dataset.reporteData = JSON.stringify({ tipo, headers: h, rows: rows.map(r => Array.isArray(r) ? r : Object.values(r)), subtitulo: subRep });
                    
                    if (tipos.every(t => document.getElementById(gridIds[t]).dataset.reporteData)) {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                });
            });
        }

        window.exportarReporteDirector = function(tipo) {
            const data = getReporteData(tipo);
            if (!data || !data.rows) { alert('Primero debe cargar los datos del período.'); return; }
            
            // Preparar filas con el mismo formato que para PDF
            const formattedRows = prepararFilasPdfReporte(data.headers, data.rows);
            const lineaTitulo = tituloLineaReporteDirector(data);
            const nombreHoja = subtituloReporteDirectorDesdeData(data).replace(/[:\\/?*[\]]/g, '').substring(0, 31) || 'Reporte';

            const formato = document.getElementById('formato-' + tipo).value;
            if (formato === 'xlsx') {
                const wb = XLSX.utils.book_new();
                const pref = filasPrefijoTituloReporteDirectorExcel(data.headers, data);
                const ws = XLSX.utils.aoa_to_sheet([...pref, data.headers, ...formattedRows]);
                XLSX.utils.book_append_sheet(wb, ws, nombreHoja);
                XLSX.writeFile(wb, 'Reporte_' + tipo + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
            } else {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();
                doc.setFontSize(14);
                doc.text(lineaTitulo, 14, 15);
                doc.text('Fecha: ' + new Date().toLocaleString('es-VE'), 14, 22);
                doc.autoTable({ head: [data.headers], body: formattedRows, startY: 28, theme: 'grid', styles: { fontSize: 8 }, headStyles: { fillColor: [37, 99, 235] } });
                doc.save('Reporte_' + tipo + '_' + new Date().toISOString().slice(0,10) + '.pdf');
            }
        };

        document.getElementById('btn-cargar-reportes').addEventListener('click', cargarReportes);
        document.getElementById('reporte-vista').addEventListener('change', function() {
            rellenarFiltroReporte().then(() => cargarReportes());
        });
        document.getElementById('reporte-area').addEventListener('change', cargarReportes);
        document.getElementById('reporte-fecha-desde').addEventListener('change', cargarReportes);
        document.getElementById('reporte-fecha-hasta').addEventListener('change', cargarReportes);

        function getReporteData(tipo) {
            const tbl = document.getElementById(gridIds[tipo]);
            return tbl && tbl.dataset.reporteData ? JSON.parse(tbl.dataset.reporteData) : null;
        }

        function subtituloReporteDirectorDesdeData(data) {
            const s = data && data.subtitulo != null ? String(data.subtitulo).trim() : '';
            return s !== '' ? s : 'Global';
        }

        function tituloLineaReporteDirector(data) {
            return 'CNE - Reporte Director: ' + subtituloReporteDirectorDesdeData(data);
        }

        function filasPrefijoTituloReporteDirectorExcel(headers, data) {
            const n = Math.max((headers && headers.length) ? headers.length : 1, 1);
            const pad = () => Array.from({ length: n }, () => '');
            const t = tituloLineaReporteDirector(data);
            const f = 'Fecha: ' + new Date().toLocaleString('es-VE');
            const r1 = pad();
            r1[0] = t;
            const r2 = pad();
            r2[0] = f;
            return [r1, r2, pad()];
        }
        document.querySelectorAll('.btn-export-excel').forEach(btn => {
            btn.addEventListener('click', function() {
                const tipo = this.dataset.tipo;
                const data = getReporteData(tipo);
                if (!data || !data.rows) { alert('Cargue los reportes primero.'); return; }
                const body = prepararFilasPdfReporte(data.headers, data.rows);
                const wb = XLSX.utils.book_new();
                const pref = filasPrefijoTituloReporteDirectorExcel(data.headers, data);
                const ws = XLSX.utils.aoa_to_sheet([...pref, data.headers, ...body]);
                const nombreHoja = subtituloReporteDirectorDesdeData(data).replace(/[:\\/?*[\]]/g, '').substring(0, 31) || 'Reporte';
                XLSX.utils.book_append_sheet(wb, ws, nombreHoja);
                XLSX.writeFile(wb, 'Reporte_' + tipo + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
            });
        });
        function normalizarDescripcionAuditoriaPdf(v) {
            var s = String(v === null || v === undefined ? '' : v);
            s = s.replace(/Tr\?mite/g, 'Trámite').replace(/Coordinaci\?n/g, 'Coordinación').replace(/Atenci\?n/g, 'Atención');
            var canonCreada = 'Solicitud creada por la Oficina de Atención al Ciudadano';
            s = s.replace(/Solicitud creada por el usuario de Oficina de Oficina de Atención al Ciudadano\.?/gi, canonCreada);
            s = s.replace(/Solicitud creada por el usuario de Oficina de Atención al Ciudadano\.?/gi, canonCreada);
            s = s.replace(/Solicitud creada por el usuario de entrada\.?/gi, canonCreada);
            var canonInm = 'Trámite completado inmediatamente por la Oficina de Atención al Ciudadano';
            s = s.replace(/Trámite completado inmediatamente por el usuario de Oficina de Oficina de Atención al Ciudadano\.?/gi, canonInm);
            s = s.replace(/Trámite completado inmediatamente por el usuario de Oficina de Atención al Ciudadano\.?/gi, canonInm);
            s = s.replace(/Trámite completado inmediatamente por el usuario de entrada\.?/gi, canonInm);
            s = s.replace(/El empleado completa el trámite/g, 'El funcionario completa el trámite');
            s = s.replace(/el empleado completa el trámite/g, 'el funcionario completa el trámite');
            s = s.replace(/Trámite iniciado por el empleado/g, 'Trámite iniciado por el funcionario');
            s = s.replace(/Requisitos actualizados por el empleado/g, 'Requisitos actualizados por el funcionario');
            s = s.replace(/\bPor el empleado\b/g, 'Por el funcionario');
            s = s.replace(/\bpor el empleado\b/g, 'por el funcionario');
            s = s.replace(/\bEl empleado\b/g, 'El funcionario');
            s = s.replace(/\bEl Empleado\b/g, 'El funcionario');
            try {
                return s.normalize('NFC');
            } catch (e) {
                return s;
            }
        }

        function prepararFilasPdfReporte(headers, rows) {
            const h = (headers || []).map(function (x) { return String(x); });
            const idxAccion = h.findIndex(function (x) { return x.toLowerCase() === 'acción'; });
            const idxDesc = h.findIndex(function (x) { return x.toLowerCase() === 'descripción'; });
            const idxFechaHora = h.findIndex(function (x) { return x.toLowerCase() === 'fecha/hora' || x.toLowerCase() === 'fecha'; });
            const idxEstadoRep = h.findIndex(function (x) { return x.toLowerCase() === 'estado'; });

            return (rows || []).map(function (row) {
                return row.map(function (cell, i) {
                    var v = (cell === null || cell === undefined) ? '' : String(cell);

                    if (i === idxEstadoRep && idxEstadoRep >= 0) {
                        return normalizarEstadoSolicitudReporteDirectorJs(v);
                    }
                    
                    // Formatear Fecha/Hora: 15/05/2024 14:30 -> 15/05/2024 02:30 pm
                    if (i === idxFechaHora && v.includes('/') && v.includes(':')) {
                        try {
                            const [fecha, horaFull] = v.split(' ');
                            if (fecha && horaFull) {
                                const [h, m] = horaFull.split(':');
                                let hh = parseInt(h);
                                const ampm = hh >= 12 ? 'pm' : 'am';
                                hh = hh % 12;
                                hh = hh ? hh : 12; // el 0 es 12
                                const hhStr = hh < 10 ? '0' + hh : hh;
                                return `${fecha} ${hhStr}:${m} ${ampm}`;
                            }
                        } catch (e) { console.warn('Error formateando fecha:', e); }
                    }

                    if (i === idxAccion) {
                        var acc = v.replace(/_/g, ' ').toUpperCase();
                        return acc.replace(/^EMPLEADO\s+/, 'FUNCIONARIO ');
                    }
                    if (i === idxDesc) {
                        return normalizarDescripcionAuditoriaPdf(v);
                    }
                    try {
                        return v.normalize('NFC');
                    } catch (e2) {
                        return v;
                    }
                });
            });
        }
        document.querySelectorAll('.btn-export-pdf').forEach(btn => {
            btn.addEventListener('click', function() {
                const tipo = this.dataset.tipo;
                const data = getReporteData(tipo);
                if (!data || !data.rows) { alert('Cargue los reportes primero.'); return; }
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF();
                doc.setFontSize(14);
                doc.text(tituloLineaReporteDirector(data), 14, 15);
                doc.setFontSize(10);
                doc.text('Fecha: ' + new Date().toLocaleString('es-VE'), 14, 22);
                const bodyPdf = prepararFilasPdfReporte(data.headers, data.rows);
                doc.autoTable({ head: [data.headers], body: bodyPdf, startY: 28, theme: 'grid', styles: { fontSize: 8 }, headStyles: { fillColor: [37, 99, 235] } });
                doc.save('Reporte_' + tipo + '_' + new Date().toISOString().slice(0,10) + '.pdf');
            });
        });

        function debounce(fn, ms) { let t; return function() { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), ms); }; }

        function escapeHtmlDir(s) {
            if (s == null || s === '') return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function indiceColumnaReporteDirector(headers, nombre) {
            const n = String(nombre || '').toLowerCase();
            const arr = headers || [];
            for (let i = 0; i < arr.length; i++) {
                if (String(arr[i]).toLowerCase() === n) return i;
            }
            return -1;
        }

        function normalizarEstadoSolicitudReporteDirectorJs(v) {
            const raw = String(v === null || v === undefined ? '' : v).trim();
            if (!raw) return '—';
            const low = raw.toLowerCase();
            const map = {
                'pendiente': 'Pendiente',
                'en proceso': 'En Proceso',
                'en_revision': 'En Proceso',
                'en_proceso': 'En Proceso',
                'en revisión': 'En Proceso',
                'en revision': 'En Proceso',
                'aprobada': 'En Proceso',
                'completada': 'Completado',
                'completado': 'Completado',
                'finalizado': 'Completado',
                'finalizada': 'Completado',
                'vencida': 'VENCIDO',
                'vencido': 'VENCIDO',
                'rechazada': 'VENCIDO',
                'redirigida': 'Redirigido',
                'redirigido': 'Redirigido'
            };
            if (map[low]) return map[low];
            if (/^en[\s_-]+revisi[oó]n$/i.test(raw)) return 'En Proceso';
            if (/^en[\s_-]+proceso$/i.test(raw)) return 'En Proceso';
            return raw;
        }

        function claseBadgeEstadoReporteDirector(etiqueta) {
            const t = String(etiqueta || '').toLowerCase();
            if (t === 'pendiente') return 'reporte-estado-pendiente';
            if (t === 'en proceso') return 'reporte-estado-proceso';
            if (t === 'completado') return 'reporte-estado-completado';
            if (t === 'vencido' || t === 'vencida') return 'reporte-estado-vencido';
            if (t === 'redirigido') return 'reporte-estado-redirigido';
            return 'reporte-estado-default';
        }

        function htmlBadgeEstadoReporteDirector(etiqueta) {
            const lab = normalizarEstadoSolicitudReporteDirectorJs(etiqueta);
            const cls = claseBadgeEstadoReporteDirector(lab);
            return '<span class="reporte-estado-badge ' + cls + '">' + escapeHtmlDir(lab) + '</span>';
        }

        function etiquetaEstadoDirector(est) {
            const e = (est || '').toLowerCase();
            if (e === 'completada') return 'Culminado';
            if (e === 'redirigida') return 'Redirigido';
            if (e === 'vencida' || e === 'rechazada') return 'VENCIDO';
            if (e === 'aprobada') return 'En Proceso';
            return estLabels[e] || est || '—';
        }

        function claseEstadoDirector(est) {
            const e = (est || '').toLowerCase();
            if (e === 'completada') return 'status-completado';
            if (e === 'pendiente') return 'status-pendiente';
            if (e === 'redirigida') return 'status-dir-redirigida';
            if (e === 'vencida' || e === 'rechazada') return 'status-vencido';
            if (e === 'aprobada') return 'status-proceso';
            return estClass[e] || 'status-pendiente';
        }

        let buscarTramiteDirectorListeners = false;

        function cerrarModalSeguimientoDirector() {
            window.__CNE_TIMELINE_SOL_ID = null;
            const modal = document.getElementById('modal-seguimiento-director');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function abrirModalSeguimientoDirector(solicitudId) {
            window.__CNE_TIMELINE_SOL_ID = solicitudId != null ? Number(solicitudId) : null;
            const modal = document.getElementById('modal-seguimiento-director');
            const content = document.getElementById('modal-seg-timeline-content');
            const numEl = document.getElementById('modal-seg-numero');
            if (!modal || !content || !numEl) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            numEl.textContent = '…';
            content.innerHTML = '<div class="text-center py-12 text-gray-500"><i class="fas fa-spinner fa-spin text-3xl text-blue-600 mb-3 block"></i>Cargando línea de tiempo…</div>';
            fetch('ajax/director_seguimiento_solicitud.php?solicitud_id=' + encodeURIComponent(solicitudId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = '<div class="rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-6 text-center">' + escapeHtmlDir(data.message || 'No se pudo cargar el seguimiento') + '</div>';
                        return;
                    }
                    numEl.textContent = data.solicitud_numero || ('#' + solicitudId);
                    const evs = data.eventos || [];
                    if (!evs.length) {
                        content.innerHTML = '<div class="text-center py-10 text-gray-500"><i class="fas fa-inbox text-4xl text-gray-300 mb-3 block"></i>No hay registros de auditoría u observaciones para este trámite.</div>';
                        return;
                    }
                    content.innerHTML = '<div class="timeline-dir">' + evs.map(ev => {
                        const acc = escapeHtmlDir(String(ev.accion || '').replace(/_/g, ' '));
                        const desc = escapeHtmlDir(ev.descripcion || '');
                        const fun = escapeHtmlDir(ev.funcionario || '');
                        const fh = escapeHtmlDir(ev.fecha_hora || '');
                        return '<div class="timeline-dir-item"><span class="timeline-dir-dot" aria-hidden="true"></span>' +
                            '<p class="text-xs font-bold text-blue-800 tracking-wide mb-1">' + acc + '</p>' +
                            '<p class="text-sm text-gray-800 leading-relaxed mb-2">' + desc + '</p>' +
                            '<p class="text-xs text-gray-600"><i class="fas fa-user-tie mr-1 text-blue-500"></i>' + fun + '</p>' +
                            '<p class="text-xs text-gray-500 mt-1"><i class="far fa-clock mr-1"></i>' + fh + '</p>' +
                            '</div>';
                    }).join('') + '</div>';
                })
                .catch(() => {
                    content.innerHTML = '<div class="rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-6 text-center">Error de conexión al cargar el seguimiento.</div>';
                });
        }

        function ejecutarBusquedaDirector() {
            const input = document.getElementById('director-buscar-tramite-input');
            const btn = document.getElementById('btn-director-buscar-tramite');
            const term = (input && input.value) ? input.value.trim() : '';
            const boxRes = document.getElementById('director-buscar-resultado');
            const boxVacio = document.getElementById('director-buscar-vacio');
            const boxMsg = document.getElementById('director-buscar-mensaje');
            const tbody = document.getElementById('director-buscar-tbody');
            const resumen = document.getElementById('director-buscar-resumen');
            if (!term) {
                boxRes?.classList.add('hidden');
                boxMsg?.classList.add('hidden');
                boxVacio?.classList.remove('hidden');
                boxVacio && (boxVacio.querySelector('p').textContent = 'Ingrese un número de seguimiento o cédula para buscar');
                return;
            }
            boxVacio?.classList.add('hidden');
            boxMsg?.classList.add('hidden');
            boxRes?.classList.add('hidden');
            const prevBtn = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando…'; }
            const urlBuscarDir = 'ajax/buscar_tramite_director.php?q=' + encodeURIComponent(term);
            fetch(urlBuscarDir)
                .then(function(r) {
                    return r.text().then(function(text) {
                        var data;
                        try {
                            data = JSON.parse(text);
                        } catch (parseErr) {
                            console.error('[Buscar Trámite Director] Respuesta no es JSON válido. HTTP', r.status, text);
                            throw parseErr;
                        }
                        return { httpOk: r.ok, status: r.status, data: data, raw: text };
                    });
                })
                .then(function(res) {
                    if (btn) { btn.disabled = false; btn.innerHTML = prevBtn; }
                    var data = res.data;
                    if (!res.httpOk) {
                        console.error('[Buscar Trámite Director] HTTP', res.status, data);
                    }
                    if (data.success && Array.isArray(data.tramites) && data.tramites.length) {
                        var list = data.tramites;
                        if (resumen) {
                            if (list.length > 1) {
                                resumen.textContent = 'Se encontraron ' + list.length + ' trámites (más reciente primero).';
                                resumen.classList.remove('hidden');
                            } else resumen.classList.add('hidden');
                        }
                        var cneM = (typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto : function (x) { return x == null ? '' : String(x); });
                        tbody.innerHTML = list.map(function(t) {
                            var sid = parseInt(t.solicitud_id, 10);
                            var est = (t.estado || '').toLowerCase();
                            return '<tr class="hover:bg-gray-50">' +
                                '<td class="px-4 py-3 font-mono text-blue-700 font-semibold">' + escapeHtmlDir(t.numero_seguimiento) + '</td>' +
                                '<td class="px-4 py-3"><span class="status-badge ' + claseEstadoDirector(est) + '">' + escapeHtmlDir(etiquetaEstadoDirector(est)) + '</span></td>' +
                                '<td class="px-4 py-3 text-gray-800 max-w-[180px]">' + escapeHtmlDir(cneM(t.ciudadano_nombre)) + '</td>' +
                                '<td class="px-4 py-3 font-mono text-gray-800">' + escapeHtmlDir(cneM(t.ciudadano_identificacion)) + '</td>' +
                                '<td class="px-4 py-3 text-gray-600">' + escapeHtmlDir(cneM(t.ciudadano_genero)) + '</td>' +
                                '<td class="px-4 py-3 text-gray-700 max-w-[160px]">' + escapeHtmlDir(t.area_nombre) + '</td>' +
                                '<td class="px-4 py-3 text-gray-700 max-w-[200px]">' + escapeHtmlDir(t.tipo_tramite_nombre) + '</td>' +
                                '<td class="px-4 py-3 text-gray-700 max-w-[140px]">' + escapeHtmlDir(t.creado_por) + '</td>' +
                                '<td class="px-4 py-3 text-gray-600 whitespace-nowrap">' + escapeHtmlDir(t.fecha_registro) + '</td>' +
                                '<td class="px-4 py-3 text-center">' +
                                '<button type="button" class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition" data-seguimiento-solicitud-id="' + sid + '" title="Ver detalles / seguimiento">' +
                                '<i class="fas fa-eye"></i></button></td></tr>';
                        }).join('');
                        boxRes.classList.remove('hidden');
                    } else {
                        console.log('[Buscar Trámite Director] Sin resultados o error de negocio:', data);
                        if (data.debug_sql) {
                            console.error('[Buscar Trámite Director] Detalle SQL/PDO:', data.debug_sql, data.debug_pdo || '');
                        }
                        var m = escapeHtmlDir(data.message || 'Sin resultados');
                        boxMsg.innerHTML = '<i class="fas fa-folder-open text-amber-500 text-3xl mb-3 block"></i><p class="font-medium">' + m + '</p>';
                        boxMsg.classList.remove('hidden');
                    }
                })
                .catch(function(err) {
                    if (btn) { btn.disabled = false; btn.innerHTML = prevBtn; }
                    console.error('[Buscar Trámite Director] Fallo de red o parseo:', err);
                    boxMsg.innerHTML = '<i class="fas fa-exclamation-circle text-red-500 text-3xl mb-3 block"></i><p class="font-medium">Error al buscar. Revise la consola (F12) para más detalle.</p>';
                    boxMsg.classList.remove('hidden');
                });
        }

        function inicializarVistaBuscarTramiteDirector() {
            const vacio = document.getElementById('director-buscar-vacio');
            const res = document.getElementById('director-buscar-resultado');
            const msg = document.getElementById('director-buscar-mensaje');
            res?.classList.add('hidden');
            msg?.classList.add('hidden');
            msg && (msg.innerHTML = '');
            vacio?.classList.remove('hidden');
            const pv = vacio?.querySelector('p');
            if (pv) pv.textContent = 'Ingrese un criterio y pulse Buscar';
            if (!buscarTramiteDirectorListeners) {
                buscarTramiteDirectorListeners = true;
                document.getElementById('btn-director-buscar-tramite')?.addEventListener('click', ejecutarBusquedaDirector);
                document.getElementById('director-buscar-tramite-input')?.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') ejecutarBusquedaDirector();
                });
                document.querySelectorAll('[data-close-seg-modal]').forEach(function(el) {
                    el.addEventListener('click', cerrarModalSeguimientoDirector);
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') cerrarModalSeguimientoDirector();
                });
                document.getElementById('director-buscar-tbody')?.addEventListener('click', function(e) {
                    const btn = e.target.closest('[data-seguimiento-solicitud-id]');
                    if (!btn) return;
                    const id = parseInt(btn.getAttribute('data-seguimiento-solicitud-id'), 10);
                    if (id) abrirModalSeguimientoDirector(id);
                });
            }
        }

        window.addEventListener('cne:realtime', function(ev) {
            const d = ev.detail && ev.detail.event;
            if (!d || !window.CNE_REALTIME || window.CNE_REALTIME.enabled === false) return;
            if (d.type === 'auditoria') {
                const sid = d.solicitud_id != null ? Number(d.solicitud_id) : null;
                if (sid && window.__CNE_TIMELINE_SOL_ID === sid && typeof abrirModalSeguimientoDirector === 'function') {
                    abrirModalSeguimientoDirector(sid);
                }
                const box = document.getElementById('director-buscar-resultado');
                if (box && !box.classList.contains('hidden') && typeof ejecutarBusquedaDirector === 'function') {
                    ejecutarBusquedaDirector();
                }
            }
        });

        cargarGeneral();

        function refrescarSoloKpiConectados() {
            const activeSection = document.querySelector('.menu-item.active')?.dataset.section;
            if (activeSection !== 'general') return;
            fetch('ajax/director_get_kpis.php')
                .then(r => r.json())
                .then(kpis => {
                    if (!kpis.success) return;
                    const el = document.getElementById('kpi-conectados');
                    if (el) el.textContent = kpis.total_conectados ?? 0;
                })
                .catch(() => {});
        }
        setInterval(refrescarSoloKpiConectados, 30000);
        
        // Actualización periódica cada 60 segundos
        setInterval(() => {
            const activeSection = document.querySelector('.menu-item.active')?.dataset.section;
            if (activeSection === 'general') cargarGeneral();
            else if (activeSection === 'usuarios') cargarUsuarios();
        }, 60000);
    </script>
</body>
</html>
