<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['rol_id'] ?? 0) !== 5) {
    header('Location: index.php');
    exit;
}

$usuario_id = $_SESSION['user_id'];
limpiarSesionesExpiradas();
actualizarSesionUltimaActividad($usuario_id);
$usuario = obtenerUsuario($usuario_id);
$db = getDB();
/** Solo estas áreas en el modal Pantallas (Funcionario / Coordinador); se excluye la Oficina de Atención al Ciudadano. */
$nombresPantallasAdmin = ['COPAFI', 'Registro Civil', 'Registro Electoral', 'Secretaría'];
$phPantallas = implode(',', array_fill(0, count($nombresPantallasAdmin), '?'));
$stmtPantallas = $db->prepare("
    SELECT coordinacion_id AS id, coordinacion_nombre AS nombre
    FROM coordinacion
    WHERE coordinacion_estado = 'activo'
      AND coordinacion_nombre NOT LIKE '%Atención al Ciudadano%'
      AND coordinacion_nombre NOT LIKE '%Oficina de Atención%'
      AND coordinacion_nombre IN ($phPantallas)
    ORDER BY FIELD(coordinacion_nombre, 'COPAFI', 'Registro Civil', 'Registro Electoral', 'Secretaría')
");
$stmtPantallas->execute($nombresPantallasAdmin);
$coordinaciones_pantallas_admin = $stmtPantallas->fetchAll(PDO::FETCH_ASSOC);
$CNE_RT = ['dashboard' => 'admin', 'coord' => (int) ($usuario['coordinacion_id'] ?? 0)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/includes/head_viewport.php'; ?>
    <title>Sistema CNE - Panel Administrador</title>
    <link rel="icon" href="recursos/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.4); z-index: 50; }
        .modal.active { display: flex; }
        .modal-content { background: #fff; width: 95%; max-width: 800px; max-height: 90vh; overflow-y: auto; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
        .status-badge { padding: 6px 10px; border-radius: 8px; font-weight: 600; font-size: 12px; display:inline-block; }
        .status-activo { background:#ecfdf5; color:#059669; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .status-inactivo { background:#f3f4f6; color:#6b7280; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .badge-admin { background:#6366f1; color:#fff; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .badge-director { background:#8b5cf6; color:#fff; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .badge-coordinador { background:#3b82f6; color:#fff; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .badge-empleado { background:#0ea5e9; color:#fff; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .badge-atencion { background:#06b6d4; color:#fff; padding:6px 10px; border-radius:8px; font-weight:600; font-size:12px; display:inline-block; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; font-weight:600; }
        .btn-primary { background:#3b82f6; color:#fff; }
        .btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; }
        .btn-secondary:hover { background:#e5e7eb; }
        .table-responsive { overflow-x: auto; }
        .custom-scrollbar::-webkit-scrollbar { height: 8px; width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .timeline-admin { position: relative; margin-left: 8px; }
        .timeline-admin-item { position: relative; padding-left: 1.75rem; padding-bottom: 1.35rem; border-left: 2px solid #e2e8f0; }
        .timeline-admin-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-admin-dot { position: absolute; left: -7px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #3b82f6; border: 2px solid #fff; box-shadow: 0 0 0 2px #bfdbfe; }
        .status-sol-pendiente { background:#fff7ed; color:#c2410c; border:1px solid #fdba74; }
        .status-sol-proceso { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; }
        .status-sol-ok { background:#ecfdf5; color:#065f46; border:1px solid #6ee7b7; }
        .status-sol-rojo { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
        .status-sol-vencido { background:#e9ecef; color:#343a40; border:1px solid #6c757d; }
        .status-sol-morado { background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe; }
        .status-sol-invalidada { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        /* Cédula usuario — misma línea visual que dashboard_entrada.php */
        .cedula-tipo-compact { min-width: 60px; }
        .cedula-input-compact { min-width: 120px; }
        @media (max-width: 768px) {
            .cedula-tipo-compact { min-width: 50px; }
            .cedula-input-compact { min-width: 100px; }
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-50">
    <div class="menu-overlay" id="menu-overlay"></div>
    <div class="flex layout-shell w-full min-h-screen min-w-0">
        <aside class="sidebar bg-gray-800 text-white flex flex-col mobile-hidden" id="sidebar">
            <div class="sidebar-header px-6 py-5 border-b border-gray-700 flex justify-between items-center">
                <div class="logo-container flex flex-col items-center justify-center w-full">
                    <img src="recursos/Logo.png" alt="Logo CNE" class="logo-img max-w-40 max-h-16 object-contain">
                </div>
                <button class="menu-close-btn text-white text-lg lg:hidden" id="menu-close-btn"><i class="fas fa-times"></i></button>
            </div>
            <nav class="menu flex-1 py-4">
                <ul class="list-none">
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all active" data-section="usuarios">
                        <i class="fas fa-users w-5 text-center"></i>
                        <span>Gestión de Usuarios</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="catalogos">
                        <i class="fas fa-folder-open w-5 text-center"></i>
                        <span>Gestión de Trámites</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="ciudadanos">
                        <i class="fas fa-id-card w-5 text-center"></i>
                        <span>Gestión de Ciudadanos</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="buscar-solicitudes">
                        <i class="fas fa-search w-5 text-center"></i>
                        <span>Buscar Solicitudes</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="pantallas">
                        <i class="fas fa-desktop w-5 text-center"></i>
                        <span>Pantallas</span>
                    </li>
                    <li class="menu-item cursor-pointer py-4 px-6 flex items-center gap-3 border-l-4 border-transparent transition-all" data-section="respaldos">
                        <i class="fas fa-file-export w-5 text-center"></i>
                        <span>Respaldo de Base de Datos</span>
                    </li>
                </ul>
            </nav>
            <div class="sidebar-footer mt-auto p-4 border-t border-gray-700 text-sm text-gray-400 text-center">
                <p class="mb-1">Sistema de Gestión CNE</p>
                <p class="text-xs">Administrador v2.0.0</p>
            </div>
        </aside>

        <main class="main-content flex flex-col bg-gray-50 w-full max-w-full min-w-0">
            <header class="header bg-white px-4 md:px-6 py-4 shadow-sm border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <div class="flex items-center">
                        <button class="menu-btn bg-blue-500 text-white p-2 rounded-lg mr-2 lg:hidden" id="menu-btn"><i class="fas fa-bars text-base"></i></button>
                        <h1 class="text-lg md:text-xl font-semibold text-gray-800" id="section-title">Gestión de Usuarios</h1>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right hidden sm:block">
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars(trim(($usuario['user_nombres'] ?? '') . ' ' . ($usuario['user_apellidos'] ?? '')) ?: $_SESSION['nombre_completo'] ?? 'Admin'); ?></p>
                            <p class="text-xs text-gray-500">Administrador</p>
                        </div>
                        <!-- Campana de Notificaciones -->
                        <div class="relative">
                            <button id="btn-notificaciones" type="button" class="relative rounded-full w-10 h-10 flex items-center justify-center bg-blue-500 text-white hover:bg-blue-600 transition-colors">
                                <i class="fas fa-bell"></i>
                                <span id="badge-notificaciones" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs px-2 py-0.5 rounded-full hidden">0</span>
                            </button>
                            <div id="panel-notificaciones" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 hidden z-50" onclick="event.stopPropagation()">
                                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                                    <p class="font-semibold text-gray-700">Notificaciones</p>
                                    <button id="btn-cerrar-panel" type="button" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
                                </div>
                                <div id="lista-notificaciones" class="max-h-80 overflow-y-auto custom-scrollbar">
                                    <div class="px-4 py-6 text-center text-gray-500">Sin notificaciones</div>
                                </div>
                                <div class="px-4 py-2 border-t border-gray-100 flex flex-col gap-1">
                                    <button id="btn-marcar-todo" type="button" class="w-full text-sm text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-md px-2 py-2 transition-colors">Marcar todo como leído</button>
                                    <a href="#" id="btn-ver-todas" class="block w-full text-center text-sm text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-md px-2 py-2 transition-colors">Ver todas</a>
                                </div>
                            </div>
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

            <!-- Sección Usuarios -->
            <section class="section block p-4 md:p-6" id="seccion-usuarios">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-users text-blue-600"></i>
                                    <span>Nómina Global de Usuarios</span>
                                </h2>
                                <p class="text-sm text-gray-600">Gestiona usuarios, roles y coordinaciones del sistema</p>
                            </div>
                            <button id="btn-nuevo-usuario" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold"><i class="fas fa-plus mr-1"></i> Nuevo Usuario</button>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow mb-6">
                        <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-filter text-blue-600 mr-1"></i> Filtros</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-search mr-1 text-gray-500"></i> Nombre / Cédula / Usuario</label>
                                <input type="text" id="filtro-nombre" placeholder="Buscar..." class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-user-tag mr-1 text-gray-500"></i> Rol</label>
                                <select id="filtro-rol" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 bg-white">
                                    <option value="">Todos los roles</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-building mr-1 text-gray-500"></i> Coordinación</label>
                                <select id="filtro-coordinacion" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 bg-white">
                                    <option value="">Todas las áreas</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow">
                        <div class="table-responsive overflow-x-auto custom-scrollbar max-h-[60vh] overflow-y-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre Completo</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Área</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tabla-usuarios-body" class="bg-white divide-y divide-gray-200"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sección Catálogos -->
            <section class="section hidden p-4 md:p-6" id="seccion-catalogos">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-folder-open text-blue-600"></i>
                                    <span>Gestión de Catálogos</span>
                                </h2>
                                <p class="text-sm text-gray-600">Administra trámites y requisitos por coordinación</p>
                            </div>
                            <div class="flex flex-wrap gap-3 items-center">
                                <div class="flex items-center gap-2">
                                    <label class="text-sm font-medium text-gray-700">Coordinación:</label>
                                    <select id="cat-coordinacion" class="p-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                        <option value="">Todas</option>
                                    </select>
                                </div>
                                <button id="btn-cargar-tramites" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold text-sm"><i class="fas fa-sync mr-1"></i> Cargar</button>
                                <button id="btn-agregar-tramite" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold text-sm"><i class="fas fa-plus mr-1"></i> Agregar Trámite</button>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div>
                                <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-list text-blue-600"></i> Trámites</h3>
                                <div id="lista-tramites" class="space-y-2 max-h-80 overflow-auto custom-scrollbar rounded-lg border border-gray-100 p-2"></div>
                            </div>
                            <div>
                                <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-file-alt text-blue-600"></i> Requisitos del trámite seleccionado</h3>
                                <div id="lista-requisitos-header" class="text-sm text-gray-500 mb-2 hidden"></div>
                                <div id="lista-requisitos" class="space-y-2 max-h-80 overflow-auto custom-scrollbar rounded-lg border border-gray-100 p-2"></div>
                                <button id="btn-agregar-requisito" class="btn btn-secondary text-sm mt-3 hidden"><i class="fas fa-plus"></i> Agregar Requisito</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sección Ciudadanos -->
            <section class="section hidden p-4 md:p-6" id="seccion-ciudadanos">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-id-card text-blue-600"></i>
                                    <span>Gestión de Ciudadanos</span>
                                </h2>
                                <p class="text-sm text-gray-600">Consulta y edita datos personales de ciudadanos registrados</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow mb-6">
                        <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-search text-blue-600 mr-1"></i> Buscar por Cédula o Nombre</h3>
                        <input type="text" id="filtro-ciudadano" placeholder="Cédula, nombres o apellidos..." class="w-full max-w-md p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow">
                        <div class="table-responsive overflow-x-auto custom-scrollbar max-h-[60vh] overflow-y-auto">
                            <table class="cne-tabla-admin-ciudadanos min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cédula</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombres</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Apellidos</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha Nac.</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Teléfono</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dirección</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tabla-ciudadanos-body" class="bg-white divide-y divide-gray-200"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Buscar Solicitudes -->
            <section class="section hidden p-4 md:p-6" id="seccion-buscar-solicitudes">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-1 flex items-center gap-2">
                            <i class="fas fa-search text-blue-600"></i> Buscar Solicitudes
                        </h2>
                        <p class="text-sm text-gray-600">Búsqueda por número de seguimiento, cédula o nombre del ciudadano. Visualice seguimiento, edite datos o redirija a otra coordinación.</p>
                    </div>
                    <div class="bg-white rounded-xl shadow border border-gray-100 p-6 mb-6">
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Criterio de búsqueda</label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <input type="text" id="admin-buscar-sol-input" autocomplete="off" placeholder="Nro. seguimiento, cédula o nombre del ciudadano…"
                                class="flex-1 p-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-sm">
                            <button type="button" id="btn-admin-buscar-sol" class="btn btn-primary justify-center shadow-md"><i class="fas fa-search"></i> Buscar</button>
                        </div>
                    </div>
                    <div id="admin-buscar-sol-resultado" class="hidden">
                        <p id="admin-buscar-sol-resumen" class="text-sm text-gray-600 mb-3 hidden"></p>
                        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
                            <div class="table-responsive overflow-x-auto custom-scrollbar">
                                <table class="min-w-full text-sm divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Seguimiento</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ciudadano</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cédula</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Género</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Coordinación</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo trámite</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registrado por</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-28">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="admin-buscar-sol-tbody" class="bg-white divide-y divide-gray-200"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div id="admin-buscar-sol-vacio" class="text-center py-10 text-gray-500">
                        <i class="fas fa-search text-gray-300 text-4xl mb-2"></i>
                        <p>Ingrese un criterio y pulse Buscar</p>
                    </div>
                    <div id="admin-buscar-sol-msg" class="hidden rounded-xl border border-amber-100 bg-amber-50 px-6 py-8 text-center text-gray-800"></div>
                </div>
            </section>

            <!-- Sección Pantallas (supervisión de roles) -->
            <section class="section hidden p-4 md:p-6" id="seccion-pantallas">
                <div class="max-w-7xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-1 flex items-center gap-3">
                            <i class="fas fa-desktop text-blue-600"></i>
                            <span>Supervisión de Pantallas</span>
                        </h2>
                        <p class="text-sm text-gray-600">Abra cada vista con los mismos permisos que el rol. Para Funcionario y Coordinador elija el área (coordinación).</p>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-white rounded-xl p-6 shadow border border-gray-100 flex flex-col gap-4">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="badge-atencion text-xs sm:text-sm"><i class="fas fa-headset mr-1"></i> Rol 1</span>
                                <h3 class="text-lg font-semibold text-gray-800">Atención al Ciudadano</h3>
                            </div>
                            <p class="text-sm text-gray-600 flex-1">Ventanilla, nueva solicitud y seguimiento ciudadano.</p>
                            <form method="post" action="auth/admin_view_as.php">
                                <input type="hidden" name="role_id" value="1">
                                <button type="submit" class="w-full py-3 rounded-lg bg-cyan-600 text-white font-semibold hover:bg-cyan-700 transition-colors shadow-sm">Entrar a la pantalla</button>
                            </form>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow border border-gray-100 flex flex-col gap-4">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="badge-empleado text-xs sm:text-sm"><i class="fas fa-user-tie mr-1"></i> Rol 2</span>
                                <h3 class="text-lg font-semibold text-gray-800">Funcionario</h3>
                            </div>
                            <p class="text-sm text-gray-600 flex-1">Gestión de trámites y solicitudes del área seleccionada.</p>
                            <button type="button" id="btn-pantalla-funcionario" class="w-full py-3 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors shadow-sm">Entrar a la pantalla</button>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow border border-gray-100 flex flex-col gap-4">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="badge-coordinador text-xs sm:text-sm"><i class="fas fa-user-shield mr-1"></i> Rol 3</span>
                                <h3 class="text-lg font-semibold text-gray-800">Coordinador</h3>
                            </div>
                            <p class="text-sm text-gray-600 flex-1">Supervisión, reportes y métricas del área elegida.</p>
                            <button type="button" id="btn-pantalla-coordinador" class="w-full py-3 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors shadow-sm">Entrar a la pantalla</button>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow border border-gray-100 flex flex-col gap-4">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="badge-director text-xs sm:text-sm"><i class="fas fa-chart-line mr-1"></i> Rol 4</span>
                                <h3 class="text-lg font-semibold text-gray-800">Director</h3>
                            </div>
                            <p class="text-sm text-gray-600 flex-1">Panel estratégico y reportes globales.</p>
                            <form method="post" action="auth/admin_view_as.php">
                                <input type="hidden" name="role_id" value="4">
                                <button type="submit" class="w-full py-3 rounded-lg bg-violet-600 text-white font-semibold hover:bg-violet-700 transition-colors shadow-sm">Entrar a la pantalla</button>
                            </form>
                        </div>
                    </div>
                    <form id="form-admin-view-as-area" method="post" action="auth/admin_view_as.php" class="hidden">
                        <input type="hidden" name="role_id" id="pantalla-role-id" value="">
                        <input type="hidden" name="coordinacion_id" id="pantalla-coord-id" value="">
                    </form>
                </div>
            </section>

            <!-- Sección Respaldos -->
            <section class="section hidden p-4 md:p-6" id="seccion-respaldos">
                <div class="max-w-4xl mx-auto w-full">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-4 md:p-6 shadow mb-6 border border-blue-100">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="mb-4 md:mb-0">
                                <h2 class="text-gray-800 text-lg md:text-xl font-semibold mb-2 flex items-center gap-3">
                                    <i class="fas fa-file-export text-blue-600"></i>
                                    <span>Respaldo de Base de Datos</span>
                                </h2>
                                <p class="text-sm text-gray-600">Genera respaldos manuales o configura el respaldo automático</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow mb-6 relative overflow-hidden" id="backup-manual-card">
                        <h3 class="text-gray-800 font-semibold mb-4 flex items-center gap-2"><i class="fas fa-database text-blue-600 mr-1"></i> Respaldo Manual</h3>
                        <div id="backup-generando-overlay" class="hidden absolute inset-0 z-10 bg-white/90 flex flex-col items-center justify-center rounded-xl">
                            <i class="fas fa-circle-notch fa-spin text-4xl text-blue-600 mb-3"></i>
                            <p class="text-gray-800 font-medium text-center px-4">Generando respaldo, espere…</p>
                            <p class="text-xs text-gray-500 mt-1">Puede tardar varios minutos en bases grandes.</p>
                        </div>
                        <button type="button" id="btn-generar-backup" class="w-full py-4 px-6 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl flex items-center justify-center gap-3 transition-colors">
                            <i class="fas fa-database text-2xl"></i>
                            <span>Generar Respaldo Ahora</span>
                        </button>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow mb-6">
                        <h3 class="text-gray-800 font-semibold mb-4 flex items-center gap-2"><i class="fas fa-clock text-blue-600 mr-1"></i> Respaldo Automático</h3>
                        <p class="text-xs text-gray-500 mb-3">Con la sesión de administrador abierta, el panel consulta al servidor cada 30 segundos; el servidor (zona America/Caracas) acepta el respaldo durante todo el minuto programado (p. ej. 20:04:00–20:04:59). La configuración se guarda en <code class="text-xs bg-gray-100 px-1 rounded">configuracion_sistema</code> bajo la clave <code class="text-xs bg-gray-100 px-1 rounded">respaldo_automatico</code> (JSON). Los archivos se envían al grupo de Telegram configurado en <code class="text-xs bg-gray-100 px-1 rounded">config/telegram_config.php</code>. También puede programar <code class="text-xs bg-gray-100 px-1 rounded">cron/backup_automatico.php</code> (CLI).</p>
                        <div class="flex flex-wrap items-center gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Hora programada</label>
                                <input type="time" id="backup-hora" class="p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="text-sm font-medium text-gray-700">Activar</label>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="backup-activo" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                    <span class="ml-2 text-sm text-gray-600" id="backup-activo-label">Off</span>
                                </label>
                            </div>
                            <button id="btn-guardar-backup-config" class="btn btn-primary"><i class="fas fa-save"></i> Guardar configuración</button>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-4 md:p-6 shadow">
                        <h3 class="text-gray-800 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-history text-blue-600 mr-1"></i> Últimos 5 respaldos</h3>
                        <p id="backup-indicador-telegram" class="text-sm mb-3 rounded-lg px-3 py-2 bg-gray-50 border border-gray-100 text-gray-700 hidden" role="status"></p>
                        <div class="table-responsive overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tamaño</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Sincronización Telegram</th>
                                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Descarga</th>
                                    </tr>
                                </thead>
                                <tbody id="tabla-backup-historial" class="bg-white divide-y divide-gray-200"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Modal: elegir área (Funcionario / Coordinador) -->
        <div id="modal-pantallas-area" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-pantallas-titulo">
            <div class="modal-content max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b border-gray-100 relative shrink-0">
                    <h3 id="modal-pantallas-titulo" class="text-lg font-semibold text-gray-800 text-center pr-8">Seleccionar área</h3>
                    <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 p-1.5 rounded-lg hover:bg-gray-100" onclick="cerrarModalPantallaArea()" aria-label="Cerrar"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-6 sm:p-8 max-h-[70vh] overflow-y-auto custom-scrollbar flex flex-col items-center">
                    <p class="text-sm text-gray-600 mb-6 text-center max-w-sm leading-relaxed">Elija una de las coordinaciones disponibles para cargar datos y permisos de esa área.</p>
                    <div class="flex flex-col gap-3 w-full max-w-sm mx-auto" id="lista-areas-pantallas-btns">
                        <?php foreach ($coordinaciones_pantallas_admin as $c): ?>
                        <button type="button" class="w-full text-center px-4 py-3.5 rounded-xl border border-gray-200 bg-white hover:bg-blue-50 hover:border-blue-300 font-semibold text-gray-800 shadow-sm transition-colors" data-coord-id="<?php echo (int) $c['id']; ?>">
                            <?php echo htmlspecialchars($c['nombre']); ?>
                        </button>
                        <?php endforeach; ?>
                        <?php if (count($coordinaciones_pantallas_admin) === 0): ?>
                        <p class="text-sm text-amber-700 text-center py-4 px-2">No hay coordinaciones configuradas con los nombres esperados (COPAFI, Registro Civil, Registro Electoral, Secretaría). Revise la tabla <span class="font-mono text-xs">coordinacion</span>.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal seguimiento (timeline) -->
        <div id="modal-seguimiento-admin" class="fixed inset-0 z-[70] hidden items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-slate-900/55" data-close-seg-admin></div>
            <div class="relative bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[88vh] flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex justify-between items-center px-5 py-4 bg-gradient-to-r from-blue-900 via-blue-800 to-blue-600 text-white shrink-0">
                    <h3 class="font-semibold text-base md:text-lg pr-4">
                        <i class="fas fa-route mr-2 opacity-90"></i>
                        Seguimiento — <span id="modal-seg-admin-numero" class="font-mono font-bold"></span>
                    </h3>
                    <button type="button" class="p-2 rounded-lg hover:bg-white/15 shrink-0" data-close-seg-admin aria-label="Cerrar"><i class="fas fa-times text-lg"></i></button>
                </div>
                <div class="overflow-y-auto custom-scrollbar p-5 md:p-6 flex-1 bg-gray-50/80" id="modal-seg-admin-content"></div>
            </div>
        </div>

        <!-- Modal editar solicitud -->
        <div id="modal-editar-solicitud-admin" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 overflow-y-auto" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-slate-900/50" data-close-edit-sol-admin></div>
            <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl my-4 border border-gray-200 overflow-hidden">
                <div class="px-5 py-4 bg-gradient-to-r from-blue-900 to-blue-600 text-white flex justify-between items-center">
                    <h3 class="font-semibold text-lg"><i class="fas fa-edit mr-2"></i>Editar solicitud</h3>
                    <button type="button" class="p-2 rounded-lg hover:bg-white/15" data-close-edit-sol-admin><i class="fas fa-times"></i></button>
                </div>
                <form id="form-editar-solicitud-admin" class="p-5 md:p-6 space-y-4 max-h-[calc(100vh-8rem)] overflow-y-auto custom-scrollbar">
                    <input type="hidden" id="edit-sol-id" name="solicitud_id">
                    <p class="text-sm text-gray-500">Solicitud <span id="edit-sol-numero" class="font-mono font-semibold text-blue-700"></span></p>
                    <p class="text-xs text-gray-500 -mt-2">Los datos de identidad y ubicación fija del ciudadano son solo lectura; edítelos desde el módulo correspondiente.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Nombres <span class="text-gray-400 font-normal">(solo lectura)</span></label>
                            <input type="text" id="edit-ciudadano-nombres" readonly tabindex="-1" class="w-full p-3 border border-gray-200 rounded-lg text-sm bg-gray-100 text-gray-800 cursor-default focus:outline-none focus:ring-0">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Apellidos <span class="text-gray-400 font-normal">(solo lectura)</span></label>
                            <input type="text" id="edit-ciudadano-apellidos" readonly tabindex="-1" class="w-full p-3 border border-gray-200 rounded-lg text-sm bg-gray-100 text-gray-800 cursor-default focus:outline-none focus:ring-0">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Cédula <span class="text-gray-400 font-normal">(solo lectura)</span></label>
                            <input type="text" id="edit-ciudadano-cedula" readonly tabindex="-1" class="w-full p-3 border border-gray-200 rounded-lg font-mono text-sm bg-gray-100 text-gray-800 cursor-default focus:outline-none focus:ring-0">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Género <span class="text-gray-400 font-normal">(solo lectura)</span></label>
                            <select id="edit-ciudadano-genero" disabled class="w-full p-3 border border-gray-200 rounded-lg text-sm bg-gray-100 text-gray-800 cursor-not-allowed opacity-100">
                                <option value="">—</option>
                                <option value="masculino">Masculino</option>
                                <option value="femenino">Femenino</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Estado (entidad federal) <span class="text-gray-400 font-normal">(solo lectura)</span></label>
                            <select id="edit-ciudadano-estado-id" disabled class="w-full p-3 border border-gray-200 rounded-lg text-sm bg-gray-100 text-gray-800 cursor-not-allowed opacity-100">
                                <option value="">—</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Estado del trámite *</label>
                            <select id="edit-solicitud-estado" required class="w-full p-3 border border-gray-200 rounded-lg bg-white focus:ring-2 focus:ring-blue-500 text-sm">
                                <option value="pendiente">Pendiente</option>
                                <option value="en_revision">En Proceso</option>
                                <option value="completada">Completada</option>
                                <option value="redirigida">Redirigida</option>
                                <option value="vencida">Vencida</option>
                                <option value="invalidada">Invalidada</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Coordinación *</label>
                            <select id="edit-sol-coordinacion" required class="w-full p-3 border border-gray-200 rounded-lg bg-white focus:ring-2 focus:ring-blue-500 text-sm"></select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Tipo de trámite *</label>
                            <select id="edit-sol-tramite" required class="w-full p-3 border border-gray-200 rounded-lg bg-yellow-100 focus:ring-2 focus:ring-blue-500 text-sm"></select>
                        </div>
                    </div>
                    <div id="wrap-requisitos-sol-admin" class="border-t border-gray-100 pt-4 space-y-2">
                        <label class="block text-xs font-semibold text-gray-600">Requisitos del trámite</label>
                        <p id="edit-sol-requisitos-hint" class="text-xs text-gray-500 leading-snug"></p>
                        <div id="edit-sol-requisitos-list" class="space-y-2 max-h-52 overflow-y-auto custom-scrollbar rounded-lg border border-gray-100 p-3 bg-gray-50/80">
                            <p class="text-sm text-gray-500">Cargando requisitos…</p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 pt-2 border-t border-gray-100">
                        <button type="submit" class="w-full py-3 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 text-sm">
                            <i class="fas fa-save mr-2"></i>Guardar cambios
                        </button>
                        <button type="button" id="btn-admin-eliminar-solicitud" class="w-full py-3 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-700 text-sm">
                            <i class="fas fa-trash-alt mr-2"></i>Eliminar solicitud
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Usuario -->
    <div class="modal" id="modal-usuario">
        <div class="modal-content">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modal-usuario-titulo">Nuevo Usuario</h3>
                <button class="text-gray-500 hover:text-gray-700" onclick="cerrarModalUsuario()"><i class="fas fa-times"></i></button>
            </div>
            <form id="form-usuario" class="p-6 space-y-4">
                <input type="hidden" id="user-id-original" name="user_identificacion_original">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cédula/ID *</label>
                        <div id="user-cedula-container" class="relative">
                            <div class="flex flex-row items-center gap-2 flex-wrap">
                                <select id="user-cedula-tipo" aria-label="Tipo de cédula"
                                    class="cedula-tipo-compact p-3 border-2 border-gray-300 rounded-lg font-bold text-gray-700 bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                                    <option value="V">V</option>
                                    <option value="E">E</option>
                                </select>
                                <span class="font-bold text-gray-500" aria-hidden="true">-</span>
                                <input type="text" id="user-cedula-numero" inputmode="numeric" autocomplete="off"
                                    class="cedula-input-compact p-3 border-2 border-gray-300 rounded-lg font-mono focus:ring-2 focus:ring-blue-200 focus:border-blue-500"
                                    placeholder="12345678" maxlength="8" title="Hasta 8 dígitos">
                            </div>
                            <p id="user-cedula-error" class="hidden text-sm text-red-600 mt-1"></p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Usuario *</label>
                        <input type="text" id="user-username" name="user_username" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombres *</label>
                        <input type="text" id="user-nombres" name="user_nombres" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Apellidos *</label>
                        <input type="text" id="user-apellidos" name="user_apellidos" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                    </div>
                </div>
                <div id="user-password-wrap">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña *</label>
                    <input type="password" id="user-password" name="user_password" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500" minlength="6">
                </div>
                <div id="user-nueva-password-wrap" style="display:none;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nueva contraseña</label>
                    <input type="password" id="user-nueva-password" name="nueva_password" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500" minlength="6" autocomplete="new-password">
                    <p class="text-xs text-gray-500 mt-1">Dejar en blanco para mantener la contraseña actual.</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rol *</label>
                        <select id="user-rol" name="rol_id" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 bg-white"></select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Coordinación</label>
                        <select id="user-coordinacion" name="coordinacion_id" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 bg-white">
                            <option value="_none_">— Sin asignar —</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select id="user-estado" name="user_estado" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 bg-white">
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalUsuario()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Trámite -->
    <div class="modal" id="modal-tramite">
        <div class="modal-content">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modal-tramite-titulo">Agregar Trámite</h3>
                <button class="text-gray-500 hover:text-gray-700" onclick="cerrarModalTramite()"><i class="fas fa-times"></i></button>
            </div>
            <form id="form-tramite" class="p-6 space-y-4">
                <input type="hidden" id="tramite-id" name="tramite_id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Trámite *</label>
                    <input type="text" id="tramite-nombre" name="tramite_nombre" required class="w-full p-3 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Coordinación *</label>
                    <select id="tramite-coordinacion" name="coordinacion_id" required class="w-full p-3 border rounded-lg"></select>
                </div>
                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalTramite()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Requisito -->
    <div class="modal" id="modal-requisito">
        <div class="modal-content">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modal-requisito-titulo">Agregar Requisito</h3>
                <button class="text-gray-500 hover:text-gray-700" onclick="cerrarModalRequisito()"><i class="fas fa-times"></i></button>
            </div>
            <form id="form-requisito" class="p-6 space-y-4">
                <input type="hidden" id="requisito-id" name="requisito_id">
                <input type="hidden" id="requisito-tramite-id" name="tramite_id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Requisito *</label>
                    <input type="text" id="requisito-nombre" name="requisito_nombre" required class="w-full p-3 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Activo</label>
                    <select id="requisito-activo" name="requisito_activo" class="w-full p-3 border rounded-lg">
                        <option value="1">Sí</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-4">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalRequisito()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Ciudadano (réplica del formulario de Entrada) -->
    <div class="modal" id="modal-ciudadano">
        <div class="modal-content max-w-2xl">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50">
                <h3 class="text-lg font-semibold text-gray-800">Editar Ciudadano</h3>
                <button class="text-gray-500 hover:text-gray-700 transition-colors" onclick="cerrarModalCiudadano()"><i class="fas fa-times"></i></button>
            </div>
            <form id="form-ciudadano" class="p-6 space-y-6">
                <input type="hidden" id="ciudadano-id-original" name="ciudadano_identificacion_original">
                <input type="hidden" id="ciudadano-institucion" name="institucion_id" value="_none_">
                <!-- Fila 1: Cédula (Input Group V/E), Teléfono (Input Group), Género -->
                <div class="grid grid-cols-1 md:grid-cols-[minmax(0,220px)_minmax(0,220px)_1fr] gap-6 gap-x-6">
                    <div class="flex flex-col justify-end max-w-[220px]">
                        <label class="block mb-2 font-semibold text-gray-700">Cédula/ID *</label>
                        <div class="flex items-stretch border border-gray-300 rounded-lg shadow-sm focus-within:ring-2 focus-within:ring-blue-200 focus-within:border-blue-500 w-fit min-w-0">
                            <select id="ciudadano-cedula-tipo" class="w-16 h-10 rounded-l-lg rounded-r-none border-0 border-r border-gray-300 bg-gray-50 px-3 font-bold text-gray-700 focus:ring-0 shrink-0">
                                <option value="V">V</option>
                                <option value="E">E</option>
                            </select>
                            <input type="text" id="ciudadano-cedula-numero" required maxlength="12" placeholder="25888999"
                                class="w-28 min-w-0 h-10 rounded-r-lg rounded-l-none border-0 px-3 font-mono focus:ring-0" title="Números de cédula (ej. 25888999)">
                        </div>
                    </div>
                    <div class="flex flex-col justify-end max-w-[220px]">
                        <label class="block mb-2 font-semibold text-gray-700">Teléfono</label>
                        <div class="flex items-stretch border border-gray-300 rounded-lg shadow-sm focus-within:ring-2 focus-within:ring-blue-200 focus-within:border-blue-500 w-fit min-w-0">
                            <select id="ciudadano-telefono-codigo" class="h-10 rounded-l-lg rounded-r-none border-0 border-r border-gray-300 bg-gray-50 px-2 text-gray-700 text-sm min-w-[72px] focus:ring-0 shrink-0">
                                <option value="0412">0412</option>
                                <option value="0414">0414</option>
                                <option value="0416">0416</option>
                                <option value="0424">0424</option>
                                <option value="0426">0426</option>
                            </select>
                            <input type="text" id="ciudadano-telefono-numero" maxlength="7" placeholder="1234567"
                                class="w-24 min-w-0 h-10 rounded-r-lg rounded-l-none border-0 px-3 focus:ring-0" inputmode="numeric">
                        </div>
                    </div>
                    <div class="flex flex-col justify-end">
                        <label class="block mb-2 font-semibold text-gray-700">Género</label>
                        <select id="ciudadano-genero" name="ciudadano_genero" class="h-10 w-full border border-gray-300 rounded-lg shadow-sm px-3 bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-500">
                            <option value="">Seleccione un género</option>
                            <option value="masculino">Masculino</option>
                            <option value="femenino">Femenino</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                </div>
                <!-- Fila 2: Nombres y Apellidos -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 gap-x-6">
                    <div>
                        <label class="block mb-2 font-semibold text-gray-700">Nombres *</label>
                        <input type="text" id="ciudadano-nombres" name="ciudadano_nombres" required class="cne-mayus-ciudadano-live w-full h-10 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all" placeholder="Ingrese los nombres">
                    </div>
                    <div>
                        <label class="block mb-2 font-semibold text-gray-700">Apellidos *</label>
                        <input type="text" id="ciudadano-apellidos" name="ciudadano_apellidos" required class="cne-mayus-ciudadano-live w-full h-10 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all" placeholder="Ingrese los apellidos">
                    </div>
                </div>
                <!-- Fila 3: Fecha de Nacimiento y Email -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 gap-x-6">
                    <div>
                        <label class="block mb-2 font-semibold text-gray-700">Fecha de Nacimiento</label>
                        <div class="relative">
                            <input type="text" id="ciudadano-fecha-nacimiento" name="ciudadano_fecha_nacimiento" readonly
                                class="w-full h-10 px-3 pr-10 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all"
                                placeholder="dd/mm/aaaa">
                            <i class="fas fa-calendar-alt absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                        </div>
                    </div>
                    <div>
                        <label class="block mb-2 font-semibold text-gray-700">Email</label>
                        <input type="email" id="ciudadano-email" name="ciudadano_email" class="w-full h-10 px-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all" placeholder="Opcional">
                    </div>
                </div>
                <!-- Fila 4: Estado y Municipio -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 gap-x-6">
                    <div>
                        <label class="block mb-2 font-semibold text-gray-700">Estado</label>
                        <select id="ciudadano-estado" name="estado_id" class="w-full h-10 px-3 border border-gray-300 rounded-lg shadow-sm bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all">
                            <option value="">Seleccione un estado</option>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-2 font-semibold text-gray-700">Municipio</label>
                        <select id="ciudadano-municipio" name="municipio_id" class="w-full h-10 px-3 border border-gray-300 rounded-lg shadow-sm bg-white focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all">
                            <option value="">Seleccione un municipio</option>
                        </select>
                    </div>
                </div>
                <!-- Fila 5: Dirección (ancho completo) -->
                <div class="col-span-full">
                    <label class="block mb-2 font-semibold text-gray-700">Dirección / Punto de Referencia</label>
                    <textarea id="ciudadano-direccion" name="ciudadano_direccion" rows="3" class="cne-mayus-ciudadano-live w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 transition-all resize-none" placeholder="Especifique dirección o punto de referencia"></textarea>
                </div>
                <div class="flex justify-center gap-4 pt-6 border-t border-gray-200">
                    <button type="button" class="px-6 py-3 border border-gray-300 rounded-lg font-semibold text-gray-700 bg-white hover:bg-gray-50 shadow-sm transition-colors" onclick="cerrarModalCiudadano()">Cancelar</button>
                    <button type="submit" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-sm transition-colors text-base"><i class="fas fa-save mr-2"></i>Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('menu-overlay');
        let flatpickrCiudadanoFechaNacimiento = null;
        document.getElementById('menu-btn')?.addEventListener('click', () => { sidebar.classList.remove('mobile-hidden'); sidebar.classList.add('mobile-visible'); overlay?.classList.add('active'); });
        document.getElementById('menu-close-btn')?.addEventListener('click', () => { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay?.classList.remove('active'); });
        overlay?.addEventListener('click', () => { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay?.classList.remove('active'); });
        document.getElementById('user-dropdown-btn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('dropdown-menu')?.classList.toggle('hidden');
            document.getElementById('panel-notificaciones')?.classList.add('hidden');
        });
        document.addEventListener('click', () => {
            document.getElementById('dropdown-menu')?.classList.add('hidden');
            document.getElementById('panel-notificaciones')?.classList.add('hidden');
        });

        // Flatpickr para Fecha de Nacimiento en modal Editar Ciudadano
        (function initFlatpickrCiudadano() {
            const inp = document.getElementById('ciudadano-fecha-nacimiento');
            if (inp && typeof flatpickr !== 'undefined') {
                flatpickrCiudadanoFechaNacimiento = flatpickr(inp, {
                    locale: (flatpickr.l10ns && flatpickr.l10ns.es) ? flatpickr.l10ns.es : 'default',
                    dateFormat: 'Y-m-d',
                    altFormat: 'd/m/Y',
                    altInput: true,
                    allowInput: false,
                    maxDate: 'today',
                    disableMobile: true
                });
            }
        })();

        const sectionTitles = { usuarios: 'Gestión de Usuarios', catalogos: 'Gestión de Catálogos', ciudadanos: 'Gestión de Ciudadanos', 'buscar-solicitudes': 'Buscar Solicitudes', pantallas: 'Supervisión de Pantallas', respaldos: 'Respaldo de Base de Datos' };
        const sections = { usuarios: document.getElementById('seccion-usuarios'), catalogos: document.getElementById('seccion-catalogos'), ciudadanos: document.getElementById('seccion-ciudadanos'), 'buscar-solicitudes': document.getElementById('seccion-buscar-solicitudes'), pantallas: document.getElementById('seccion-pantallas'), respaldos: document.getElementById('seccion-respaldos') };
        function guardarPestañaAdmin(sectionId) {
            if (!sectionId || !sectionTitles[sectionId]) return;
            try { localStorage.setItem('admin_active_tab', sectionId); } catch (e) {}
        }

        let ultimosIdsNotif = new Set();
        function inicializarNotificaciones() {
            const btn = document.getElementById('btn-notificaciones');
            const panel = document.getElementById('panel-notificaciones');
            const cerrar = document.getElementById('btn-cerrar-panel');
            const marcarTodo = document.getElementById('btn-marcar-todo');
            const verTodas = document.getElementById('btn-ver-todas');
            btn?.addEventListener('click', (e) => {
                e.stopPropagation();
                document.getElementById('dropdown-menu')?.classList.add('hidden');
                panel?.classList.toggle('hidden');
                if (!panel?.classList.contains('hidden')) fetchNotificacionesAdmin();
            });
            cerrar?.addEventListener('click', () => panel?.classList.add('hidden'));
            marcarTodo?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                marcarTodasLeidasAdmin();
            });
            verTodas?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                panel?.classList.add('hidden');
                document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                document.querySelector('.menu-item[data-section="ciudadanos"]')?.classList.add('active');
                Object.keys(sections).forEach(k => { sections[k].classList.toggle('block', k === 'ciudadanos'); sections[k].classList.toggle('hidden', k !== 'ciudadanos'); });
                document.getElementById('section-title').textContent = 'Gestión de Ciudadanos';
                guardarPestañaAdmin('ciudadanos');
                cargarCiudadanos();
            });
        }
        function fetchNotificacionesAdmin() {
            fetch('ajax/admin_obtener_notificaciones.php')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    actualizarBadgeAdmin(data.unread);
                    renderNotificacionesAdmin(data.notificaciones || []);
                })
                .catch(() => {});
        }
        function actualizarBadgeAdmin(count) {
            const badge = document.getElementById('badge-notificaciones');
            if (!badge) return;
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
        function renderNotificacionesAdmin(lista) {
            const cont = document.getElementById('lista-notificaciones');
            if (!cont) return;
            if (!lista.length) {
                cont.innerHTML = '<div class="px-4 py-6 text-center text-gray-500">Sin notificaciones</div>';
                return;
            }
            const ultimas = lista.slice(0, 10);
            cont.innerHTML = ultimas.map(n => `
                <button type="button" class="w-full text-left px-4 py-3 hover:bg-gray-50 border-b border-gray-100 flex items-start gap-3"
                        onclick="clickNotificacionAdmin(${n.notificacion_id}, '${(n.ciudadano_identificacion || '').replace(/'/g, "\\'")}')">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800">${n.notificacion_titulo || 'Notificación'}</p>
                        <p class="text-sm text-gray-600 break-words">${(n.mensaje || '').replace(/</g, '&lt;')}</p>
                        <p class="text-xs text-gray-400 mt-1">${n.fecha || ''}</p>
                    </div>
                    ${n.notificacion_estado === 'no_leido' ? '<span class="ml-2 h-2 w-2 bg-blue-500 rounded-full mt-1 shrink-0"></span>' : ''}
                </button>
            `).join('');
        }
        function marcarTodasLeidasAdmin() {
            fetch('ajax/admin_marcar_notificaciones_leidas.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: ''
            })
            .then(r => r.json())
            .then(data => {
                if (data?.success) {
                    actualizarBadgeAdmin(0);
                    document.getElementById('lista-notificaciones')?.querySelectorAll('.bg-blue-500').forEach(dot => dot.remove());
                    fetchNotificacionesAdmin();
                }
            })
            .catch(() => {});
        }
        function clickNotificacionAdmin(id, ciudadanoId) {
            fetch('ajax/admin_marcar_notificacion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ notificacion_id: id }).toString()
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fetchNotificacionesAdmin();
                    document.getElementById('panel-notificaciones')?.classList.add('hidden');
                    document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                    document.querySelector('.menu-item[data-section="ciudadanos"]')?.classList.add('active');
                    Object.keys(sections).forEach(k => { sections[k].classList.toggle('block', k === 'ciudadanos'); sections[k].classList.toggle('hidden', k !== 'ciudadanos'); });
                    document.getElementById('section-title').textContent = 'Gestión de Ciudadanos';
                    guardarPestañaAdmin('ciudadanos');
                    cargarCiudadanos();
                    if (ciudadanoId && ciudadanoId.startsWith('V-CNE')) {
                        document.getElementById('filtro-ciudadano').value = ciudadanoId;
                        setTimeout(() => cargarCiudadanos(), 100);
                    }
                }
            })
            .catch(() => {});
        }
        inicializarNotificaciones();
        setInterval(fetchNotificacionesAdmin, 60000);
        fetchNotificacionesAdmin();

        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                const s = this.dataset.section;
                guardarPestañaAdmin(s);
                document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                Object.keys(sections).forEach(k => {
                    const el = sections[k];
                    if (!el) return;
                    el.classList.toggle('block', k === s);
                    el.classList.toggle('hidden', k !== s);
                });
                document.getElementById('section-title').textContent = sectionTitles[s] || s;
                if (s === 'catalogos') cargarCatalogos();
                if (s === 'ciudadanos') cargarCiudadanos();
                if (s === 'buscar-solicitudes') inicializarVistaBuscarSolicitudesAdmin();
                if (s === 'respaldos') { cargarConfigBackup(); cargarHistorialBackup(); }
                if (window.innerWidth < 1024) { sidebar.classList.remove('mobile-visible'); sidebar.classList.add('mobile-hidden'); overlay?.classList.remove('active'); }
            });
        });
        (function restaurarPestañaAdmin() {
            try {
                var id = localStorage.getItem('admin_active_tab');
                if (!id || !sectionTitles[id]) return;
                var item = document.querySelector('.menu-item[data-section="' + id + '"]');
                if (item) item.click();
            } catch (e) {}
        })();

        let pantallaRoleAreaPendiente = null;
        function abrirModalPantallaArea(roleId) {
            pantallaRoleAreaPendiente = roleId;
            document.getElementById('modal-pantallas-area')?.classList.add('active');
        }
        function cerrarModalPantallaArea() {
            pantallaRoleAreaPendiente = null;
            document.getElementById('modal-pantallas-area')?.classList.remove('active');
        }
        document.getElementById('btn-pantalla-funcionario')?.addEventListener('click', () => abrirModalPantallaArea(2));
        document.getElementById('btn-pantalla-coordinador')?.addEventListener('click', () => abrirModalPantallaArea(3));
        document.getElementById('modal-pantallas-area')?.addEventListener('click', function(e) {
            if (e.target === this) cerrarModalPantallaArea();
        });
        document.querySelectorAll('#lista-areas-pantallas-btns button').forEach(btn => {
            btn.addEventListener('click', function() {
                const coordId = this.getAttribute('data-coord-id');
                const roleId = pantallaRoleAreaPendiente;
                if (!coordId || !roleId) return;
                document.getElementById('pantalla-role-id').value = String(roleId);
                document.getElementById('pantalla-coord-id').value = coordId;
                document.getElementById('form-admin-view-as-area').submit();
            });
        });

        let adminBuscarSolInited = false;
        let _adminBuscarSolDebounce = null;
        let adminEdicionCoords = [];

        function escapeHtmlAdmin(s) {
            if (s == null || s === '') return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        /** Destino de redirección: nunca la Oficina de Atención al Ciudadano (regla de negocio + coherencia con backend) */
        function coordinacionPermiteComoDestinoRedireccion(c) {
            var n = String(c.coordinacion_nombre || '').toLowerCase();
            if (n.indexOf('oficina de atención') !== -1) return false;
            if (n.indexOf('atención al ciudadano') !== -1) return false;
            return true;
        }

        function claseBadgeEstadoSolAdmin(est) {
            const e = (est || '').toLowerCase();
            if (e === 'pendiente') return 'status-sol-pendiente';
            if (e === 'en_revision' || e === 'aprobada' || e === 'proceso') return 'status-sol-proceso';
            if (e === 'completada' || e === 'ok') return 'status-sol-ok';
            if (e === 'rechazada' || e === 'vencida') return 'status-sol-vencido';
            if (e === 'redirigida') return 'status-sol-morado';
            if (e === 'invalidada') return 'status-sol-invalidada';
            return 'status-sol-pendiente';
        }

        function etiquetaEstadoSolAdmin(est) {
            const key = (est || '').toLowerCase();
            const m = { pendiente: 'Pendiente', en_revision: 'En Proceso', aprobada: 'En Proceso', rechazada: 'VENCIDO', completada: 'Completada', redirigida: 'Redirigida', vencida: 'VENCIDO', invalidada: 'Invalidada' };
            if (m[key]) return m[key];
            if (!est) return '—';
            return String(est).replace(/_/g, ' ');
        }

        function cerrarModalSegAdmin() {
            window.__CNE_TIMELINE_SOL_ID = null;
            const el = document.getElementById('modal-seguimiento-admin');
            if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
        }

        function abrirTimelineAdmin(solicitudId) {
            window.__CNE_TIMELINE_SOL_ID = solicitudId != null ? Number(solicitudId) : null;
            const modal = document.getElementById('modal-seguimiento-admin');
            const content = document.getElementById('modal-seg-admin-content');
            const numEl = document.getElementById('modal-seg-admin-numero');
            if (!modal || !content) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            numEl.textContent = '…';
            content.innerHTML = '<div class="text-center py-12 text-gray-500"><i class="fas fa-spinner fa-spin text-3xl text-blue-600 mb-3 block"></i>Cargando…</div>';
            fetch('ajax/admin_seguimiento_solicitud.php?solicitud_id=' + encodeURIComponent(solicitudId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = '<div class="text-red-600 text-center py-8">' + escapeHtmlAdmin(data.message || 'Error') + '</div>';
                        return;
                    }
                    numEl.textContent = data.solicitud_numero || ('#' + solicitudId);
                    const evs = data.eventos || [];
                    if (!evs.length) {
                        content.innerHTML = '<div class="text-center py-10 text-gray-500">Sin registros de auditoría u observaciones.</div>';
                        return;
                    }
                    content.innerHTML = '<div class="timeline-admin">' + evs.map(function(ev) {
                        const acc = escapeHtmlAdmin(String(ev.accion || '').replace(/_/g, ' '));
                        const desc = escapeHtmlAdmin(ev.descripcion || '');
                        const fun = escapeHtmlAdmin(ev.funcionario || '');
                        const fh = escapeHtmlAdmin(ev.fecha_hora || '');
                        return '<div class="timeline-admin-item"><span class="timeline-admin-dot"></span>' +
                            '<p class="text-xs font-bold text-blue-800 tracking-wide mb-1">' + acc + '</p>' +
                            '<p class="text-sm text-gray-800 mb-2">' + desc + '</p>' +
                            '<p class="text-xs text-gray-600"><i class="fas fa-user-tie mr-1 text-blue-500"></i>' + fun + '</p>' +
                            '<p class="text-xs text-gray-500 mt-1"><i class="far fa-clock mr-1"></i>' + fh + '</p></div>';
                    }).join('') + '</div>';
                })
                .catch(() => { content.innerHTML = '<div class="text-red-600 text-center">Error de conexión</div>'; });
        }

        function cerrarModalEditSolAdmin() {
            const el = document.getElementById('modal-editar-solicitud-admin');
            if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
        }

        function adminEstadoPermiteEditarRequisitos(est) {
            const e = (est || '').toLowerCase();
            return e === 'en_revision' || e === 'completada' || e === 'redirigida' || e === 'invalidada';
        }

        function aplicarBloqueoRequisitosSolAdmin() {
            const estSel = document.getElementById('edit-solicitud-estado');
            const est = estSel ? estSel.value : '';
            const allow = adminEstadoPermiteEditarRequisitos(est);
            const hint = document.getElementById('edit-sol-requisitos-hint');
            if (hint) {
                hint.textContent = allow
                    ? 'Puede marcar o desmarcar requisitos para corregir el registro de la solicitud.'
                    : 'Con el estado Pendiente los requisitos permanecen bloqueados. Use En proceso, Completada, Redirigida o Invalidada para poder corregirlos.';
            }
            document.querySelectorAll('#edit-sol-requisitos-list .edit-sol-req-cb').forEach(function(cb) {
                cb.disabled = !allow;
            });
        }

        function cargarRequisitosListaSolAdmin(solicitudId, tramiteId) {
            const list = document.getElementById('edit-sol-requisitos-list');
            if (!list) return;
            list.innerHTML = '<p class="text-sm text-gray-500">Cargando requisitos…</p>';
            fetch('ajax/admin_solicitud_requisitos_ui.php?solicitud_id=' + encodeURIComponent(solicitudId) + '&tramite_id=' + encodeURIComponent(tramiteId))
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d.success) {
                        list.innerHTML = '<p class="text-sm text-red-600">' + escapeHtmlAdmin(d.message || 'Error') + '</p>';
                        return;
                    }
                    const reqs = d.requisitos || [];
                    if (!reqs.length) {
                        list.innerHTML = '<p class="text-sm text-gray-500">Este trámite no tiene requisitos activos en catálogo.</p>';
                    } else {
                        list.innerHTML = reqs.map(function(r) {
                            const id = r.requisito_id;
                            const chk = r.marcado ? ' checked' : '';
                            return '<label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer">' +
                                '<input type="checkbox" class="edit-sol-req-cb mt-0.5 rounded border-gray-300 text-blue-600" data-requisito-id="' + id + '"' + chk + ' />' +
                                '<span>' + escapeHtmlAdmin(r.requisito_nombre || '') + '</span></label>';
                        }).join('');
                    }
                    aplicarBloqueoRequisitosSolAdmin();
                })
                .catch(function() {
                    list.innerHTML = '<p class="text-sm text-red-600">Error de conexión</p>';
                });
        }

        function obtenerRequisitosMarcadosSolAdmin() {
            return Array.from(document.querySelectorAll('#edit-sol-requisitos-list .edit-sol-req-cb'))
                .filter(function(cb) { return cb.checked && !cb.disabled; })
                .map(function(cb) { return parseInt(cb.getAttribute('data-requisito-id'), 10); })
                .filter(function(n) { return !isNaN(n); });
        }

        function quitarFilaBuscarSolicitudesAdmin(solicitudId) {
            const tbody = document.getElementById('admin-buscar-sol-tbody');
            if (!tbody) return;
            const tr = tbody.querySelector('tr[data-solicitud-id="' + solicitudId + '"]');
            if (tr) tr.remove();
            const rest = tbody.querySelectorAll('tr').length;
            const resumen = document.getElementById('admin-buscar-sol-resumen');
            if (resumen) {
                if (rest > 1) resumen.textContent = 'Se encontraron ' + rest + ' solicitudes.';
                else if (rest === 1) resumen.classList.add('hidden');
            }
            if (rest === 0) {
                document.getElementById('admin-buscar-sol-resultado')?.classList.add('hidden');
                const vacio = document.getElementById('admin-buscar-sol-vacio');
                if (vacio) vacio.classList.remove('hidden');
            }
        }

        function llenarSelectTramitesAdmin(tramites, selectedId) {
            const sel = document.getElementById('edit-sol-tramite');
            if (!sel) return;
            sel.innerHTML = (tramites || []).map(function(t) {
                const pad = t.tramite_padre_id ? '↳ ' : '';
                return '<option value="' + t.tramite_id + '">' + pad + escapeHtmlAdmin(t.tramite_nombre) + '</option>';
            }).join('');
            if (selectedId) sel.value = String(selectedId);
        }

        function abrirModalEditarSolAdmin(solicitudId) {
            fetch('ajax/admin_solicitud_edicion_datos.php?solicitud_id=' + encodeURIComponent(solicitudId))
                .then(r => r.json())
                .then(function(d) {
                    if (!d.success) { toast('error', d.message || 'Error'); return; }
                    adminEdicionCoords = d.coordinaciones || [];
                    const sol = d.solicitud;
                    const ciu = d.ciudadano || {};
                    const cneMEd = (typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto : (x) => (x == null ? '' : String(x)));
                    document.getElementById('edit-sol-id').value = sol.solicitud_id;
                    document.getElementById('edit-sol-numero').textContent = sol.solicitud_numero || '';
                    document.getElementById('edit-ciudadano-nombres').value = cneMEd(ciu.ciudadano_nombres || '');
                    document.getElementById('edit-ciudadano-apellidos').value = cneMEd(ciu.ciudadano_apellidos || '');
                    document.getElementById('edit-ciudadano-cedula').value = ciu.ciudadano_identificacion || '';
                    const selGen = document.getElementById('edit-ciudadano-genero');
                    selGen.disabled = false;
                    selGen.value = ciu.ciudadano_genero || '';
                    selGen.disabled = true;

                    document.getElementById('edit-solicitud-estado').value = sol.solicitud_estado || 'pendiente';

                    const selEst = document.getElementById('edit-ciudadano-estado-id');
                    selEst.disabled = false;
                    selEst.innerHTML = '<option value="">—</option>' + (d.estados || []).map(function(e) {
                        return '<option value="' + e.estado_id + '">' + escapeHtmlAdmin(e.estado_nombre) + '</option>';
                    }).join('');
                    if (ciu.estado_id) selEst.value = String(ciu.estado_id);
                    selEst.disabled = true;

                    const selC = document.getElementById('edit-sol-coordinacion');
                    selC.innerHTML = adminEdicionCoords.map(function(c) {
                        return '<option value="' + c.coordinacion_id + '">' + escapeHtmlAdmin(c.coordinacion_nombre) + '</option>';
                    }).join('');
                    selC.value = String(sol.coordinacion_id);

                    llenarSelectTramitesAdmin(d.tramites, sol.tramite_id);

                    cargarRequisitosListaSolAdmin(sol.solicitud_id, sol.tramite_id);

                    const m = document.getElementById('modal-editar-solicitud-admin');
                    m.classList.remove('hidden');
                    m.classList.add('flex');
                })
                .catch(function() { toast('error', 'Error al cargar el formulario'); });
        }

        function ejecutarBuscarSolicitudesAdmin() {
            const input = document.getElementById('admin-buscar-sol-input');
            const term = (input && input.value) ? input.value.trim() : '';
            const boxRes = document.getElementById('admin-buscar-sol-resultado');
            const boxVacio = document.getElementById('admin-buscar-sol-vacio');
            const boxMsg = document.getElementById('admin-buscar-sol-msg');
            const tbody = document.getElementById('admin-buscar-sol-tbody');
            const resumen = document.getElementById('admin-buscar-sol-resumen');
            const btn = document.getElementById('btn-admin-buscar-sol');
            if (!term) {
                boxRes?.classList.add('hidden');
                boxMsg?.classList.add('hidden');
                boxVacio?.classList.remove('hidden');
                return;
            }
            boxVacio.classList.add('hidden');
            boxMsg.classList.add('hidden');
            boxRes.classList.add('hidden');
            const prev = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando…'; }
            fetch('ajax/admin_buscar_solicitudes.php?q=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(function(data) {
                    if (btn) { btn.disabled = false; btn.innerHTML = prev; }
                    const vacioMsg = 'No se encontraron solicitudes con esos criterios';
                    if (data.success && data.tramites && data.tramites.length) {
                        const list = data.tramites;
                        if (resumen) {
                            if (list.length > 1) {
                                resumen.textContent = 'Se encontraron ' + list.length + ' solicitudes.';
                                resumen.classList.remove('hidden');
                            } else resumen.classList.add('hidden');
                        }
                        tbody.innerHTML = list.map(function(t) {
                            const sid = parseInt(t.solicitud_id, 10);
                            const est = (t.estado || '').toLowerCase();
                            return '<tr class="hover:bg-gray-50" data-solicitud-id="' + sid + '">' +
                                '<td class="px-4 py-3 font-mono font-semibold text-blue-700">' + escapeHtmlAdmin(t.numero_seguimiento) + '</td>' +
                                '<td class="px-4 py-3"><span class="status-badge ' + claseBadgeEstadoSolAdmin(est) + '">' + escapeHtmlAdmin(etiquetaEstadoSolAdmin(est)) + '</span></td>' +
                                '<td class="px-4 py-3 text-gray-800 max-w-[140px]">' + escapeHtmlAdmin(t.ciudadano_nombre) + '</td>' +
                                '<td class="px-4 py-3 font-mono">' + escapeHtmlAdmin(t.ciudadano_identificacion) + '</td>' +
                                '<td class="px-4 py-3">' + escapeHtmlAdmin(t.ciudadano_genero) + '</td>' +
                                '<td class="px-4 py-3 max-w-[120px]">' + escapeHtmlAdmin(t.area_nombre) + '</td>' +
                                '<td class="px-4 py-3 max-w-[140px]">' + escapeHtmlAdmin(t.tipo_tramite_nombre) + '</td>' +
                                '<td class="px-4 py-3 max-w-[120px]">' + escapeHtmlAdmin(t.creado_por) + '</td>' +
                                '<td class="px-4 py-3 whitespace-nowrap text-gray-600">' + escapeHtmlAdmin(t.fecha_registro) + '</td>' +
                                '<td class="px-4 py-3 text-center whitespace-nowrap">' +
                                '<button type="button" class="inline-flex w-8 h-8 items-center justify-center rounded-lg bg-blue-50 text-blue-700 border border-blue-200 mr-1" title="Ver detalles" data-admin-ver-seg="' + sid + '"><i class="fas fa-eye text-sm"></i></button>' +
                                '<button type="button" class="inline-flex w-8 h-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200" title="Editar" data-admin-edit-sol="' + sid + '"><i class="fas fa-pen text-sm"></i></button>' +
                                '</td></tr>';
                        }).join('');
                        boxRes.classList.remove('hidden');
                    } else {
                        boxRes.classList.add('hidden');
                        const txt = (data && data.message) ? data.message : vacioMsg;
                        boxMsg.innerHTML = '<p class="font-medium">' + escapeHtmlAdmin(txt) + '</p><p class="text-sm text-gray-600 mt-2">Pruebe con otro número, cédula o nombre.</p>';
                        boxMsg.classList.remove('hidden');
                    }
                })
                .catch(function() {
                    if (btn) { btn.disabled = false; btn.innerHTML = prev; }
                    toast('error', 'Error en la búsqueda');
                });
        }

        function inicializarVistaBuscarSolicitudesAdmin() {
            document.getElementById('admin-buscar-sol-resultado')?.classList.add('hidden');
            document.getElementById('admin-buscar-sol-msg')?.classList.add('hidden');
            document.getElementById('admin-buscar-sol-vacio')?.classList.remove('hidden');
            if (!adminBuscarSolInited) {
                adminBuscarSolInited = true;
                document.getElementById('btn-admin-buscar-sol')?.addEventListener('click', ejecutarBuscarSolicitudesAdmin);
                document.getElementById('admin-buscar-sol-input')?.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') ejecutarBuscarSolicitudesAdmin();
                });
                document.getElementById('admin-buscar-sol-input')?.addEventListener('input', function() {
                    clearTimeout(_adminBuscarSolDebounce);
                    const v = (this.value || '').trim();
                    if (v.length < 2) return;
                    _adminBuscarSolDebounce = setTimeout(ejecutarBuscarSolicitudesAdmin, 400);
                });
                document.getElementById('admin-buscar-sol-tbody')?.addEventListener('click', function(e) {
                    const b1 = e.target.closest('[data-admin-ver-seg]');
                    if (b1) { abrirTimelineAdmin(parseInt(b1.getAttribute('data-admin-ver-seg'), 10)); return; }
                    const b2 = e.target.closest('[data-admin-edit-sol]');
                    if (b2) { abrirModalEditarSolAdmin(parseInt(b2.getAttribute('data-admin-edit-sol'), 10)); }
                });
                document.querySelectorAll('[data-close-seg-admin]').forEach(function(el) {
                    el.addEventListener('click', cerrarModalSegAdmin);
                });
                document.querySelectorAll('[data-close-edit-sol-admin]').forEach(function(el) {
                    el.addEventListener('click', cerrarModalEditSolAdmin);
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key !== 'Escape') return;
                    if (!document.getElementById('modal-seguimiento-admin')?.classList.contains('hidden')) cerrarModalSegAdmin();
                    else if (!document.getElementById('modal-editar-solicitud-admin')?.classList.contains('hidden')) cerrarModalEditSolAdmin();
                });
                document.getElementById('edit-sol-coordinacion')?.addEventListener('change', function() {
                    const cid = this.value;
                    if (!cid) return;
                    fetch('ajax/admin_tramites_por_coordinacion.php?coordinacion_id=' + encodeURIComponent(cid))
                        .then(r => r.json())
                        .then(function(d) {
                            if (d.success) {
                                llenarSelectTramitesAdmin(d.tramites, null);
                                const sid = document.getElementById('edit-sol-id') && document.getElementById('edit-sol-id').value;
                                const tid = document.getElementById('edit-sol-tramite') && document.getElementById('edit-sol-tramite').value;
                                if (sid && tid) cargarRequisitosListaSolAdmin(parseInt(sid, 10), parseInt(tid, 10));
                            }
                        });
                });
                document.getElementById('edit-sol-tramite')?.addEventListener('change', function() {
                    const sid = document.getElementById('edit-sol-id') && document.getElementById('edit-sol-id').value;
                    const tid = this.value;
                    if (sid && tid) cargarRequisitosListaSolAdmin(parseInt(sid, 10), parseInt(tid, 10));
                });
                document.getElementById('edit-solicitud-estado')?.addEventListener('change', aplicarBloqueoRequisitosSolAdmin);
                document.getElementById('form-editar-solicitud-admin')?.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const fd = new URLSearchParams();
                    fd.append('solicitud_id', document.getElementById('edit-sol-id').value);
                    fd.append('solicitud_estado', document.getElementById('edit-solicitud-estado').value);
                    fd.append('tramite_id', document.getElementById('edit-sol-tramite').value);
                    fd.append('requisitos', JSON.stringify(obtenerRequisitosMarcadosSolAdmin()));
                    fetch('ajax/admin_solicitud_guardar.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
                        .then(r => r.json())
                        .then(function(d) {
                            toast(d.success ? 'success' : 'error', d.message || (d.success ? 'Guardado' : 'Error'));
                            if (d.success) { cerrarModalEditSolAdmin(); ejecutarBuscarSolicitudesAdmin(); }
                        })
                        .catch(function() { toast('error', 'Error de red'); });
                });
                document.getElementById('btn-admin-eliminar-solicitud')?.addEventListener('click', function() {
                    const sid = parseInt(document.getElementById('edit-sol-id').value, 10);
                    const num = (document.getElementById('edit-sol-numero') && document.getElementById('edit-sol-numero').textContent) ? document.getElementById('edit-sol-numero').textContent.trim() : '';
                    if (!sid) return;
                    Swal.fire({
                        title: '¿Eliminar solicitud?',
                        text: 'Se eliminará permanentemente la solicitud ' + (num || ('#' + sid)) + '. Esta acción no se puede deshacer.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, eliminar',
                        cancelButtonText: 'Cancelar',
                        confirmButtonColor: '#dc2626'
                    }).then(function(res) {
                        if (!res.isConfirmed) return;
                        const fd = new URLSearchParams();
                        fd.append('solicitud_id', String(sid));
                        fetch('ajax/admin_solicitud_eliminar.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                toast(d.success ? 'success' : 'error', d.message || (d.success ? 'Eliminada' : 'Error'));
                                if (d.success) {
                                    cerrarModalEditSolAdmin();
                                    quitarFilaBuscarSolicitudesAdmin(sid);
                                }
                            })
                            .catch(function() { toast('error', 'Error de red'); });
                    });
                });
            }
        }

        function toast(tipo, msg) {
            Swal.fire({ toast: true, position: 'top-end', icon: tipo, title: msg, showConfirmButton: false, timer: 3000 });
        }

        function badgeRol(r, id) {
            const n = (r || '').toLowerCase();
            const i = parseInt(id) || 0;
            if (n.includes('admin') || i === 5) return 'badge-admin';
            if (n.includes('director') || i === 4) return 'badge-director';
            if (n.includes('coordinador') || i === 3) return 'badge-coordinador';
            if (n.includes('funcionario') || i === 2) return 'badge-empleado';
            return 'badge-atencion';
        }

        let tramiteSeleccionado = null;

        function cargarUsuarios() {
            const nombre = document.getElementById('filtro-nombre').value.trim();
            const rol = document.getElementById('filtro-rol').value;
            const coord = document.getElementById('filtro-coordinacion').value;
            const params = new URLSearchParams({ action: 'get_all' });
            if (nombre) params.set('nombre', nombre);
            if (rol) params.set('rol_id', rol);
            if (coord) params.set('coordinacion_id', coord);

            fetch('ajax/admin_usuarios_controller.php?' + params).then(r => r.json()).then(d => {
                const tbody = document.getElementById('tabla-usuarios-body');
                tbody.innerHTML = '';
                const list = d.success ? (d.usuarios || []) : [];
                if (!d.success) {
                    const errMsg = d.message || d.error || 'Error al cargar';
                    console.error('get_all error:', errMsg);
                    tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-8 text-center text-red-600">${errMsg}</td></tr>`;
                    return;
                }
                if (!list.length) {
                    tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">Sin usuarios</td></tr>';
                    return;
                }
                list.forEach(u => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-gray-50 transition-colors';
                    const est = (u.user_estado || '').toLowerCase() === 'activo' ? 'status-activo' : 'status-inactivo';
                    const estTxt = (u.user_estado || '').toLowerCase() === 'activo' ? 'Activo' : 'Inactivo';
                    tr.innerHTML = `<td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 font-mono">${u.user_identificacion || '-'}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${(u.user_nombres||'')} ${(u.user_apellidos||'')}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${u.user_username||'-'}</td><td class="px-6 py-4 whitespace-nowrap"><span class="${badgeRol(u.rol_nombre,u.rol_id)}">${u.rol_nombre||'-'}</span></td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${u.coordinacion_nombre||'-'}</td><td class="px-6 py-4 whitespace-nowrap"><span class="${est}">${estTxt}</span></td><td class="px-6 py-4 whitespace-nowrap"><button type="button" class="btn btn-secondary py-2 px-3 text-sm mr-2" title="Editar" data-id="${(u.user_identificacion||'').replace(/"/g,'&quot;')}"><i class="fas fa-user-edit text-blue-600"></i></button><button type="button" class="btn btn-secondary py-2 px-3 text-sm" title="Eliminar" data-id="${(u.user_identificacion||'').replace(/"/g,'&quot;')}" data-nombre="${((u.user_nombres||'')+' '+(u.user_apellidos||'')).trim().replace(/"/g,'&quot;')}"><i class="fas fa-user-times text-red-600"></i></button></td>`;
                    const btnEdit = tr.querySelector('button[title="Editar"]');
                    const btnDel = tr.querySelector('button[title="Eliminar"]');
                    btnEdit.onclick = () => editarUsuario(btnEdit.dataset.id);
                    btnDel.onclick = () => eliminarUsuario(btnDel.dataset.id, (btnDel.dataset.nombre || '').replace(/&quot;/g,'"'));
                    tbody.appendChild(tr);
                });
            }).catch(err => {
                console.error('cargarUsuarios:', err);
                document.getElementById('tabla-usuarios-body').innerHTML = '<tr><td colspan="7" class="px-6 py-8 text-center text-red-500">Error de red o respuesta inválida</td></tr>';
            });
        }

        /**
         * Carga roles/coordinaciones para filtros, catálogos y modal de usuario.
         * @param {function|null} onDone — se ejecuta tras rellenar el DOM (p. ej. abrir modal edición).
         * @param {{rol_id?: *, coordinacion_id?: *}|null} presetUsuario — si viene de editar: marca selected en rol_id y coordinacion_id (null/undefined → “Sin asignar”).
         */
        function cargarMetadata(onDone, presetUsuario) {
            fetch('ajax/admin_get_metadata.php').then(r => r.json()).then(d => {
                if (!d.success) {
                    if (typeof onDone === 'function') onDone();
                    return;
                }
                const roles = d.roles || [];
                const coords = d.coordinaciones || [];
                document.getElementById('filtro-rol').innerHTML = '<option value="">Todos los roles</option>' + roles.map(r => `<option value="${r.rol_id}">${escapeHtmlAdmin(r.rol_nombre)}</option>`).join('');
                document.getElementById('filtro-coordinacion').innerHTML = '<option value="">Todas las áreas</option>' + coords.map(c => `<option value="${c.coordinacion_id}">${escapeHtmlAdmin(c.coordinacion_nombre)}</option>`).join('');
                const pr = presetUsuario && presetUsuario.rol_id != null && presetUsuario.rol_id !== '' ? presetUsuario.rol_id : null;
                const pcRaw = presetUsuario && Object.prototype.hasOwnProperty.call(presetUsuario, 'coordinacion_id') ? presetUsuario.coordinacion_id : undefined;
                const sinCoord = pcRaw === null || pcRaw === '' || pcRaw === undefined;
                document.getElementById('user-rol').innerHTML = roles.map(r => {
                    const sel = pr != null && String(r.rol_id) === String(pr) ? ' selected' : '';
                    return `<option value="${r.rol_id}"${sel}>${escapeHtmlAdmin(r.rol_nombre)}</option>`;
                }).join('');
                document.getElementById('user-coordinacion').innerHTML =
                    '<option value="_none_"' + (sinCoord ? ' selected' : '') + '>— Sin asignar —</option>' +
                    coords.map(c => {
                        const sel = !sinCoord && String(c.coordinacion_id) === String(pcRaw) ? ' selected' : '';
                        return `<option value="${c.coordinacion_id}"${sel}>${escapeHtmlAdmin(c.coordinacion_nombre)}</option>`;
                    }).join('');
                document.getElementById('cat-coordinacion').innerHTML = '<option value="">Todas</option>' + coords.map(c => `<option value="${c.coordinacion_id}">${escapeHtmlAdmin(c.coordinacion_nombre)}</option>`).join('');
                document.getElementById('tramite-coordinacion').innerHTML = coords.map(c => `<option value="${c.coordinacion_id}">${escapeHtmlAdmin(c.coordinacion_nombre)}</option>`).join('');
                if (typeof onDone === 'function') onDone();
            }).catch(function() {
                if (typeof onDone === 'function') onDone();
            });
        }

        function parseUserIdentificacionForForm(full) {
            const raw = String(full || '').trim();
            if (!raw) return { tipo: 'V', num: '' };
            const hy = raw.match(/^([VE])[\s\-]+([\d\s]{1,24})$/i);
            if (hy) {
                const digits = hy[2].replace(/\D/g, '').slice(0, 8);
                return { tipo: hy[1].toUpperCase(), num: digits };
            }
            const compact = raw.match(/^([VE])(\d{1,8})$/i);
            if (compact) return { tipo: compact[1].toUpperCase(), num: compact[2] };
            const digitsOnly = raw.replace(/\D/g, '');
            if (digitsOnly) return { tipo: 'V', num: digitsOnly.slice(0, 8) };
            return { tipo: 'V', num: '' };
        }

        function setUserCedulaError(msg) {
            const el = document.getElementById('user-cedula-error');
            if (!el) return;
            if (msg) {
                el.textContent = msg;
                el.classList.remove('hidden');
            } else {
                el.textContent = '';
                el.classList.add('hidden');
            }
        }

        document.getElementById('user-cedula-numero')?.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 8);
            setUserCedulaError('');
        });

        document.getElementById('btn-nuevo-usuario').addEventListener('click', () => {
            document.getElementById('modal-usuario-titulo').textContent = 'Nuevo Usuario';
            document.getElementById('form-usuario').reset();
            document.getElementById('user-id-original').value = '';
            document.getElementById('user-cedula-tipo').value = 'V';
            document.getElementById('user-cedula-numero').value = '';
            document.getElementById('user-cedula-tipo').disabled = false;
            document.getElementById('user-cedula-numero').disabled = false;
            setUserCedulaError('');
            document.getElementById('user-password-wrap').style.display = 'block';
            document.getElementById('user-nueva-password-wrap').style.display = 'none';
            document.getElementById('user-password').value = '';
            document.getElementById('user-password').required = true;
            document.getElementById('user-nueva-password').value = '';
            cargarMetadata();
            document.getElementById('modal-usuario').classList.add('active');
        });

        function editarUsuario(id) {
            fetch('ajax/admin_usuarios_controller.php?action=get_all').then(r => r.json()).then(d => {
                const u = (d.usuarios || []).find(x => x.user_identificacion === id);
                if (!u) return;
                document.getElementById('modal-usuario-titulo').textContent = 'Editar Usuario';
                document.getElementById('user-id-original').value = id;
                const idParts = parseUserIdentificacionForForm(u.user_identificacion || id);
                document.getElementById('user-cedula-tipo').value = idParts.tipo === 'E' ? 'E' : 'V';
                document.getElementById('user-cedula-numero').value = idParts.num;
                document.getElementById('user-cedula-tipo').disabled = true;
                document.getElementById('user-cedula-numero').disabled = true;
                setUserCedulaError('');
                document.getElementById('user-username').value = u.user_username || '';
                document.getElementById('user-nombres').value = u.user_nombres || '';
                document.getElementById('user-apellidos').value = u.user_apellidos || '';
                document.getElementById('user-estado').value = (u.user_estado || 'activo').toLowerCase();
                document.getElementById('user-password-wrap').style.display = 'none';
                document.getElementById('user-nueva-password-wrap').style.display = 'block';
                document.getElementById('user-password').required = false;
                document.getElementById('user-nueva-password').value = '';
                document.getElementById('user-nueva-password').required = false;
                const preset = {
                    rol_id: u.rol_id,
                    coordinacion_id: u.coordinacion_id != null && u.coordinacion_id !== '' ? u.coordinacion_id : null
                };
                cargarMetadata(function () {
                    document.getElementById('user-rol').value = String(u.rol_id != null ? u.rol_id : '');
                    const cid = u.coordinacion_id != null && u.coordinacion_id !== '' ? String(u.coordinacion_id) : '_none_';
                    document.getElementById('user-coordinacion').value = cid;
                    document.getElementById('modal-usuario').classList.add('active');
                }, preset);
            });
        }

        function eliminarUsuario(id, nombre) {
            Swal.fire({ title: '¿Eliminar usuario?', text: nombre || id, icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444' }).then(res => {
                if (res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('user_identificacion', id);
                    fetch('ajax/admin_usuarios_controller.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                        toast(d.success ? 'success' : 'error', d.message || 'Error');
                        if (d.success) cargarUsuarios();
                    });
                }
            });
        }

        function cerrarModalUsuario() { document.getElementById('modal-usuario').classList.remove('active'); }

        document.getElementById('form-usuario').addEventListener('submit', function(e) {
            e.preventDefault();
            const orig = document.getElementById('user-id-original').value;
            const nuevaPwd = document.getElementById('user-nueva-password').value.trim();
            if (orig && nuevaPwd && nuevaPwd.length < 6) {
                toast('error', 'La contraseña debe tener al menos 6 caracteres');
                return;
            }
            let userIdentificacion = orig;
            if (!orig) {
                const tipo = document.getElementById('user-cedula-tipo').value;
                const num = document.getElementById('user-cedula-numero').value.replace(/\D/g, '').slice(0, 8);
                if (!num) {
                    setUserCedulaError('Ingrese el número de cédula (hasta 8 dígitos).');
                    toast('error', 'Ingrese el número de cédula');
                    return;
                }
                if (num.length > 8) {
                    setUserCedulaError('Máximo 8 dígitos.');
                    return;
                }
                userIdentificacion = tipo + '-' + num;
                setUserCedulaError('');
            }
            const fd = new FormData();
            fd.append('action', orig ? 'update' : 'create');
            fd.append('user_identificacion', userIdentificacion);
            fd.append('user_username', document.getElementById('user-username').value);
            fd.append('user_nombres', document.getElementById('user-nombres').value);
            fd.append('user_apellidos', document.getElementById('user-apellidos').value);
            fd.append('rol_id', document.getElementById('user-rol').value);
            fd.append('coordinacion_id', document.getElementById('user-coordinacion').value);
            fd.append('user_estado', document.getElementById('user-estado').value);
            if (!orig) fd.append('user_password', document.getElementById('user-password').value);
            if (orig && nuevaPwd) fd.append('nueva_password', nuevaPwd);
            fetch('ajax/admin_usuarios_controller.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (!d.success && (d.error || d.message)) console.error('Registro falló:', d.error || d.message);
                    if (d.success) {
                        cerrarModalUsuario();
                        cargarUsuarios();
                        if (orig) {
                            Swal.fire({ icon: 'success', title: 'Usuario actualizado', text: d.message || 'Los cambios se guardaron correctamente.' });
                        } else {
                            toast('success', d.message || 'Usuario creado');
                        }
                    } else {
                        toast('error', d.message || d.error || 'Error');
                    }
                })
                .catch(err => { console.error('Form submit:', err); toast('error', 'Error de red o respuesta inválida'); });
        });

        document.getElementById('filtro-nombre').addEventListener('input', () => { clearTimeout(window._filtroTimer); window._filtroTimer = setTimeout(cargarUsuarios, 300); });
        document.getElementById('filtro-rol').addEventListener('change', cargarUsuarios);
        document.getElementById('filtro-coordinacion').addEventListener('change', cargarUsuarios);

        document.getElementById('filtro-ciudadano').addEventListener('input', () => { clearTimeout(window._filtroCiudadano); window._filtroCiudadano = setTimeout(cargarCiudadanos, 300); });

        document.getElementById('form-ciudadano').addEventListener('submit', function(e) {
            e.preventDefault();
            const cedulaTipo = document.getElementById('ciudadano-cedula-tipo').value;
            const cedulaNum = document.getElementById('ciudadano-cedula-numero').value.trim();
            if (!cedulaNum) {
                toast('error', 'Ingrese el número de cédula');
                return;
            }
            const fd = new FormData(this);
            fd.append('action', 'update');
            fd.set('ciudadano_identificacion_original', document.getElementById('ciudadano-id-original').value);
            fd.set('ciudadano_identificacion', cedulaTipo + '-' + cedulaNum);
            const telCod = document.getElementById('ciudadano-telefono-codigo').value;
            const telNum = document.getElementById('ciudadano-telefono-numero').value.trim();
            fd.set('ciudadano_telefono', telNum ? telCod + '-' + telNum : '');
            fd.set('ciudadano_tipo_identificacion', 'cedula');
            fetch('ajax/admin_ciudadanos_controller.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    toast(d.success ? 'success' : 'error', d.message || (d.error || 'Error'));
                    if (d.success) { cerrarModalCiudadano(); cargarCiudadanos(); }
                })
                .catch(err => { console.error(err); toast('error', 'Error de red'); });
        });

        function setBackupGenerandoUI(activo) {
            const ov = document.getElementById('backup-generando-overlay');
            const btn = document.getElementById('btn-generar-backup');
            if (ov) ov.classList.toggle('hidden', !activo);
            if (btn) btn.classList.toggle('opacity-60', !!activo);
        }

        document.getElementById('btn-generar-backup')?.addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            setBackupGenerandoUI(true);
            btn.innerHTML = '<i class="fas fa-spinner fa-spin text-2xl"></i><span>Generando…</span>';
            fetch('ajax/admin_backup_controller.php?action=generar')
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false;
                    setBackupGenerandoUI(false);
                    btn.innerHTML = '<i class="fas fa-database text-2xl"></i><span>Generar Respaldo Ahora</span>';
                    toast(d.success ? 'success' : 'error', d.message || 'Error');
                    if (d.success) {
                        cargarHistorialBackup();
                        if (d.archivo) window.open('ajax/admin_backup_controller.php?action=descargar&archivo=' + encodeURIComponent(d.archivo), '_blank');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    setBackupGenerandoUI(false);
                    btn.innerHTML = '<i class="fas fa-database text-2xl"></i><span>Generar Respaldo Ahora</span>';
                    toast('error', 'Error al generar respaldo');
                });
        });

        document.getElementById('backup-activo')?.addEventListener('change', function() {
            const lab = document.getElementById('backup-activo-label');
            if (lab) lab.textContent = this.checked ? 'On' : 'Off';
        });

        document.getElementById('btn-guardar-backup-config')?.addEventListener('click', function() {
            const fd = new FormData();
            fd.append('action', 'save_config');
            fd.append('hora', document.getElementById('backup-hora')?.value || '02:00');
            fd.append('activo', document.getElementById('backup-activo')?.checked ? '1' : '0');
            fetch('ajax/admin_backup_controller.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    toast(d.success ? 'success' : 'error', d.message || 'Error');
                    if (d.success) {
                        actualizarCacheRespaldoAutoDesdeConfig({
                            activado: !!document.getElementById('backup-activo')?.checked,
                            hora: document.getElementById('backup-hora')?.value || '02:00'
                        });
                    }
                })
                .catch(() => toast('error', 'Error de red'));
        });

        function cargarCiudadanos() {
            const nombre = document.getElementById('filtro-ciudadano').value.trim();
            const params = new URLSearchParams({ action: 'get_all' });
            if (nombre) params.set('nombre', nombre);
            fetch('ajax/admin_ciudadanos_controller.php?' + params).then(r => r.json()).then(d => {
                const tbody = document.getElementById('tabla-ciudadanos-body');
                tbody.innerHTML = '';
                const list = d.success ? (d.ciudadanos || []) : [];
                if (!d.success) {
                    tbody.innerHTML = `<tr><td colspan="8" class="px-6 py-8 text-center text-red-600">${d.message || 'Error al cargar'}</td></tr>`;
                    return;
                }
                if (!list.length) {
                    tbody.innerHTML = '<tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">Sin ciudadanos</td></tr>';
                    return;
                }
                list.forEach(c => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-gray-50 transition-colors';
                    const cneM = (typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto : (x) => (x == null ? '' : String(x)));
                    const fn = c.ciudadano_fecha_nacimiento ? new Date(c.ciudadano_fecha_nacimiento).toLocaleDateString('es-VE') : '-';
                    const idBadge = (c.ciudadano_identificacion || '').toUpperCase().startsWith('V-CNE') 
                        ? `<span class="font-mono">${c.ciudadano_identificacion || '-'}</span> <span class="ml-1 inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-800">Temporal</span>`
                        : `<span class="font-mono">${c.ciudadano_identificacion || '-'}</span>`;
                    const dirT = cneM(c.ciudadano_direccion || '-');
                    tr.innerHTML = `<td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${idBadge}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${cneM(c.ciudadano_nombres || '-')}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${cneM(c.ciudadano_apellidos || '-')}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${fn}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${c.ciudadano_telefono || '-'}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${c.ciudadano_email || '-'}</td><td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title="${dirT.replace(/"/g,'&quot;')}">${dirT}</td><td class="px-6 py-4 whitespace-nowrap"><button type="button" class="btn btn-secondary py-2 px-3 text-sm" title="Editar" data-id="${(c.ciudadano_identificacion||'').replace(/"/g,'&quot;')}"><i class="fas fa-pen text-blue-600"></i></button></td>`;
                    tr.querySelector('button').onclick = () => editarCiudadano(tr.querySelector('button').dataset.id);
                    tbody.appendChild(tr);
                });
            }).catch(() => { document.getElementById('tabla-ciudadanos-body').innerHTML = '<tr><td colspan="8" class="px-6 py-8 text-center text-red-500">Error de red</td></tr>'; });
        }

        let municipiosData = [];
        function editarCiudadano(id) {
            Promise.all([
                fetch('ajax/admin_ciudadanos_controller.php?action=get_ciudadano&ciudadano_identificacion=' + encodeURIComponent(id)).then(r => r.json()),
                fetch('ajax/admin_ciudadanos_controller.php?action=get_metadata').then(r => r.json())
            ]).then(([resC, resM]) => {
                const c = resC.success ? resC.ciudadano : null;
                if (!c) return;
                municipiosData = resM.success ? (resM.municipios || []) : [];
                const estados = resM.success ? (resM.estados || []) : [];
                document.getElementById('ciudadano-id-original').value = id;
                const idMatch = (id || '').match(/^([VE])\-?(.*)$/i);
                if (idMatch) {
                    document.getElementById('ciudadano-cedula-tipo').value = idMatch[1].toUpperCase();
                    document.getElementById('ciudadano-cedula-numero').value = (idMatch[2] || '').trim();
                } else {
                    document.getElementById('ciudadano-cedula-tipo').value = 'V';
                    document.getElementById('ciudadano-cedula-numero').value = (id || '').trim();
                }
                const tel = (c.ciudadano_telefono || '').split('-');
                if (tel.length >= 2) {
                    document.getElementById('ciudadano-telefono-codigo').value = tel[0];
                    document.getElementById('ciudadano-telefono-numero').value = tel[1];
                } else {
                    document.getElementById('ciudadano-telefono-codigo').value = '0412';
                    document.getElementById('ciudadano-telefono-numero').value = tel[0] || '';
                }
                document.getElementById('ciudadano-genero').value = c.ciudadano_genero || '';
                const cneMA = (typeof cneMayusCiudadanoTexto === 'function' ? cneMayusCiudadanoTexto : (x) => (x == null ? '' : String(x)));
                document.getElementById('ciudadano-nombres').value = cneMA(c.ciudadano_nombres || '');
                document.getElementById('ciudadano-apellidos').value = cneMA(c.ciudadano_apellidos || '');
                if (flatpickrCiudadanoFechaNacimiento) {
                    if (c.ciudadano_fecha_nacimiento) flatpickrCiudadanoFechaNacimiento.setDate(c.ciudadano_fecha_nacimiento);
                    else flatpickrCiudadanoFechaNacimiento.clear();
                } else document.getElementById('ciudadano-fecha-nacimiento').value = c.ciudadano_fecha_nacimiento || '';
                document.getElementById('ciudadano-email').value = c.ciudadano_email || '';
                document.getElementById('ciudadano-direccion').value = cneMA(c.ciudadano_direccion || '');
                document.getElementById('ciudadano-institucion').value = c.institucion_id || '_none_';
                document.getElementById('ciudadano-estado').innerHTML = '<option value="">Seleccione un estado</option>' + estados.map(e => `<option value="${e.estado_id}">${e.estado_nombre}</option>`).join('');
                document.getElementById('ciudadano-estado').value = c.estado_id || '';
                const muns = municipiosData.filter(m => m.estado_id == c.estado_id);
                document.getElementById('ciudadano-municipio').innerHTML = '<option value="">Seleccione un municipio</option>' + muns.map(m => `<option value="${m.municipio_id}">${m.municipio_nombre}</option>`).join('');
                document.getElementById('ciudadano-municipio').value = c.municipio_id || '';
                document.getElementById('modal-ciudadano').classList.add('active');
            });
        }
        document.getElementById('ciudadano-estado').addEventListener('change', function() {
            const eid = this.value;
            const sel = document.getElementById('ciudadano-municipio');
            const muns = eid ? municipiosData.filter(m => m.estado_id == eid) : [];
            sel.innerHTML = '<option value="">Seleccione un municipio</option>' + muns.map(m => `<option value="${m.municipio_id}">${m.municipio_nombre}</option>`).join('');
            sel.value = '';
        });

        function cerrarModalCiudadano() { document.getElementById('modal-ciudadano').classList.remove('active'); }

        /** Caché de la última config de respaldo auto leída/guardada (debe coincidir con BD para el temporizador). */
        function actualizarCacheRespaldoAutoDesdeConfig(cfg) {
            if (!cfg) return;
            let h = String(cfg.hora != null ? cfg.hora : '02:00').trim();
            if (h.length >= 5) h = h.slice(0, 5);
            window.__CNE_BACKUP_AUTO = { activado: !!cfg.activado, hora: h };
        }

        function syncRespaldoAutoDesdeServidor() {
            fetch('ajax/admin_backup_controller.php?action=get_config', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.config) actualizarCacheRespaldoAutoDesdeConfig(d.config);
                })
                .catch(() => {});
        }

        function actualizarIndicadorTelegram(du) {
            const el = document.getElementById('backup-indicador-telegram');
            if (!el) return;
            el.classList.remove('hidden');
            if (!du || du.configurado === false) {
                el.textContent = 'Sincronización Telegram: no configurado (edite config/telegram_config.php: bot_token, chat_id, nombre_instancia).';
                el.className = 'text-sm mb-3 rounded-lg px-3 py-2 bg-amber-50 border border-amber-100 text-amber-900';
                return;
            }
            if (du.ultimo_exito === true) {
                el.textContent = 'Sincronización Telegram: último envío correcto' + (du.ultimo_intento_fecha ? ' — ' + du.ultimo_intento_fecha : '') + (du.ultimo_archivo ? ' — ' + du.ultimo_archivo : '');
                el.className = 'text-sm mb-3 rounded-lg px-3 py-2 bg-emerald-50 border border-emerald-100 text-emerald-900';
                return;
            }
            if (du.ultimo_exito === false) {
                el.textContent = 'Sincronización Telegram: falló el último envío — ' + (du.ultimo_mensaje || '') + (du.ultimo_intento_fecha ? ' (' + du.ultimo_intento_fecha + ')' : '');
                el.className = 'text-sm mb-3 rounded-lg px-3 py-2 bg-red-50 border border-red-100 text-red-900';
                return;
            }
            el.textContent = 'Sincronización Telegram: pendiente del primer envío o sin intentos recientes.';
            el.className = 'text-sm mb-3 rounded-lg px-3 py-2 bg-gray-50 border border-gray-100 text-gray-700';
        }

        function cargarConfigBackup() {
            fetch('ajax/admin_backup_controller.php?action=get_config').then(r => r.json()).then(d => {
                if (!d.success) return;
                const cfg = d.config || {};
                actualizarCacheRespaldoAutoDesdeConfig(cfg);
                document.getElementById('backup-hora').value = cfg.hora || '02:00';
                const cb = document.getElementById('backup-activo');
                cb.checked = !!(cfg.activado !== undefined ? cfg.activado : cfg.activo);
                document.getElementById('backup-activo-label').textContent = cb.checked ? 'On' : 'Off';
                if (d.telegram_ultimo) actualizarIndicadorTelegram(d.telegram_ultimo);
            });
        }

        function cargarHistorialBackup() {
            fetch('ajax/admin_backup_controller.php?action=get_historial')
                .then(r => r.json())
                .then(d => {
                    const tbody = document.getElementById('tabla-backup-historial');
                    if (!tbody) return;
                    tbody.innerHTML = '';
                    if (d.telegram_ultimo) actualizarIndicadorTelegram(d.telegram_ultimo);
                    const list = d.success ? (d.historial || []) : [];
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-4 text-center text-gray-500">Sin respaldos registrados</td></tr>';
                        return;
                    }
                    list.forEach(h => {
                        const ok = (h.estado || '').toLowerCase() === 'completado';
                        const fn = (h.archivo && /^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/.test(String(h.archivo))) ? h.archivo : '';
                        const dl = ok && fn
                            ? '<a class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 font-medium text-sm" href="ajax/admin_backup_controller.php?action=descargar&archivo=' + encodeURIComponent(fn) + '"><i class="fas fa-download"></i> .sql</a>'
                            : '<span class="text-gray-400 text-sm">—</span>';
                        const tg = String(h.telegram || h.drive || '').toLowerCase();
                        let tgCell = '<span class="text-xs text-gray-400">—</span>';
                        const errTxt = (h.telegram_error != null && h.telegram_error !== '') ? String(h.telegram_error) : '';
                        const safeTip = errTxt.replace(/"/g, '&quot;').replace(/</g, '').replace(/\s+/g, ' ').substring(0, 400);
                        if (tg === 'ok') {
                            tgCell = '<span class="inline-flex items-center gap-1.5 text-sky-700" title="Enviado al grupo de Telegram"><i class="bi bi-telegram text-xl leading-none" aria-hidden="true"></i><span class="text-xs font-semibold">Enviado</span></span>';
                        } else if (tg === 'error') {
                            tgCell = '<span class="inline-flex items-center gap-1 text-red-700" title="' + (safeTip || 'Error de envío') + '"><i class="bi bi-exclamation-triangle text-lg" aria-hidden="true"></i><span class="text-xs">Error</span></span>';
                        } else if (tg === 'omitido') {
                            tgCell = '<span class="text-xs text-gray-500">N/A</span>';
                        }
                        const tr = document.createElement('tr');
                        tr.className = 'hover:bg-gray-50';
                        tr.innerHTML = '<td class="px-4 py-2 text-sm text-gray-700">' + escapeHtmlAdmin(h.fecha || '-') + '</td>' +
                            '<td class="px-4 py-2 text-sm text-gray-600">' + escapeHtmlAdmin(h.tamanio || '-') + '</td>' +
                            '<td class="px-4 py-2"><span class="status-badge ' + (ok ? 'status-activo' : 'status-inactivo') + '">' + escapeHtmlAdmin(h.estado || '-') + '</span></td>' +
                            '<td class="px-4 py-2">' + tgCell + '</td>' +
                            '<td class="px-4 py-2 text-center">' + dl + '</td>';
                        tbody.appendChild(tr);
                    });
                })
                .catch(() => {
                    const tbody = document.getElementById('tabla-backup-historial');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-4 text-center text-red-500">No se pudo cargar el historial</td></tr>';
                });
        }

        syncRespaldoAutoDesdeServidor();

        function __cneLogHoraClienteCaracas() {
            try {
                const s = new Intl.DateTimeFormat('en-CA', {
                    timeZone: 'America/Caracas',
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                    hour12: false
                }).format(new Date());
                console.log('[CNE Debug] Hora del navegador (referencia America/Caracas):', s);
            } catch (e) {
                console.log('[CNE Debug] Hora local navegador:', new Date().toString());
            }
        }

        function __cneProcesarJsonRespaldoAuto(text) {
            const raw = (text != null ? String(text) : '').trim();
            let d;
            try {
                d = JSON.parse(raw);
            } catch (e) {
                console.error('[CNE Debug] La respuesta NO es JSON válido (¿warning/notice de PHP, HTML de error o permisos?). Primeros 2000 caracteres:', raw.substring(0, 2000));
                return;
            }
            console.log('[CNE Debug] Respuesta completa respaldo auto (JSON):', d);
            if (d.success && d.ejecutado) {
                cargarHistorialBackup();
                toast('success', d.message || 'Respaldo automático completado');
            } else if (d.success === false && d.message) {
                console.warn('[CNE Debug] El servidor devolvió success:false —', d.message);
            }
        }

        setInterval(function() {
            console.log('[CNE Debug] Comprobando hora de respaldo...');
            if (!window.__CNE_BACKUP_AUTO || !window.__CNE_BACKUP_AUTO.activado) {
                console.log('[CNE Debug] Respaldo automático desactivado (última config conocida); no se llama al servidor.');
                return;
            }
            const hh = window.__CNE_BACKUP_AUTO.hora || '02:00';
            console.log('[CNE Debug] Respaldo automático ON; hora programada (caché UI):', hh, '— el servidor usa America/Caracas y acepta todo el minuto programado (00–59 s).');
            __cneLogHoraClienteCaracas();
            fetch('ajax/procesar_respaldo_automatico.php', { credentials: 'same-origin' })
                .then(function(r) { return r.text().then(function(t) { return { ok: r.ok, status: r.status, text: t }; }); })
                .then(function(res) {
                    if (!res.ok) {
                        console.error('[CNE Debug] HTTP', res.status, '— cuerpo (recorte):', (res.text || '').substring(0, 2000));
                        return;
                    }
                    __cneProcesarJsonRespaldoAuto(res.text);
                })
                .catch(function(err) {
                    console.error('[CNE Debug] Error de red al comprobar respaldo automático:', err);
                });
        }, 30000);

        function cargarCatalogos() {
            const coord = document.getElementById('cat-coordinacion').value;
            fetch('ajax/admin_catalogos_controller.php?action=get_tramites&coordinacion_id=' + coord).then(r => r.json()).then(d => {
                const list = d.success ? (d.tramites || []) : [];
                const div = document.getElementById('lista-tramites');
                div.innerHTML = '';
                list.forEach(t => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'w-full text-left px-3 py-2 rounded-lg hover:bg-blue-50 border border-gray-200 flex justify-between items-center group tramite-item';
                    btn.innerHTML = `<span>${t.tramite_nombre || '-'}</span><span class="opacity-0 group-hover:opacity-100"><i class="fas fa-pen text-blue-600 mr-2 cursor-pointer icon-edit"></i><i class="fas fa-trash text-red-600 cursor-pointer icon-delete"></i></span>`;
                    btn.onclick = () => seleccionarTramite(t);
                    btn.querySelector('.icon-edit').onclick = e => { e.stopPropagation(); editarTramite(t); };
                    btn.querySelector('.icon-delete').onclick = e => { e.stopPropagation(); eliminarTramite(t.tramite_id); };
                    div.appendChild(btn);
                });
            });
            document.getElementById('lista-requisitos').innerHTML = '';
            document.getElementById('lista-requisitos-header').classList.add('hidden');
            document.getElementById('btn-agregar-requisito').classList.add('hidden');
            tramiteSeleccionado = null;
        }

        function seleccionarTramite(t) {
            tramiteSeleccionado = t;
            document.getElementById('lista-requisitos-header').textContent = t.tramite_nombre || '';
            document.getElementById('lista-requisitos-header').classList.remove('hidden');
            document.getElementById('btn-agregar-requisito').classList.remove('hidden');
            document.getElementById('requisito-tramite-id').value = t.tramite_id;
            fetch('ajax/admin_catalogos_controller.php?action=get_requisitos&tramite_id=' + t.tramite_id).then(r => r.json()).then(d => {
                const list = d.success ? (d.requisitos || []) : [];
                const div = document.getElementById('lista-requisitos');
                div.innerHTML = '';
                list.forEach(r => {
                    const row = document.createElement('div');
                    row.className = 'flex justify-between items-center px-3 py-2 rounded-lg hover:bg-gray-50 border border-gray-100';
                    const nom = (r.requisito_nombre || '-').replace(/</g, '&lt;');
                    const esAsesoria = /^asesor[ií]a$/i.test((r.requisito_nombre || '').trim());
                    const spanCls = esAsesoria ? 'font-semibold text-blue-800' : '';
                    row.innerHTML = `<span class="${spanCls}">${nom}</span><span><i class="fas fa-pen text-blue-600 cursor-pointer mr-2 icon-req-edit"></i><i class="fas fa-trash text-red-600 cursor-pointer icon-req-delete"></i></span>`;
                    row.querySelector('.icon-req-edit').onclick = () => editarRequisito(r.requisito_id, r.requisito_nombre, r.requisito_activo);
                    row.querySelector('.icon-req-delete').onclick = () => eliminarRequisito(r.requisito_id);
                    div.appendChild(row);
                });
            });
        }

        document.getElementById('btn-cargar-tramites').addEventListener('click', cargarCatalogos);

        document.getElementById('btn-agregar-tramite').addEventListener('click', () => {
            document.getElementById('modal-tramite-titulo').textContent = 'Agregar Trámite';
            document.getElementById('tramite-id').value = '';
            document.getElementById('form-tramite').reset();
            cargarMetadata(function () {
                document.getElementById('modal-tramite').classList.add('active');
            });
        });

        function editarTramite(t) {
            if (!t) return;
            document.getElementById('modal-tramite-titulo').textContent = 'Editar Trámite';
            document.getElementById('tramite-id').value = t.tramite_id;
            document.getElementById('tramite-nombre').value = t.tramite_nombre || '';
            cargarMetadata(function () {
                const cid = t.coordinacion_id != null && t.coordinacion_id !== '' ? String(t.coordinacion_id) : '';
                document.getElementById('tramite-coordinacion').value = cid;
                document.getElementById('modal-tramite').classList.add('active');
            });
        }

        function eliminarTramite(id) {
            Swal.fire({ title: '¿Eliminar trámite?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444' }).then(res => {
                if (res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete_tramite');
                    fd.append('tramite_id', id);
                    fetch('ajax/admin_catalogos_controller.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                        toast(d.success ? 'success' : 'error', d.message || 'Error');
                        if (d.success) cargarCatalogos();
                    });
                }
            });
        }

        function cerrarModalTramite() { document.getElementById('modal-tramite').classList.remove('active'); }

        document.getElementById('form-tramite').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData();
            const tid = document.getElementById('tramite-id').value;
            fd.append('action', tid ? 'update_tramite' : 'create_tramite');
            if (tid) fd.append('tramite_id', tid);
            fd.append('tramite_nombre', document.getElementById('tramite-nombre').value);
            fd.append('coordinacion_id', document.getElementById('tramite-coordinacion').value || document.getElementById('cat-coordinacion').value);
            fetch('ajax/admin_catalogos_controller.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                toast(d.success ? 'success' : 'error', d.message || 'Error');
                if (d.success) { cerrarModalTramite(); cargarCatalogos(); }
            });
        });

        document.getElementById('btn-agregar-requisito').addEventListener('click', () => {
            if (!tramiteSeleccionado) return;
            document.getElementById('modal-requisito-titulo').textContent = 'Agregar Requisito';
            document.getElementById('requisito-id').value = '';
            document.getElementById('requisito-tramite-id').value = tramiteSeleccionado.tramite_id;
            document.getElementById('requisito-nombre').value = '';
            document.getElementById('requisito-activo').value = '1';
            document.getElementById('modal-requisito').classList.add('active');
        });

        function editarRequisito(id, nom, act) {
            document.getElementById('modal-requisito-titulo').textContent = 'Editar Requisito';
            document.getElementById('requisito-id').value = id;
            document.getElementById('requisito-tramite-id').value = tramiteSeleccionado.tramite_id;
            document.getElementById('requisito-nombre').value = nom || '';
            document.getElementById('requisito-activo').value = act == 1 ? '1' : '0';
            document.getElementById('modal-requisito').classList.add('active');
        }

        function eliminarRequisito(id) {
            Swal.fire({ title: '¿Eliminar requisito?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444' }).then(res => {
                if (res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete_requisito');
                    fd.append('requisito_id', id);
                    fetch('ajax/admin_catalogos_controller.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                        toast(d.success ? 'success' : 'error', d.message || 'Error');
                        if (d.success && tramiteSeleccionado) seleccionarTramite(tramiteSeleccionado);
                    });
                }
            });
        }

        function cerrarModalRequisito() { document.getElementById('modal-requisito').classList.remove('active'); }

        document.getElementById('form-requisito').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData();
            const rid = document.getElementById('requisito-id').value;
            fd.append('action', rid ? 'update_requisito' : 'create_requisito');
            if (rid) fd.append('requisito_id', rid);
            fd.append('tramite_id', document.getElementById('requisito-tramite-id').value);
            fd.append('requisito_nombre', document.getElementById('requisito-nombre').value);
            fd.append('requisito_activo', document.getElementById('requisito-activo').value);
            fetch('ajax/admin_catalogos_controller.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                toast(d.success ? 'success' : 'error', d.message || 'Error');
                if (d.success) { cerrarModalRequisito(); if (tramiteSeleccionado) seleccionarTramite(tramiteSeleccionado); }
            });
        }); 

        window.addEventListener('cne:realtime', function(ev) {
            const d = ev.detail && ev.detail.event;
            if (!d || !window.CNE_REALTIME || window.CNE_REALTIME.enabled === false) return;
            if (d.type === 'notification_hint' && typeof fetchNotificacionesAdmin === 'function') {
                fetchNotificacionesAdmin();
            }
            if (d.type === 'auditoria') {
                const sid = d.solicitud_id != null ? Number(d.solicitud_id) : null;
                if (sid && window.__CNE_TIMELINE_SOL_ID === sid && typeof abrirTimelineAdmin === 'function') {
                    abrirTimelineAdmin(sid);
                }
                const box = document.getElementById('admin-buscar-sol-resultado');
                if (box && !box.classList.contains('hidden') && typeof ejecutarBuscarSolicitudesAdmin === 'function') {
                    ejecutarBuscarSolicitudesAdmin();
                }
            }
        });

        cargarMetadata();
        cargarUsuarios();
    </script>
</body>
</html>
