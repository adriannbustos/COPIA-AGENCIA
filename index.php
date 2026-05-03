<?php
// index.php - Área Investigaciones - Control y Verificación de Empresas de Seguridad
// Policía de la Provincia del Chubut

session_start();

// Configuración de seguridad básica
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// Variables institucionales
$institucion = "Policía de la Provincia del Chubut";
$area = "Área Investigaciones - Control y Verificación de Empresas de Seguridad";
$lema = "Al Servicio de la Comunidad";
$anio_fundacion = "1955";
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sistema de Control y Verificación de Empresas de Seguridad - Policía del Chubut">
    <meta name="author" content="Área Investigaciones - Policía del Chubut">
    <title><?php echo htmlspecialchars($area); ?> | <?php echo htmlspecialchars($institucion); ?></title>
    
    <!-- Fuentes institucionales -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    
    <!-- Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            /* Colores institucionales Policía del Chubut [[5]] */
            --chubut-blue: #0066CC;        /* Pantone 298 CVC - Azul institucional */
            --chubut-blue-dark: #003D7A;
            --chubut-gold: #FFD700;         /* Pantone Yellow 012 CVC - Dorado */
            --chubut-gold-dark: #B8860B;
            --white: #FFFFFF;
            --gray-light: #F8F9FA;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --black: #1A1A1A;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            --shadow-hover: 0 8px 30px rgba(0, 102, 204, 0.25);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, var(--chubut-blue-dark) 0%, var(--chubut-blue) 50%, #0052A3 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Patrón institucional de fondo */
        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(255, 215, 0, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 215, 0, 0.08) 0%, transparent 50%),
                repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,0.03) 10px, rgba(255,255,255,0.03) 20px);
            pointer-events: none;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
            z-index: 10;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Header institucional */
        .login-header {
            background: linear-gradient(135deg, var(--chubut-blue-dark), var(--chubut-blue));
            padding: 30px 25px;
            text-align: center;
            position: relative;
            border-bottom: 4px solid var(--chubut-gold);
        }

        .login-header::after {
            content: "";
            position: absolute;
            bottom: -4px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: var(--chubut-gold);
            border-radius: 2px;
        }

        .logo-container {
            margin-bottom: 15px;
        }

        .logo-institucional {
            width: 90px;
            height: 90px;
            background: var(--white);
            border: 3px solid var(--chubut-gold);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .logo-institucional i {
            font-size: 40px;
            color: var(--chubut-blue);
        }

        .logo-text {
            color: var(--white);
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            line-height: 1.3;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .logo-subtext {
            color: var(--chubut-gold);
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 5px;
            letter-spacing: 0.5px;
        }

        .lema-institucional {
            background: var(--chubut-gold);
            color: var(--chubut-blue-dark);
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Área específica */
        .area-badge {
            background: linear-gradient(135deg, var(--gray-dark), var(--black));
            color: var(--white);
            padding: 15px 20px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .area-badge i {
            color: var(--chubut-gold);
            margin-right: 8px;
        }

        /* Formulario */
        .login-form {
            padding: 35px 30px;
        }

        .form-title {
            text-align: center;
            color: var(--chubut-blue-dark);
            font-family: 'Montserrat', sans-serif;
            font-size: 1.4rem;
            margin-bottom: 25px;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 22px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--gray-dark);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-group label i {
            color: var(--chubut-blue);
            margin-right: 6px;
            width: 16px;
            text-align: center;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 2px solid #E0E6ED;
            border-radius: 10px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--gray-light);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--chubut-blue);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(0, 102, 204, 0.15);
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 1.1rem;
            transition: var(--transition);
        }

        .form-control:focus + .input-icon,
        .form-control:not(:placeholder-shown) + .input-icon {
            color: var(--chubut-blue);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 5px;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--chubut-blue);
        }

        /* Opciones adicionales */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--chubut-blue);
            cursor: pointer;
        }

        .remember-me label {
            font-size: 0.9rem;
            color: var(--gray);
            cursor: pointer;
            margin: 0;
        }

        .forgot-password {
            color: var(--chubut-blue);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .forgot-password:hover {
            color: var(--chubut-blue-dark);
            text-decoration: underline;
        }

        /* Botón de login */
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--chubut-blue), var(--chubut-blue-dark));
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(0, 102, 204, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            background: linear-gradient(135deg, var(--chubut-blue-dark), #002F5C);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login i {
            font-size: 1.1rem;
        }

        /* Seguridad */
        .security-notice {
            background: linear-gradient(135deg, #FFF8E1, #FFECB3);
            border-left: 4px solid var(--chubut-gold-dark);
            padding: 12px 15px;
            margin-top: 25px;
            border-radius: 0 8px 8px 0;
            font-size: 0.85rem;
            color: var(--gray-dark);
        }

        .security-notice i {
            color: var(--chubut-gold-dark);
            margin-right: 6px;
        }

        /* Footer */
        .login-footer {
            background: var(--gray-light);
            padding: 20px 25px;
            text-align: center;
            border-top: 1px solid #E0E6ED;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .login-footer strong {
            color: var(--chubut-blue-dark);
        }

        .footer-links {
            margin-top: 10px;
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: var(--chubut-blue);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-links a:hover {
            color: var(--chubut-blue-dark);
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 520px) {
            .login-container {
                max-width: 100%;
                margin: 10px;
            }
            
            .login-header {
                padding: 25px 20px;
            }
            
            .login-form {
                padding: 25px 20px;
            }
            
            .form-options {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Animación de carga */
        .loading {
            display: none;
        }

        .btn-login.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .btn-login.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Mensajes de error/éxito */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: none;
        }

        .alert-error {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }

        .alert-success {
            background: #D1FAE5;
            border: 1px solid #6EE7B7;
            color: #065F46;
        }

        .alert.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header Institucional -->
        <header class="login-header">
            <div class="logo-container">
                <div class="logo-institucional">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="logo-text">
                    <?php echo htmlspecialchars($institucion); ?>
                </div>
                <div class="logo-subtext">Desde <?php echo htmlspecialchars($anio_fundacion); ?></div>
            </div>
            <div class="lema-institucional">
                <i class="fas fa-star"></i> <?php echo htmlspecialchars($lema); ?>
            </div>
        </header>

        <!-- Badge del Área -->
        <div class="area-badge">
            <i class="fas fa-search"></i>
            <?php echo htmlspecialchars($area); ?>
        </div>

        <!-- Formulario de Login -->
        <main class="login-form">
            <h2 class="form-title">
                <i class="fas fa-lock"></i> Acceso al Sistema
            </h2>

            <!-- Contenedor de alertas -->
            <div id="alertContainer" class="alert"></div>

            <form id="loginForm" action="login.php" method="POST">
                <!-- Token CSRF -->
                <input type="hidden" name="csrf_token" value="<?php echo bin2hex(random_bytes(32)); ?>">
                
                <!-- Legajo/Usuario -->
                <div class="form-group">
                    <label for="usuario">
                        <i class="fas fa-id-badge"></i> Legajo o Usuario
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="usuario" 
                            name="usuario" 
                            class="form-control" 
                            placeholder="Ingrese su legajo institucional"
                            autocomplete="username"
                            required
                            minlength="4"
                            maxlength="20"
                        >
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <!-- Contraseña -->
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-key"></i> Contraseña
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="••••••••"
                            autocomplete="current-password"
                            required
                            minlength="8"
                        >
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Mostrar contraseña">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Opciones -->
                <div class="form-options">
                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Recordar sesión</label>
                    </div>
                    <a href="recuperar_acceso.php" class="forgot-password">
                        <i class="fas fa-question-circle"></i> ¿Olvidó su contraseña?
                    </a>
                </div>

                <!-- Botón de acceso -->
                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Ingresar al Sistema</span>
                </button>
            </form>

            <!-- Aviso de seguridad -->
            <div class="security-notice">
                <i class="fas fa-shield-alt"></i>
                <strong>Acceso Restringido:</strong> Este sistema es exclusivo para personal autorizado de la Policía del Chubut. Todo intento de acceso no autorizado será registrado y denunciado conforme a la Ley 11.483.
            </div>
        </main>

        <!-- Footer Institucional -->
        <footer class="login-footer">
            <p>
                <strong>Sistema de Gestión - Área Investigaciones</strong><br>
                Policía de la Provincia del Chubut &copy; <?php echo date('Y'); ?>
            </p>
            <div class="footer-links">
                <a href="https://www.policia.chubut.gov.ar" target="_blank" rel="noopener">
                    <i class="fas fa-globe"></i> Sitio Institucional
                </a>
                <a href="soporte.php">
                    <i class="fas fa-headset"></i> Soporte Técnico
                </a>
                <a href="terminos.php">
                    <i class="fas fa-file-contract"></i> Términos de Uso
                </a>
            </div>
        </footer>
    </div>

    <!-- Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const btnLogin = document.getElementById('btnLogin');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const alertContainer = document.getElementById('alertContainer');

            // Toggle visibilidad de contraseña
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.type === 'password' ? 'text' : 'password';
                passwordInput.type = type;
                this.querySelector('i').className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
            });

            // Validación del formulario
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const usuario = document.getElementById('usuario').value.trim();
                const password = passwordInput.value;
                
                // Validaciones básicas
                if (usuario.length < 4) {
                    showAlert('El legajo/usuario debe tener al menos 4 caracteres.', 'error');
                    return;
                }
                
                if (password.length < 8) {
                    showAlert('La contraseña debe tener al menos 8 caracteres.', 'error');
                    return;
                }

                // Efecto de carga
                btnLogin.classList.add('loading');
                btnLogin.querySelector('span').textContent = 'Verificando...';

                // Simulación de envío (reemplazar con fetch real)
                setTimeout(() => {
                    // Aquí iría la llamada real: fetch('login.php', { method: 'POST', body: new FormData(form) })
                    
                    // Para demostración:
                    showAlert('Credenciales verificadas. Redirigiendo...', 'success');
                    
                    setTimeout(() => {
                        // window.location.href = 'dashboard.php';
                        btnLogin.classList.remove('loading');
                        btnLogin.querySelector('span').textContent = 'Ingresar al Sistema';
                    }, 1500);
                }, 1200);
            });

            // Función para mostrar alertas
            function showAlert(message, type) {
                alertContainer.textContent = message;
                alertContainer.className = `alert alert-${type} show`;
                
                if (type === 'error') {
                    setTimeout(() => {
                        alertContainer.classList.remove('show');
                    }, 5000);
                }
            }

            // Accesibilidad: Enter en campos
            document.querySelectorAll('.form-control').forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        form.requestSubmit();
                    }
                });
            });

            // Prevención de autocompletado inseguro en campos sensibles
            if (window.chrome) {
                passwordInput.setAttribute('autocomplete', 'new-password');
                setTimeout(() => {
                    passwordInput.setAttribute('autocomplete', 'current-password');
                }, 100);
            }
        });
    </script>
</body>
</html>