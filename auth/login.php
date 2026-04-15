<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $db = getDB();
    
    // Buscar usuario - Incluir área en la consulta
    $stmt = $db->prepare("
        SELECT 
            u.*, 
            r.rol_nombre as rol_nombre,
            a.coordinacion_nombre as coordinacion_nombre
        FROM usuarios u 
        JOIN roles r ON u.rol_id = r.rol_id 
        LEFT JOIN coordinacion a ON u.coordinacion_id = a.coordinacion_id
        WHERE u.user_username = :username 
        AND u.user_estado = 'activo'
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Verificar contraseña
        if (password_verify($password, $user['user_password_hash'])) {
            // Crear sesión
            $_SESSION['user_id'] = $user['user_identificacion']; 
            $_SESSION['username'] = $user['user_username'];
            $_SESSION['rol'] = $user['rol_nombre'];
            $_SESSION['rol_id'] = $user['rol_id'];
            $_SESSION['nombre_completo'] = $user['user_nombres'] . ' ' . $user['user_apellidos'];
            $_SESSION['acoordinacion_id'] = $user['coordinacion_id'];
            $_SESSION['coordinacion_id'] = $user['coordinacion_id'];
            $_SESSION['coordinacion_nombre'] = $user['coordinacion_nombre'] ?? '';
            
            // Registrar o actualizar sesión activa (PERSISTENTE)
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("
                INSERT INTO sesiones_activas (usuario_id, sesion_token, sesion_ip_address, sesion_user_agent, sesion_ultima_actividad) 
                VALUES (:usuario_id, :token, :ip, :agent, NOW())
                ON DUPLICATE KEY UPDATE 
                    sesion_token = :token2, 
                    sesion_ip_address = :ip2, 
                    sesion_user_agent = :agent2, 
                    sesion_ultima_actividad = NOW()
            ");
            $stmt->execute([
                ':usuario_id' => $user['user_identificacion'],
                ':token' => $token,
                ':token2' => $token,
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':ip2' => $_SERVER['REMOTE_ADDR'],
                ':agent' => $_SERVER['HTTP_USER_AGENT'],
                ':agent2' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Redirigir según rol_id (estable)
            switch ((int)$user['rol_id']) {
                case 1: // Atención al Ciudadano
                    header('Location: ../dashboard_entrada.php'); 
                    break;
                case 2: // Funcionario
                    header('Location: ../dashboard_empleado.php'); 
                    break;
                case 3: // Coordinador
                    header('Location: ../dashboard_coordinador.php'); 
                    break;
                case 4: // Director
                    header('Location: ../dashboard_director.php'); 
                    break;
                case 5: // Admin
                    header('Location: ../dashboard_admin.php'); 
                    break;
                default: 
                    header('Location: ../dashboard_entrada.php');
            }
            exit();
        } else {
            // Contraseña incorrecta
            $error = "Usuario o contraseña incorrectos";
        }
    } else {
        // Usuario no encontrado
        $error = "Usuario o contraseña incorrectos";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oficina Regional Electoral de Portuguesa - Sistema Automatizado para la Atención al Ciudadano</title>
    <link rel="icon" href="../recursos/icon.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome en CSS: los <i> no se sustituyen por SVG, así classList en el icono del toggle funciona -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        .gota-mask { clip-path: url(#drop-mask); }
        body, html { height: 100%; margin: 0; overflow: hidden; background-color: white; }
        @media (max-width: 1024px) {
            body, html { overflow-y: auto; }
        }
        
        /* Animación para mensaje de error */
        .shake {
            animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both;
        }
        
        @keyframes shake {
            10%, 90% { transform: translate3d(-1px, 0, 0); }
            20%, 80% { transform: translate3d(2px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-2px, 0, 0); }
            40%, 60% { transform: translate3d(2px, 0, 0); }
        }
    </style>
</head>
<body class="font-sans antialiased">
    <div class="flex h-screen w-full">
        <div class="hidden lg:block relative lg:w-[60%] xl:w-[65%] h-full bg-blue-700 gota-mask z-10">
            <img src="../recursos/1-4.jpeg" alt="fondo" class="w-full h-full object-cover object-left">
            <div class="absolute inset-0 bg-gradient-to-r from-transparent via-blue-900/10 to-blue-700/40"></div>
        </div>

        <div class="w-full lg:w-[40%] xl:w-[35%] flex flex-col justify-center items-center px-6 sm:px-12 md:px-20 bg-white z-0">
            <div class="w-full max-w-sm flex flex-col items-center">
                <img src="../recursos/Logo.png" alt="CNE Logo" class="h-20 md:h-24 mb-6">
                <h2 class="text-xs md:text-sm font-bold text-gray-800 text-center mb-10 md:mb-14 uppercase tracking-tight px-4">
                    Oficina Regional Electoral de Portuguesa
                    <span class="mt-10 block text-[10px] md:text-xl font-bold normal-case mt-1">Sistema Automatizado para la Atención al Ciudadano</span>
                </h2>
                
                <?php if (isset($error)): ?>
                    <div class="w-full bg-red-50 border-l-4 border-red-500 text-red-700 p-3 mb-6 text-xs shake" role="alert" id="error-message">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form action="" method="POST" class="w-full space-y-8" id="login-form">
                    <div class="group">
                        <label class="block text-blue-600 text-[10px] font-black uppercase tracking-[0.15em] mb-1">Usuario</label>
                        <input type="text" name="username" required
                               class="w-full border-b border-gray-200 py-2 focus:outline-none focus:border-blue-600 transition-all bg-transparent text-gray-600 text-sm placeholder-gray-300"
                               placeholder="Ingrese su usuario"
                               id="username-input">
                    </div>
                    
                    <div class="group">
                        <label class="block text-blue-600 text-[10px] font-black uppercase tracking-[0.15em] mb-1">Contraseña</label>
                        <div class="relative">
                            <input type="password" name="password" required
                                   class="w-full border-b border-gray-200 py-2 pr-11 focus:outline-none focus:border-blue-600 transition-all bg-transparent text-gray-600 text-sm"
                                   placeholder="••••••••"
                                   id="password-input"
                                   autocomplete="current-password">
                            <button type="button" id="togglePassword"
                                    class="absolute inset-y-0 right-0 flex items-center justify-center w-10 cursor-pointer text-gray-400 hover:text-gray-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#2b6df1] focus-visible:ring-offset-0 rounded transition-colors duration-200"
                                    aria-label="Mostrar contraseña"
                                    aria-pressed="false">
                                <i class="fas fa-eye-slash text-gray-400 text-[1.05rem] leading-none transition-colors duration-200" aria-hidden="true"></i>
                            </button>
                            <!-- Asegura que Tailwind CDN genere text-blue-600 al usarlo en el icono vía JS -->
                            <span class="pointer-events-none absolute h-0 w-0 overflow-hidden text-blue-600" aria-hidden="true"></span>
                        </div>
                    </div>

                    <br/>

                    <button type="submit" 
                            class="w-full bg-[#2b6df1] text-white font-bold py-4 rounded-2xl shadow-lg shadow-blue-100 hover:bg-blue-700 active:scale-95 transition-all duration-300 uppercase tracking-widest text-xs"
                            id="submit-btn">
                        Iniciar Sesión
                    </button>
                  
                </form>
            </div>
            
            <div class="mt-12 lg:hidden text-center opacity-40 text-[9px] text-gray-400">
                <p>© 2026 Consejo Nacional Electoral</p>
            </div>
        </div>
    </div>

    <svg width="0" height="0" class="absolute">
        <defs>
            <clipPath id="drop-mask" clipPathUnits="objectBoundingBox">
                <path d="M 0,0 L 0.82,0 C 0.98,0.3 0.98,0.7 0.82,1 L 0,1 Z"></path>
            </clipPath>
        </defs>
    </svg>
    
    <script>
        // Validación del formulario
        document.getElementById('login-form')?.addEventListener('submit', function(e) {
            const username = document.getElementById('username-input').value.trim();
            const password = document.getElementById('password-input').value.trim();
            const submitBtn = document.getElementById('submit-btn');
            
            if (!username || !password) {
                e.preventDefault();
                mostrarError('Por favor, complete todos los campos');
                return;
            }
            
            // Mostrar loading en el botón
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Verificando...';
            submitBtn.disabled = true;
        });
        
        function mostrarError(mensaje) {
            let errorDiv = document.getElementById('error-message');
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.id = 'error-message';
                errorDiv.className = 'w-full bg-red-50 border-l-4 border-red-500 text-red-700 p-3 mb-6 text-xs shake';
                errorDiv.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <p>${mensaje}</p>
                    </div>
                `;
                document.querySelector('.w-full.max-w-sm').insertBefore(errorDiv, document.querySelector('form'));
            } else {
                errorDiv.querySelector('p').textContent = mensaje;
                errorDiv.classList.remove('shake');
                void errorDiv.offsetWidth; // Trigger reflow
                errorDiv.classList.add('shake');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-rellenar para pruebas (solo desarrollo)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('test') === '1') {
                document.getElementById('username-input').value = 'entrada1';
                document.getElementById('password-input').value = 'password';
            }

            // Toggle contraseña: icono real en <i> (FA por CSS). Sincronizar type + clases con classList.replace
            const passwordInput = document.getElementById('password-input');
            const toggleBtn = document.getElementById('togglePassword');
            const toggleIcon = toggleBtn ? toggleBtn.querySelector('i') : null;
            if (passwordInput && toggleBtn && toggleIcon) {
                function syncToggleFromInputType() {
                    const isText = passwordInput.getAttribute('type') === 'text';
                    if (isText) {
                        toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
                        toggleIcon.classList.replace('text-gray-400', 'text-blue-600');
                        toggleBtn.setAttribute('aria-label', 'Ocultar contraseña');
                        toggleBtn.setAttribute('aria-pressed', 'true');
                    } else {
                        toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
                        toggleIcon.classList.replace('text-blue-600', 'text-gray-400');
                        toggleBtn.setAttribute('aria-label', 'Mostrar contraseña');
                        toggleBtn.setAttribute('aria-pressed', 'false');
                    }
                }
                toggleBtn.addEventListener('click', function() {
                    const show = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', show ? 'text' : 'password');
                    syncToggleFromInputType();
                });
            }
        });
    </script>
</body>
</html>