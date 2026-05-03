<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/auditoria_func.php';

// Verificar autenticación (cualquier rol puede acceder)
if (!$auth->isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'panel_usuario';
$user = $auth->getCurrentUser();
$conn = getDBConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ==================== PROCESAR CAMBIO DE CONTRASEÑA ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_password'])) {
    try {
        $password_actual = $_POST['password_actual'] ?? '';
        $password_nuevo = $_POST['password_nuevo'] ?? '';
        $password_confirmar = $_POST['password_confirmar'] ?? '';
        $usuario_id = $user['id'];

        // Validar campos
        if (empty($password_actual) || empty($password_nuevo) || empty($password_confirmar)) {
            throw new Exception('Todos los campos son obligatorios');
        }

        // Validar longitud mínima
        if (strlen($password_nuevo) < 8) {
            throw new Exception('La nueva contraseña debe tener al menos 8 caracteres');
        }

        // Validar que las contraseñas nuevas coincidan
        if ($password_nuevo !== $password_confirmar) {
            throw new Exception('Las nuevas contraseñas no coinciden');
        }

        // Verificar contraseña actual
        $stmt = $conn->prepare("SELECT password FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario_data || !password_verify($password_actual, $usuario_data['password'])) {
            throw new Exception('La contraseña actual es incorrecta');
        }

        // Validar que la nueva contraseña sea diferente a la actual
        if (password_verify($password_nuevo, $usuario_data['password'])) {
            throw new Exception('La nueva contraseña debe ser diferente a la actual');
        }

        // Hashear nueva contraseña
        $hashed_password = password_hash($password_nuevo, PASSWORD_BCRYPT);

        // Actualizar contraseña
        $stmt = $conn->prepare("
            UPDATE usuarios SET 
            password = ?,
            updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$hashed_password, $usuario_id]);

        // Registrar auditoría
        logAuditoria($conn, 'password_cambiado', 'usuarios', $usuario_id, [
            'username' => $user['username'],
            'accion' => 'cambio_password'
        ]);

        $_SESSION['success'] = 'Contraseña cambiada correctamente';
        header('Location: panel_usuario.php');
        exit;

    } catch(Exception $e) {
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        error_log("ERROR cambiando contraseña: " . $e->getMessage());
    }
}

// ==================== OBTENER INFORMACIÓN DEL USUARIO ====================
try {
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.rol, u.empresa_id, u.ultimo_login, u.created_at,
               e.nombre as empresa_nombre
        FROM usuarios u
        LEFT JOIN empresas e ON u.empresa_id = e.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user['id']]);
    $usuario_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $usuario_info = [];
    $error = "Error cargando información: " . htmlspecialchars($e->getMessage());
}

// Roles disponibles para mostrar
$roles_disponibles = [
    'administrador' => ['nombre' => 'Administrador', 'icono' => 'fa-user-shield', 'color' => '#e74c3c'],
    'carga' => ['nombre' => 'Carga', 'icono' => 'fa-database', 'color' => '#f39c12'],
    'operador' => ['nombre' => 'Operador', 'icono' => 'fa-user-cog', 'color' => '#3498db'],
    'auditor' => ['nombre' => 'Auditor', 'icono' => 'fa-clipboard-check', 'color' => '#9b59b6'],
    'empresa' => ['nombre' => 'Empresa', 'icono' => 'fa-building', 'color' => '#27ae60'],
    'supervisor' => ['nombre' => 'Supervisor', 'icono' => 'fa-user-tie', 'color' => '#f39c12'],
    'usuario' => ['nombre' => 'Usuario', 'icono' => 'fa-user', 'color' => '#3498db']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<meta name="theme-color" content="#4361ee">
<title>Mi Perfil - Sistema de Seguridad</title>
<!-- Mantener CDN para Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Pero usar locales para Bootstrap y SweetAlert2 -->
<link href="../css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
:root {
    --primary-color: #4361ee;
    --secondary-color: #3a0ca3;
    --sidebar-width: 280px;
    --header-height: 80px;
    --header-height-mobile: 65px;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body {
    padding-top: var(--header-height);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
    min-height: 100vh;
    overflow-x: hidden;
}
.dashboard {
    display: flex;
    min-height: calc(100vh - var(--header-height));
    padding-top: 20px;
}
.main-content {
    padding: 30px 40px 40px 40px !important;
    margin-left: var(--sidebar-width) !important;
    width: calc(100% - var(--sidebar-width));
    transition: margin-left 0.35s ease;
}
/* Profile Card */
.profile-card-modern {
    background: white;
    border-radius: 24px;
    padding: 40px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
    border: 1px solid rgba(0, 0, 0, 0.05);
    margin-bottom: 30px;
}
.profile-header {
    display: flex;
    align-items: center;
    gap: 25px;
    padding-bottom: 30px;
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 30px;
}
.profile-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2.5rem;
    box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
}
.profile-info h2 {
    font-weight: 800;
    font-size: 1.8rem;
    color: #2c3e50;
    margin: 0 0 5px 0;
}
.profile-info .username {
    color: #4361ee;
    font-weight: 600;
    font-size: 1.1rem;
}
.profile-info .email {
    color: #718096;
    font-size: 0.95rem;
    margin-top: 5px;
}
/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.info-item {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    padding: 20px;
    border-radius: 16px;
    border: 1px solid rgba(0, 0, 0, 0.05);
}
.info-item .label {
    font-size: 0.85rem;
    color: #718096;
    font-weight: 600;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-item .value {
    font-size: 1.1rem;
    color: #2c3e50;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
/* Password Section */
.password-section-modern {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
    border-radius: 24px;
    padding: 35px 40px;
    box-shadow: 0 10px 40px rgba(67, 97, 238, 0.12);
    border: 1px solid rgba(67, 97, 238, 0.1);
}
.password-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid rgba(67, 97, 238, 0.15);
}
.password-header .icon-box {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.3rem;
}
.password-header h3 {
    font-weight: 800;
    font-size: 1.4rem;
    color: #2c3e50;
    margin: 0;
}
.form-label-modern {
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-label-modern i { color: #4361ee; width: 20px; text-align: center; }
.form-label-modern .required { color: #e53e3e; margin-left: auto; }
.form-control-modern {
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 18px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}
.form-control-modern:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
    outline: none;
}
.password-strength {
    margin-top: 10px;
    padding: 12px;
    border-radius: 12px;
    background: #f8f9fa;
    font-size: 0.85rem;
}
.password-strength .strength-bar {
    height: 6px;
    border-radius: 3px;
    background: #e2e8f0;
    margin-top: 8px;
    overflow: hidden;
}
.password-strength .strength-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s ease, background 0.3s ease;
}
.btn-save-modern {
    background: linear-gradient(135deg, #4361ee, #3a0ca3);
    border: none;
    color: white;
    padding: 14px 32px;
    border-radius: 14px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    box-shadow: 0 6px 20px rgba(67, 97, 238, 0.35);
}
.btn-save-modern:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(67, 97, 238, 0.5);
}
.btn-reset-modern {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border: 2px solid #e2e8f0;
    color: #4a5568;
    padding: 14px 28px;
    border-radius: 14px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
}
.btn-reset-modern:hover {
    background: linear-gradient(135deg, #e9ecef, #dee2e6);
    transform: translateY(-2px);
}
/* Role Badge */
.role-badge-display {
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
/* Alerts */
.alert-custom {
    border-radius: 16px;
    padding: 15px 20px;
    border: none;
    font-weight: 600;
}
/* Responsive */
@media (max-width: 991px) {
    :root { --sidebar-width: 0px; }
    .main-content { margin-left: 0 !important; width: 100%; padding: 20px 25px !important; }
    .profile-header { flex-direction: column; text-align: center; }
    .profile-avatar { margin: 0 auto; }
}
@media (max-width: 767px) {
    body { padding-top: var(--header-height-mobile); }
    :root { --header-height: var(--header-height-mobile); }
    .password-section-modern { padding: 25px 20px; }
    .info-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<?php $page_title = 'Mi Perfil'; include '../includes/header.php'; ?>
<div class="dashboard">
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php if ($success): ?>
        <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- PERFIL DE USUARIO -->
        <div class="profile-card-modern">
            <div class="profile-header">
                <div class="profile-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($usuario_info['username'] ?? $user['username']); ?></h2>
                    <div class="username">@<?php echo htmlspecialchars($usuario_info['username'] ?? $user['username']); ?></div>
                    <div class="email"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($usuario_info['email'] ?? $user['email']); ?></div>
                </div>
                <div class="ms-auto">
                    <?php
                    $role = $usuario_info['rol'] ?? 'operador';
                    $role_info = $roles_disponibles[$role] ?? $roles_disponibles['operador'];
                    ?>
                    <span class="role-badge-display" style="background: <?php echo $role_info['color']; ?>; color: white;">
                        <i class="fas <?php echo $role_info['icono']; ?>"></i>
                        <?php echo $role_info['nombre']; ?>
                    </span>
                </div>
            </div>

            <!-- INFORMACIÓN DEL USUARIO (Solo lectura) -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="label"><i class="fas fa-user"></i> Nombre de Usuario</div>
                    <div class="value"><?php echo htmlspecialchars($usuario_info['username'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="value"><?php echo htmlspecialchars($usuario_info['email'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fas fa-building"></i> Empresa</div>
                    <div class="value">
                        <?php if (!empty($usuario_info['empresa_nombre'])): ?>
                            <i class="fas fa-building"></i><?php echo htmlspecialchars($usuario_info['empresa_nombre']); ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fas fa-clock"></i> Último Login</div>
                    <div class="value">
                        <?php
                        $ultimo_login = $usuario_info['ultimo_login'] ?? null;
                        if ($ultimo_login && $ultimo_login != '0000-00-00 00:00:00') {
                            echo '<i class="fas fa-clock"></i>' . date('d/m/Y H:i', strtotime($ultimo_login));
                        } else {
                            echo '<span class="text-muted">Nunca</span>';
                        }
                        ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fas fa-calendar"></i> Fecha de Registro</div>
                    <div class="value">
                        <i class="fas fa-calendar"></i>
                        <?php echo !empty($usuario_info['created_at']) ? date('d/m/Y', strtotime($usuario_info['created_at'])) : 'N/A'; ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="label"><i class="fas fa-shield-alt"></i> Rol</div>
                    <div class="value"><?php echo $role_info['nombre']; ?></div>
                </div>
            </div>
        </div>

        <!-- CAMBIAR CONTRASEÑA -->
        <div class="password-section-modern">
            <div class="password-header">
                <div class="icon-box"><i class="fas fa-lock"></i></div>
                <h3>Cambiar Contraseña</h3>
            </div>

            <form method="POST" action="" id="formCambiarPassword">
                <input type="hidden" name="cambiar_password" value="1">
                
                <div class="row g-4">
                    <div class="col-md-12">
                        <label class="form-label-modern">
                            <i class="fas fa-key"></i>
                            Contraseña Actual
                            <span class="required">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="password_actual" class="form-control form-control-modern" 
                                   required placeholder="Ingrese su contraseña actual" id="passwordActual">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('passwordActual', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-modern">
                            <i class="fas fa-lock"></i>
                            Nueva Contraseña
                            <span class="required">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="password_nuevo" class="form-control form-control-modern" 
                                   required minlength="8" placeholder="Mínimo 8 caracteres" id="passwordNuevo"
                                   oninput="verificarFortalezaPassword(this.value)">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('passwordNuevo', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength">
                            <div>Fortaleza: <span id="strengthText">Sin ingresar</span></div>
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill" style="width: 0%; background: #e2e8f0;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-modern">
                            <i class="fas fa-lock"></i>
                            Confirmar Nueva Contraseña
                            <span class="required">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="password_confirmar" class="form-control form-control-modern" 
                                   required minlength="8" placeholder="Repita la nueva contraseña" id="passwordConfirmar"
                                   oninput="verificarCoincidencia()">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('passwordConfirmar', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text mt-2" id="coincidenciaMensaje"></div>
                    </div>
                </div>

                <div class="mt-4" style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn-save-modern">
                        <i class="fas fa-save"></i>
                        <span>Guardar Nueva Contraseña</span>
                    </button>
                    <button type="reset" class="btn-reset-modern" onclick="resetPasswordForm()">
                        <i class="fas fa-undo"></i>
                        <span>Limpiar</span>
                    </button>
                </div>

                <div class="mt-4 p-3" style="background: linear-gradient(135deg, #ebf4ff, #e6f0ff); border-radius: 12px; border: 1px solid rgba(67, 97, 238, 0.2);">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <i class="fas fa-info-circle" style="color: #4361ee; font-size: 1.2rem; margin-top: 2px;"></i>
                        <div style="font-size: 0.9rem; color: #4a5568;">
                            <strong>Requisitos de contraseña:</strong>
                            <ul class="mb-0 mt-2" style="padding-left: 20px;">
                                <li>Mínimo 8 caracteres</li>
                                <li>Se recomienda usar mayúsculas, minúsculas, números y símbolos</li>
                                <li>No puede ser igual a la contraseña actual</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../css/bootstrap.bundle.min.js"></script>
<script src="../css/sweetalert2.all.min.js"></script>
<script>
// Toggle visibilidad de contraseña
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Verificar fortaleza de contraseña
function verificarFortalezaPassword(password) {
    const strengthFill = document.getElementById('strengthFill');
    const strengthText = document.getElementById('strengthText');
    
    let strength = 0;
    let text = 'Débil';
    let color = '#e74c3c';
    
    if (password.length >= 8) strength += 25;
    if (password.length >= 12) strength += 15;
    if (/[A-Z]/.test(password)) strength += 20;
    if (/[a-z]/.test(password)) strength += 20;
    if (/[0-9]/.test(password)) strength += 10;
    if (/[^A-Za-z0-9]/.test(password)) strength += 10;
    
    if (strength >= 80) {
        text = 'Fuerte';
        color = '#27ae60';
    } else if (strength >= 60) {
        text = 'Buena';
        color = '#f39c12';
    } else if (strength >= 40) {
        text = 'Regular';
        color = '#f39c12';
    } else {
        text = 'Débil';
        color = '#e74c3c';
    }
    
    if (password.length === 0) {
        text = 'Sin ingresar';
        color = '#e2e8f0';
        strength = 0;
    }
    
    strengthFill.style.width = strength + '%';
    strengthFill.style.background = color;
    strengthText.textContent = text;
    strengthText.style.color = color;
}

// Verificar coincidencia de contraseñas
function verificarCoincidencia() {
    const nuevo = document.getElementById('passwordNuevo').value;
    const confirmar = document.getElementById('passwordConfirmar').value;
    const mensaje = document.getElementById('coincidenciaMensaje');
    
    if (confirmar.length === 0) {
        mensaje.innerHTML = '';
        mensaje.style.color = '';
        return;
    }
    
    if (nuevo === confirmar) {
        mensaje.innerHTML = '<i class="fas fa-check-circle text-success"></i> Las contraseñas coinciden';
        mensaje.style.color = '#27ae60';
    } else {
        mensaje.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Las contraseñas no coinciden';
        mensaje.style.color = '#e74c3c';
    }
}

// Resetear formulario
function resetPasswordForm() {
    document.getElementById('strengthFill').style.width = '0%';
    document.getElementById('strengthFill').style.background = '#e2e8f0';
    document.getElementById('strengthText').textContent = 'Sin ingresar';
    document.getElementById('strengthText').style.color = '#718096';
    document.getElementById('coincidenciaMensaje').innerHTML = '';
}

// Auto-cerrar alerts
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => new bootstrap.Alert(alert).close(), 5000);
});

// Confirmar antes de enviar
document.getElementById('formCambiarPassword')?.addEventListener('submit', function(e) {
    const nuevo = document.getElementById('passwordNuevo').value;
    const confirmar = document.getElementById('passwordConfirmar').value;
    
    if (nuevo !== confirmar) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Las nuevas contraseñas no coinciden'
        });
        return;
    }
    
    if (nuevo.length < 8) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Contraseña Débil',
            text: 'La contraseña debe tener al menos 8 caracteres'
        });
        return;
    }
});
</script>
</body>
</html>