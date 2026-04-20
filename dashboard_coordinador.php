<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 3) {
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/includes/head_viewport.php'; ?>
    <?php require __DIR__ . '/includes/cne_ciudadano_mayus.php'; ?>
    <title>Sistema CNE - Dashboard Coordinador</title>
    <link rel="icon" href="recursos/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
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
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.4); z-index: 50; }
        .modal.active { display: flex; }
        .modal-content { background: #fff; width: 95%; max-width: 800px; max-height: 90vh; overflow-y: auto; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
        .status-badge { padding: 6px 10px; border-radius: 8px; font-weight: 600; font-size: 12px; display:inline-block; }
        .status-pendiente { background:#fff7ed; color:#c2410c; border:1px solid #fdba74; }
        .status-proceso { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; }
        .status-completado { background:#ecfdf5; color:#065f46; border:1px solid #6ee7b7; }
        .status-cancelado { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; }
        .status-redirigido { background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe; }
        .status-invalidada { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .kpi-card-danger { background: linear-gradient(135deg, #991b1b 0%, #dc2626 100%); color: white; border-radius: 12px; padding: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .status-vencido { background:#e9ecef; color:#343a40; border:1px solid #6c757d; }
        .status-en-caracas { background:#ccfbf1; color:#0d9488; border:1px solid #5eead4; }
        .status-activo { background:#ecfdf5; color:#059669; }
        .status-inactivo { background:#f3f4f6; color:#6b7280; }
        .status-dot { height: 8px; width: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .dot-activo { background-color: #10b981; box-shadow: 0 0 8px #10b981; animation: pulse-green 2s infinite; }
        .dot-inactivo { background-color: #94a3b8; }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; font-weight:600; }
        .btn-primary { background:#3b82f6; color:#fff; }
        .btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
        .btn-secondary:hover { background:#e5e7eb; }
        .table-responsive { overflow-x: auto; }
        .custom-scrollbar::-webkit-scrollbar { height: 8px; width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
                <div class="logo-container flex flex-col items-center justify-center w-full min-w-0">
                    <img src="recursos/Logo.png" alt="Logo CNE" class="logo-img max-w-40 max-h-16 object-contain">
                </div>
                <button type="button" class="menu-close-btn text-white text-lg lg:hidden shrink-0 p-2 rounded-lg hover:bg-white/10" id="menu-close-btn" aria-label="Cerrar menú"><i class="fas fa-times"></i></button>
            </div>
            <nav class="menu flex-1 py-4">
                <ul class="list-none">
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all active" data-section="inicio">
                        <i class="fas fa-chart-line w-5 text-center"></i>
                        <span>Inicio / Métricas</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="tramites">
                        <i class="fas fa-folder-open w-5 text-center"></i>
                        <span>Trámites del Área</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="conexiones">
                        <i class="fas fa-users w-5 text-center"></i>
                        <span>Monitor de Conexiones</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="reportes">
                        <i class="fas fa-file-export w-5 text-center"></i>
                        <span>Reportes</span>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer mt-auto p-4 border-t border-gray-700 text-sm text-gray-400 text-center">
                <p class="mb-1">Sistema de Gestión CNE</p>
                <p class="text-xs">Coordinador v2.0.0</p>
            </div>
        </aside>

        <main class="main-content flex flex-col bg-gray-50 w-full max-w-full min-w-0">
            <header class="header bg-white px-4 md:px-6 py-4 shadow-sm border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <button class="menu-btn bg-blue-500 text-white p-2 rounded-lg mr-2 lg:hidden" id="menu-btn"><i class="fas fa-bars text-base"></i></button>
                        <h1 class="text-lg md:text-xl font-semibold text-gray-800" id="section-title">Inicio / Métricas</h1>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right hidden sm:block">
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars(($_SESSION['nombre_completo'] ?? 'Usuario') . ' | ' . ($coordinacion_nombre ?: 'Sin coordinación')); ?></p>
                            <p class="text-xs text-gray-500">Coordinador</p>
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

            <script>const MI_COORDINACION_ID = <?php echo $coordinacion_id ? (int)$coordinacion_id : 'null'; ?>;</script>

            <!-- A. Inicio / Métricas -->
            <section class="section block dashboard-powerbi p-4 md:p-6" id="seccion-inicio">
                <div class="flex flex-col h-full overflow-hidden">
                    <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-3 mb-4 flex-shrink-0">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Fecha Desde</label>
                            <input type="text" id="filtro-fecha-desde" placeholder="dd/mm/aaaa" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-36" readonly>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Fecha Hasta</label>
                            <input type="text" id="filtro-fecha-hasta" placeholder="dd/mm/aaaa" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-36" readonly>
                        </div>
                        <select id="filtro-funcionario" class="p-2 border border-gray-300 rounded-lg text-sm bg-white">
                            <option value="">Todos los funcionarios</option>
                        </select>
                        <div class="flex items-center gap-0">
                            <select id="filtro-nacionalidad" class="p-2 border border-gray-300 rounded-l-lg text-sm bg-white w-14">
                                <option value="V">V</option>
                                <option value="E">E</option>
                            </select>
                            <input type="text" id="filtro-cedula" placeholder="Cédula del Ciudadano" class="p-2 border border-l-0 border-gray-300 rounded-r-lg text-sm w-28">
                        </div>
                        <select id="filtro-estado" class="p-2 border border-gray-300 rounded-lg text-sm bg-white w-40">
                            <option value="">Todos los estados</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="en_revision">En Proceso</option>
                            <option value="completada">Completada</option>
                            <option value="redirigida">Redirigido</option>
                            <option value="vencida">Vencido</option>
                            <option value="invalidada">Invalidada</option>
                        </select>
                        <button id="btn-aplicar-filtros" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"><i class="fas fa-filter mr-1"></i> Aplicar</button>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-4 flex-shrink-0">
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Total</p>
                            <p class="text-2xl font-bold" id="kpi-total">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Pendientes</p>
                            <p class="text-2xl font-bold" id="kpi-pendientes">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">En Proceso</p>
                            <p class="text-2xl font-bold" id="kpi-en-proceso">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Completados</p>
                            <p class="text-2xl font-bold" id="kpi-completados">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Vencidos</p>
                            <p class="text-2xl font-bold" id="kpi-vencidos">0</p>
                        </div>
                        <div class="kpi-card">
                            <p class="text-xs text-blue-100">Redirigidos</p>
                            <p class="text-2xl font-bold" id="kpi-redirigidos">0</p>
                        </div>
                        <div class="kpi-card-danger">
                            <p class="text-xs text-red-100 flex items-center gap-1"><i class="fas fa-exclamation-triangle"></i> Trámites invalidados</p>
                            <p class="text-2xl font-bold" id="kpi-invalidados">0</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 flex-1 min-h-0">
                        <div class="bg-white rounded-xl shadow p-4 flex flex-col min-h-0">
                            <h3 class="text-gray-800 font-semibold mb-2">Volumen por estado</h3>
                            <div class="flex-1 min-h-[160px]"><canvas id="chart-bar"></canvas></div>
                        </div>
                        <div class="bg-white rounded-xl shadow p-4 flex flex-col min-h-0">
                            <h3 class="text-gray-800 font-semibold mb-2">Distribución por tipo de trámite</h3>
                            <div class="flex-1 min-h-[160px]"><canvas id="chart-pie"></canvas></div>
                        </div>
                        <div class="bg-white rounded-xl shadow p-4 flex flex-col min-h-0">
                            <h3 class="text-gray-800 font-semibold mb-2">Carga de trabajo por funcionario</h3>
                            <div class="flex-1 min-h-[160px]"><canvas id="chart-carga-empleados"></canvas></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- B. Trámites del Área -->
            <section class="section hidden p-4 md:p-6" id="seccion-tramites">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-folder-open text-blue-600"></i>
                                    <span>Trámites del Área</span>
                                </h2>
                                <p class="text-sm text-gray-600">Trámites gestionados en tu coordinación</p>
                            </div>
                            <div class="bg-white px-4 py-3 rounded-lg shadow-sm">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-chart-line text-blue-500"></i>
                                    <div>
                                        <p class="text-xs text-gray-500">Total</p>
                                        <p class="text-lg font-bold text-gray-800" id="contador-tramites">0</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Filtros -->
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow mb-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-hashtag mr-1"></i> N° Seguimiento</label>
                                <input type="text" id="tram-filtro-numero" placeholder="Ej: CNE-0001" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-user-tie mr-1"></i> Funcionario</label>
                                <select id="tram-filtro-funcionario" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500"><option value="">Todos</option></select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-id-card mr-1"></i> Cédula</label>
                                <div class="flex gap-0">
                                    <select id="tram-filtro-nacionalidad" class="w-16 p-3 border border-gray-300 rounded-l-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 bg-white">

                                        <option value="V">V</option>
                                        <option value="E">E</option>
                                    </select>
                                    <input type="text" id="tram-filtro-cedula" placeholder="12345678" class="flex-1 p-3 border border-l-0 border-gray-300 rounded-r-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-flag mr-1"></i> Estado</label>
                                <select id="tram-filtro-estado" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="">Todos</option>
                                    <option value="pendiente">Pendiente</option>
                                    <option value="en_revision">En Proceso</option>
                                    <option value="completada">Completada</option>
                                    <option value="redirigida">Redirigido</option>
                                    <option value="vencida">Vencido</option>
                                    <option value="invalidada">Invalidada</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-calendar mr-1"></i> Fecha desde</label>
                                <input type="date" id="tram-filtro-fecha-desde" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-calendar mr-1"></i> Fecha hasta</label>
                                <input type="date" id="tram-filtro-fecha-hasta" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                        </div>
                        <div class="flex justify-end gap-4">
                            <button id="btn-reset-filtros-tram" class="btn btn-secondary"><i class="fas fa-redo"></i> Limpiar</button>
                            <button id="btn-buscar-tramites" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold"><i class="fas fa-filter mr-1"></i> Aplicar filtros</button>
                        </div>
                    </div>
                    <!-- Tabla -->
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow">
                        <div class="table-responsive overflow-x-auto custom-scrollbar max-h-[60vh] overflow-y-auto">
                            <table class="cne-tabla-tramites-coord min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Funcionario</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ciudadano</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo de Trámite</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Número</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tramites-tbody" class="bg-white divide-y divide-gray-200"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- C. Monitor de Conexiones -->
            <section class="section hidden p-4 md:p-6" id="seccion-conexiones">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-xl shadow p-6 text-center">
                        <p class="text-gray-500 text-sm">Total Usuarios</p>
                        <p class="text-3xl font-bold text-gray-800" id="conn-total">0</p>
                    </div>
                    <div class="bg-white rounded-xl shadow p-6 text-center">
                        <p class="text-gray-500 text-sm">Activos</p>
                        <p class="text-3xl font-bold text-emerald-600" id="conn-activos">0</p>
                    </div>
                    <div class="bg-white rounded-xl shadow p-6 text-center">
                        <p class="text-gray-500 text-sm">Inactivos</p>
                        <p class="text-3xl font-bold text-gray-500" id="conn-inactivos">0</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl shadow overflow-hidden">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-100"><tr><th class="px-4 py-3 text-left">Usuario</th><th class="px-4 py-3 text-left">Rol</th><th class="px-4 py-3 text-left">Última Actividad</th><th class="px-4 py-3 text-left">Estado</th></tr></thead>
                            <tbody id="conexiones-tbody" class="divide-y divide-gray-200"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- D. Reportes -->
            <section class="section hidden p-4 md:p-6" id="seccion-reportes">
                <div class="max-w-7xl mx-auto">
                    <!-- Filtro Global de Período -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
                        <div class="flex flex-col md:flex-row md:items-end gap-4">
                            <div class="flex-1">
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Período</label>
                                <select id="reporte-periodo" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-50 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all outline-none">
                                    <option value="mes">Mes actual</option>
                                    <option value="semana">Semana actual</option>
                                    <option value="custom">Personalizado</option>
                                </select>
                            </div>
                            <div id="reporte-fechas-custom" class="hidden flex-1 grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Desde</label>
                                    <input type="date" id="reporte-fecha-desde" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-50 focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Hasta</label>
                                    <input type="date" id="reporte-fecha-hasta" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-50 focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none">
                                </div>
                            </div>
                            <button id="btn-cargar-datos-reporte" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold flex items-center gap-2 transition-all shadow-md hover:shadow-lg">
                                <i class="fas fa-sync-alt"></i> Cargar Datos
                            </button>
                        </div>
                    </div>

                    <!-- Grids de Reportes -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Card 1: Estado -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600 flex-shrink-0">
                                        <i class="fas fa-list-check text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800">Estado</h3>
                                        <p class="text-sm text-gray-500 leading-relaxed">Reporte de trámites distribuidos por su estatus actual.</p>
                                    </div>
                                </div>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-estado" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-blue-500 transition-all outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-gray-400 italic">
                                        <span class="w-2 h-2 rounded-full bg-gray-300"></span>
                                        <span id="status-estado">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="generarReporteDirecto('estados', 'estado')" class="w-full py-3 bg-blue-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-blue-700 transition-all shadow-sm">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>

                        <!-- Card 2: Tipo de Trámite -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600 flex-shrink-0">
                                        <i class="fas fa-list-ul text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800">Tipo de Trámite</h3>
                                        <p class="text-sm text-gray-500 leading-relaxed">Clasificación de solicitudes según el tipo de gestión.</p>
                                    </div>
                                </div>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-tipos" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 transition-all outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-gray-400 italic">
                                        <span class="w-2 h-2 rounded-full bg-gray-300"></span>
                                        <span id="status-tipos">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="generarReporteDirecto('tipos', 'tipos')" class="w-full py-3 bg-emerald-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-emerald-700 transition-all shadow-sm">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>

                        <!-- Card 3: Desempeño -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition-shadow">
                            <div class="p-6 flex-1">
                                <div class="flex items-start gap-4 mb-6">
                                    <div class="w-12 h-12 rounded-xl bg-purple-50 flex items-center justify-center text-purple-600 flex-shrink-0">
                                        <i class="fas fa-user-check text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800">Desempeño</h3>
                                        <p class="text-sm text-gray-500 leading-relaxed">Métricas de productividad y efectividad por funcionario.</p>
                                    </div>
                                </div>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Formato de Exportación</label>
                                        <select id="formato-desempeno" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-50 text-sm focus:bg-white focus:ring-2 focus:ring-purple-500 transition-all outline-none">
                                            <option value="xlsx">Microsoft Excel (.xlsx)</option>
                                            <option value="pdf">Documento PDF (.pdf)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-gray-400 italic">
                                        <span class="w-2 h-2 rounded-full bg-gray-300"></span>
                                        <span id="status-desempeno">Esperando carga...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 bg-gray-50/50">
                                <button onclick="generarReporteDirecto('desempeno_funcionarios', 'desempeno')" class="w-full py-3 bg-purple-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-purple-700 transition-all shadow-sm">
                                    <i class="fas fa-file-export"></i> Generar Reporte
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Vista Previa (Hidden but kept for logic if needed) -->
                    <div id="reporte-vista-previa" class="hidden"><table id="reporte-tabla"></table></div>
                </div>
            </section>
        </main>
    </div>

    <div id="modal-detalle" class="modal">
        <div class="modal-content">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold">Detalle del Trámite</h3>
                <button id="modal-detalle-cerrar" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
            </div>
            <div id="modal-detalle-body" class="p-6"></div>
        </div>
    </div>

    <script>
        const estLabels = { pendiente: 'Pendiente', en_revision: 'En Proceso', completada: 'Completada', redirigida: 'Redirigido', aprobada: 'En Proceso', rechazada: 'VENCIDO', vencida: 'VENCIDO', invalidada: 'Invalidada' };
        const estClass = { pendiente: 'status-pendiente', en_revision: 'status-proceso', completada: 'status-completado', redirigida: 'status-redirigido', aprobada: 'status-proceso', rechazada: 'status-vencido', vencida: 'status-vencido', invalidada: 'status-invalidada' };
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.getElementById('menu-btn');
        const menuClose = document.getElementById('menu-close-btn');
        const overlay = document.getElementById('menu-overlay');
        menuBtn?.addEventListener('click', () => { sidebar.classList.remove('mobile-hidden'); sidebar.classList.add('mobile-visible'); overlay.classList.add('active'); });
        menuClose?.addEventListener('click', () => { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay.classList.remove('active'); });
        overlay?.addEventListener('click', () => { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay.classList.remove('active'); });

        const sections = { inicio: document.getElementById('seccion-inicio'), tramites: document.getElementById('seccion-tramites'), conexiones: document.getElementById('seccion-conexiones'), reportes: document.getElementById('seccion-reportes') };
        const titles = { inicio: 'Inicio / Métricas', tramites: 'Trámites del Área', conexiones: 'Monitor de Conexiones', reportes: 'Reportes' };

        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const s = this.dataset.section;
                document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                Object.values(sections).forEach(sec => { sec.classList.add('hidden'); sec.classList.remove('block'); });
                if (sections[s]) { sections[s].classList.remove('hidden'); sections[s].classList.add('block'); }
                document.getElementById('section-title').textContent = titles[s] || s;
                if (s === 'inicio') cargarMetricas();
                else if (s === 'tramites') cargarTramites();
                else if (s === 'conexiones') cargarConexiones();
                if (window.innerWidth < 1024) { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay.classList.remove('active'); }
            });
        });

        document.getElementById('user-dropdown-btn')?.addEventListener('click', (e) => { e.stopPropagation(); document.getElementById('dropdown-menu').classList.toggle('hidden'); });
        document.addEventListener('click', () => document.getElementById('dropdown-menu').classList.add('hidden'));

        let chartBar = null, chartPie = null, chartCargaEmpleados = null;
        let flatpickrCoordInited = false;
        function initFlatpickrCoordinador() {
            if (flatpickrCoordInited) return;
            const inpDesde = document.getElementById('filtro-fecha-desde');
            const inpHasta = document.getElementById('filtro-fecha-hasta');
            if (!inpDesde || !inpHasta) return;
            const hace30 = new Date(); hace30.setDate(hace30.getDate() - 30);
            const hoy = new Date();
            const opts = { locale: (typeof flatpickr !== 'undefined' && flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default', dateFormat: 'Y-m-d' };
            flatpickr(inpDesde, { ...opts, defaultDate: hace30, onChange: () => cargarMetricas() });
            flatpickr(inpHasta, { ...opts, defaultDate: hoy, onChange: () => cargarMetricas() });
            flatpickrCoordInited = true;
        }

        function buildParams(prefix) {
            const p = new URLSearchParams();
            const fid = document.getElementById(prefix + 'funcionario')?.value;
            const nac = document.getElementById(prefix + 'nacionalidad')?.value;
            const ced = document.getElementById(prefix + 'cedula')?.value;
            const est = document.getElementById(prefix + 'estado')?.value;
            const fd = document.getElementById(prefix + 'fecha-desde')?.value;
            const fh = document.getElementById(prefix + 'fecha-hasta')?.value;
            if (fid) p.set('funcionario', fid);
            if (nac) p.set('nacionalidad', nac);
            if (ced) p.set('cedula', ced);
            if (est) p.set('estado', est);
            if (fd) p.set('fecha_desde', fd);
            if (fh) p.set('fecha_hasta', fh);
            return p.toString();
        }

        function cargarFuncionarios() {
            fetch('ajax/coordinador_funcionarios.php').then(r => r.json()).then(d => {
                if (d.success && d.funcionarios) {
                    const tramOpts = '<option value="">Todos</option>' + d.funcionarios.map(f => `<option value="${f.id}">${f.nombre}</option>`).join('');
                    const metOpts = '<option value="">Todos los funcionarios</option>' + d.funcionarios.map(f => `<option value="${f.id}">${f.nombre}</option>`).join('');
                    document.getElementById('filtro-funcionario').innerHTML = metOpts;
                    document.getElementById('tram-filtro-funcionario').innerHTML = tramOpts;
                }
            });
        }

        function cargarMetricas() {
            initFlatpickrCoordinador();
            const qs = buildParams('filtro-');
            fetch('ajax/coordinador_metricas.php?' + qs).then(r => r.json()).then(d => {
                if (!d.success) return;
                const k = d.kpis || {};
                document.getElementById('kpi-total').textContent = k.total ?? 0;
                document.getElementById('kpi-pendientes').textContent = k.pendientes ?? 0;
                document.getElementById('kpi-en-proceso').textContent = k.en_proceso ?? 0;
                document.getElementById('kpi-completados').textContent = k.completados ?? 0;
                document.getElementById('kpi-vencidos').textContent = k.vencidos ?? 0;
                document.getElementById('kpi-redirigidos').textContent = k.redirigidos ?? 0;
                const ki = document.getElementById('kpi-invalidados');
                if (ki) ki.textContent = k.invalidados ?? 0;
                const cb = d.chart_bar || { labels: [], data: [], colors: [] };
                const cp = d.chart_pie || { labels: [], data: [] };
                const barColors = cb.colors && cb.colors.length ? cb.colors : ['#f59e0b','#3b82f6','#10b981','#6b7280','#8b5cf6','#f97316','#ef4444'];
                if (chartBar) chartBar.destroy();
                chartBar = new Chart(document.getElementById('chart-bar'), {
                    type: 'bar',
                    data: { labels: cb.labels, datasets: [{ label: 'Cantidad', data: cb.data, backgroundColor: barColors }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });
                if (chartPie) chartPie.destroy();
                chartPie = new Chart(document.getElementById('chart-pie'), {
                    type: 'doughnut',
                    data: { labels: cp.labels, datasets: [{ data: cp.data, backgroundColor: ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ec4899','#14b8a6'] }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: t => t.label + ': ' + t.raw } } } }
                });
                const ce = d.chart_carga_empleados || { labels: [], data: [] };
                if (chartCargaEmpleados) { chartCargaEmpleados.destroy(); chartCargaEmpleados = null; }
                chartCargaEmpleados = new Chart(document.getElementById('chart-carga-empleados'), {
                    type: 'bar',
                    data: { labels: ce.labels || [], datasets: [{ label: 'Trámites', data: ce.data || [], backgroundColor: '#3b82f6' }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { precision: 0 } } } }
                });
            });
        }

        document.getElementById('btn-aplicar-filtros')?.addEventListener('click', cargarMetricas);
        document.getElementById('filtro-funcionario')?.addEventListener('change', cargarMetricas);

        function cargarTramites() {
            const p = new URLSearchParams();
            const n = document.getElementById('tram-filtro-numero')?.value;
            const f = document.getElementById('tram-filtro-funcionario')?.value;
            const nac = document.getElementById('tram-filtro-nacionalidad')?.value;
            const c = document.getElementById('tram-filtro-cedula')?.value;
            const e = document.getElementById('tram-filtro-estado')?.value;
            const fd = document.getElementById('tram-filtro-fecha-desde')?.value;
            const fh = document.getElementById('tram-filtro-fecha-hasta')?.value;
            if (n) p.set('numero', n);
            if (f) p.set('funcionario', f);
            if (nac) p.set('nacionalidad', nac);
            if (c) p.set('cedula', c);
            if (e) p.set('estado', e);
            if (fd) p.set('fecha_desde', fd);
            if (fh) p.set('fecha_hasta', fh);
            fetch('ajax/coordinador_tramites.php?' + p).then(r => r.json()).then(d => {
                const tbody = document.getElementById('tramites-tbody');
                const contador = document.getElementById('contador-tramites');
                if (!d.success || !d.solicitudes || !d.solicitudes.length) {
                    tbody.innerHTML = '<tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">Sin datos</td></tr>';
                    if (contador) contador.textContent = '0';
                    return;
                }
                if (contador) contador.textContent = d.solicitudes.length;
                tbody.innerHTML = d.solicitudes.map(s => `
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${(s.empleado_nombre === 'Sin asignar')
                                ? `<span class="text-gray-400 italic">${s.empleado_nombre}</span>`
                                : (s.empleado_nombre || '-')}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><span class="font-mono">${(typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_identificacion || '') : (s.ciudadano_identificacion || '-'))}</span></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto(s.ciudadano_nombre || '-') : (s.ciudadano_nombre || '-')}</td>
                        <td class="px-6 py-4 whitespace-nowrap"><span class="status-badge ${estClass[s.solicitud_estado] || 'status-pendiente'}">${estLabels[s.solicitud_estado] || (s.solicitud_estado && typeof s.solicitud_estado === 'string' ? s.solicitud_estado.charAt(0).toUpperCase() + s.solicitud_estado.slice(1).toLowerCase().replace(/_/g, ' ') : '-')}</span></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${s.tramite_nombre || '-'}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600"><span class="font-mono">${s.solicitud_numero || '-'}</span></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${s.fecha_registro || '-'}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <button class="btn btn-secondary" onclick="verDetalle(${s.solicitud_id})"><i class="fas fa-info-circle"></i> Detalles</button>
                        </td>
                    </tr>
                `).join('');
            });
        }
        window.verDetalle = function(sid) {
            fetch('ajax/coordinador_detalle_solicitud.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'solicitud_id=' + sid })
                .then(r => r.json()).then(d => {
                    if (!d.success) { alert(d.message || 'Error'); return; }
                    const s = d.solicitud;
                    const cneM = (typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto : (x) => (x == null ? '' : String(x)));
                    document.getElementById('modal-detalle-body').innerHTML = `
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div><p class="text-gray-500">N° Seguimiento</p><p class="font-mono font-semibold">${s.solicitud_numero}</p></div>
                            <div><p class="text-gray-500">Estado</p><p><span class="status-badge ${estClass[s.solicitud_estado] || 'status-pendiente'}">${estLabels[s.solicitud_estado] || (s.solicitud_estado && typeof s.solicitud_estado === 'string' ? s.solicitud_estado.charAt(0).toUpperCase() + s.solicitud_estado.slice(1).toLowerCase().replace(/_/g, ' ') : '-')}</span></p></div>
                            <div><p class="text-gray-500">Ciudadano</p><p class="font-semibold">${cneM(s.ciudadano_nombres)} ${cneM(s.ciudadano_apellidos)}</p></div>
                            <div><p class="text-gray-500">Cédula</p><p class="font-mono">${cneM(s.ciudadano_identificacion)}</p></div>
                            <div><p class="text-gray-500">Teléfono</p><p>${s.ciudadano_telefono || '-'}</p></div>
                            <div><p class="text-gray-500">Trámite</p><p>${s.tramite_nombre}</p></div>
                            <div><p class="text-gray-500">Empleado asignado</p><p>
                                ${(s.empleado_nombre === 'Sin asignar')
                                    ? `<span class="text-gray-400 italic">${s.empleado_nombre}</span>`
                                    : (s.empleado_nombre || '-')}
                            </p></div>
                            <div><p class="text-gray-500">Fecha registro</p><p>${s.fecha_registro}</p></div>
                        </div>
                        ${s.requisitos && s.requisitos.length ? '<div class="mt-4"><p class="text-gray-700 font-medium mb-2">Requisitos</p><ul class="list-disc pl-5 space-y-1">' + s.requisitos.map(r => '<li>' + r.requisito_nombre + ' (' + r.requisitos_solicitud_status + ')</li>').join('') + '</ul></div>' : ''}
                    `;
                    document.getElementById('modal-detalle').classList.add('active');
                });
        };
        document.getElementById('modal-detalle-cerrar')?.addEventListener('click', () => document.getElementById('modal-detalle').classList.remove('active'));

        function cargarConexiones() {
            fetch('ajax/coordinador_conexiones.php').then(r => r.json()).then(d => {
                if (!d.success) return;
                document.getElementById('conn-total').textContent = d.total_conectados ?? 0;
                document.getElementById('conn-activos').textContent = d.activos ?? 0;
                document.getElementById('conn-inactivos').textContent = d.inactivos ?? 0;
                const tbody = document.getElementById('conexiones-tbody');
                const list = d.usuarios || [];
                if (!list.length) {
                    tbody.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No hay usuarios en la coordinación</td></tr>';
                    return;
                }
                tbody.innerHTML = list.map(u => {
                    const isAct = u.estado === 'activo';
                    return `<tr>
                        <td class="px-4 py-2">${u.nombre_completo}</td>
                        <td class="px-4 py-2">${u.rol}</td>
                        <td class="px-4 py-2">${u.ultima_actividad}</td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ${isAct ? 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200' : 'bg-gray-100 text-gray-600 ring-1 ring-gray-200'}">
                                <span class="status-dot ${isAct ? 'dot-activo' : 'dot-inactivo'}"></span>
                                ${isAct ? 'En línea' : 'Desconectado'}
                            </span>
                        </td>
                    </tr>`;
                }).join('');
            });
        }

        document.getElementById('btn-buscar-tramites')?.addEventListener('click', cargarTramites);
        document.getElementById('btn-reset-filtros-tram')?.addEventListener('click', () => {
            document.getElementById('tram-filtro-numero').value = '';
            document.getElementById('tram-filtro-funcionario').value = '';
            document.getElementById('tram-filtro-nacionalidad').value = '';
            document.getElementById('tram-filtro-cedula').value = '';
            document.getElementById('tram-filtro-estado').value = '';
            document.getElementById('tram-filtro-fecha-desde').value = '';
            document.getElementById('tram-filtro-fecha-hasta').value = '';
            cargarTramites();
        });

        document.getElementById('reporte-periodo')?.addEventListener('change', function() {
            document.getElementById('reporte-fechas-custom').classList.toggle('hidden', this.value !== 'custom');
        });

        function getFechasReporte() {
            const periodo = document.getElementById('reporte-periodo').value;
            let fd = '', fh = '';
            const hoy = new Date();
            if (periodo === 'mes') {
                fd = new Date(hoy.getFullYear(), hoy.getMonth(), 1).toISOString().slice(0, 10);
                fh = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0).toISOString().slice(0, 10);
            } else if (periodo === 'semana') {
                const lun = new Date(hoy); lun.setDate(hoy.getDate() - hoy.getDay() + (hoy.getDay() === 0 ? -6 : 1));
                const dom = new Date(lun); dom.setDate(lun.getDate() + 6);
                fd = lun.toISOString().slice(0, 10); fh = dom.toISOString().slice(0, 10);
            } else if (periodo === 'custom') {
                fd = document.getElementById('reporte-fecha-desde')?.value || '';
                fh = document.getElementById('reporte-fecha-hasta')?.value || '';
            }
            return { fd, fh, periodo };
        }

        document.getElementById('btn-cargar-datos-reporte')?.addEventListener('click', function() {
            const { fd, fh } = getFechasReporte();
            if (!fd || !fh) { alert('Por favor seleccione las fechas.'); return; }
            
            const btn = this;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Cargando...';

            const statusIds = ['status-estado', 'status-tipos', 'status-desempeno'];
            statusIds.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.textContent = 'Actualizando datos...';
                    el.parentElement.querySelector('span:first-child').className = 'w-2 h-2 rounded-full bg-blue-400 animate-pulse';
                }
            });

            const p = new URLSearchParams(buildParams('filtro-'));
            p.set('fecha_desde', fd);
            p.set('fecha_hasta', fh);

            // Cargamos métricas para verificar que hay datos
            fetch('ajax/coordinador_metricas.php?' + p.toString())
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    
                    if (d.success) {
                        const cantEstados = d.chart_bar?.data?.reduce((a, b) => a + b, 0) || 0;
                        const cantTipos = d.chart_pie?.data?.reduce((a, b) => a + b, 0) || 0;
                        
                        document.getElementById('status-estado').textContent = `${cantEstados} registros encontrados`;
                        document.getElementById('status-estado').parentElement.querySelector('span:first-child').className = 'w-2 h-2 rounded-full bg-emerald-500';
                        
                        document.getElementById('status-tipos').textContent = `${cantTipos} registros encontrados`;
                        document.getElementById('status-tipos').parentElement.querySelector('span:first-child').className = 'w-2 h-2 rounded-full bg-emerald-500';
                    }
                });

            fetch('ajax/coordinador_reporte_desempeno.php?' + p.toString())
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const cant = d.filas?.length || 0;
                        document.getElementById('status-desempeno').textContent = `${cant} funcionarios con actividad`;
                        document.getElementById('status-desempeno').parentElement.querySelector('span:first-child').className = 'w-2 h-2 rounded-full bg-emerald-500';
                    }
                });
        });

        window.generarReporteDirecto = function(tipo, prefix) {
            const { fd, fh, periodo } = getFechasReporte();
            if (!fd || !fh) { alert('Primero cargue los datos del período.'); return; }
            
            const formato = document.getElementById('formato-' + prefix).value;
            const p = new URLSearchParams(buildParams('filtro-'));
            p.set('fecha_desde', fd);
            p.set('fecha_hasta', fh);

            const tipoNombres = { tramites: 'Tramites', estados: 'Estados', tipos: 'Tipos', desempeno_funcionarios: 'DesempenoFuncionarios' };
            let mes = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][new Date().getMonth()];
            let nombreBase = 'Reporte_' + (tipoNombres[tipo] || tipo) + '_' + mes + '_' + new Date().getFullYear();
            if (periodo === 'custom') nombreBase = 'Reporte_' + (tipoNombres[tipo] || tipo) + '_' + fd + '_' + fh;
            
            const tbl = document.getElementById('reporte-tabla');

            if (tipo === 'desempeno_funcionarios') {
                fetch('ajax/coordinador_reporte_desempeno.php?' + p.toString()).then(r => r.json()).then(d => {
                    if (!d.success) { alert('Error al obtener datos'); return; }
                    window.reporteMetadata = { tipo, nombreBase, generatedAt: new Date().toLocaleString('es-VE') };
                    const filas = d.filas || [];
                    const th = '<thead><tr>' + ['Funcionario', 'Pendientes', 'En Proceso', 'Completados', 'Vencidos', 'Redirigidos', 'Invalidados', 'Total'].map(h => '<th>' + h + '</th>').join('') + '</tr></thead>';
                    tbl.innerHTML = th + '<tbody>' + filas.map(f => `<tr><td>${f.funcionario || '-'}</td><td>${f.pendientes}</td><td>${f.en_proceso}</td><td>${f.completados}</td><td>${f.vencidos}</td><td>${f.redirigidos}</td><td>${f.invalidados ?? 0}</td><td>${f.total}</td></tr>`).join('') + '</tbody>';
                    if (formato === 'pdf') exportarPDF(); else exportarExcel();
                });
            } else {
                fetch('ajax/coordinador_metricas.php?' + p.toString()).then(r => r.json()).then(d => {
                    if (!d.success) { alert('Error al obtener datos'); return; }
                    window.reporteMetadata = { tipo, nombreBase, generatedAt: new Date().toLocaleString('es-VE') };
                    if (tipo === 'estados') {
                        tbl.innerHTML = '<thead><tr><th>Estado</th><th>Cantidad</th></tr></thead><tbody>' + (d.chart_bar?.labels?.map((l, i) => '<tr><td>' + l + '</td><td>' + (d.chart_bar.data[i] || 0) + '</td></tr>').join('') || '') + '</tbody>';
                    } else if (tipo === 'tipos') {
                        tbl.innerHTML = '<thead><tr><th>Tipo de Trámite</th><th>Cantidad</th></tr></thead><tbody>' + (d.chart_pie?.labels?.map((l, i) => '<tr><td>' + l + '</td><td>' + (d.chart_pie.data[i] || 0) + '</td></tr>').join('') || '') + '</tbody>';
                    }
                    if (formato === 'pdf') exportarPDF(); else exportarExcel();
                });
            }
        };
        function exportarExcel() {
            if (!window.reporteMetadata) return;
            const tabla = document.getElementById('reporte-tabla');
            if (!tabla || !tabla.querySelector('tbody')) return;
            const headers = Array.from(tabla.querySelectorAll('thead th')).map(th => th.textContent.trim());
            const rows = Array.from(tabla.querySelectorAll('tbody tr')).map(tr =>
                Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim())
            );
            const encabezado = [['CNE - Reporte de Coordinación'], ['Fecha: ' + (window.reporteMetadata.generatedAt || '')], []];
            const data = [headers, ...rows];
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet([...encabezado, ...data]);
            ws['!cols'] = headers.map((_, i) => ({ wch: Math.max(15, ...data.map(r => String(r[i] || '').length)) }));
            XLSX.utils.book_append_sheet(wb, ws, 'Reporte');
            XLSX.writeFile(wb, (window.reporteMetadata.nombreBase || 'Reporte') + '.xlsx');
        }
        function exportarPDF() {
            if (!window.reporteMetadata) return;
            const tabla = document.getElementById('reporte-tabla');
            if (!tabla || !tabla.querySelector('tbody')) return;
            const { jsPDF } = window.jspdf;
            const desempeno = window.reporteMetadata.tipo === 'desempeno_funcionarios';
            const doc = new jsPDF({ orientation: desempeno ? 'landscape' : 'portrait', unit: 'mm', format: 'a4' });
            doc.setFontSize(14);
            doc.text('CNE - Reporte de Coordinación', 14, 15);
            doc.setFontSize(10);
            doc.text('Fecha de generación: ' + (window.reporteMetadata.generatedAt || ''), 14, 22);
            const headers = Array.from(tabla.querySelectorAll('thead th')).map(th => th.textContent.trim());
            const rows = Array.from(tabla.querySelectorAll('tbody tr')).map(tr =>
                Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim())
            );
            doc.autoTable({
                head: [headers],
                body: rows,
                startY: 30,
                theme: 'grid',
                styles: { fontSize: desempeno ? 8 : 9 },
                headStyles: { fillColor: [37, 99, 235] },
                columnStyles: desempeno ? { 0: { cellWidth: 52 } } : {},
                bodyStyles: desempeno ? { overflow: 'linebreak' } : {}
            });
            doc.save((window.reporteMetadata.nombreBase || 'Reporte') + '.pdf');
        }
        document.getElementById('btn-exportar-pdf')?.addEventListener('click', exportarPDF);
        document.getElementById('btn-exportar-excel')?.addEventListener('click', exportarExcel);

        fetch('ajax/ping_actividad.php', { credentials: 'same-origin' }).catch(() => {});
        setInterval(function() {
            fetch('ajax/ping_actividad.php', { credentials: 'same-origin' }).catch(() => {});
        }, 60000);

        cargarFuncionarios();
        cargarMetricas();
        
        setInterval(() => {
            const activeSection = document.querySelector('.menu-item.active')?.dataset.section;
            if (activeSection === 'inicio') cargarMetricas();
            else if (activeSection === 'conexiones') cargarConexiones();
        }, 30000);
    </script>
</body>
</html>
