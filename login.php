<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Cambiar a 1 si usas HTTPS
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
// A5: Generar nonce para CSP
$csp_nonce = bin2hex(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' nonce-{$csp_nonce} https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' ; connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;");
session_start();
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/auditoria_func.php';
// Crear tabla login_attempts si no existe
$conn = getDBConnection();
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(255) NOT NULL,
        fecha_intento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        exitoso TINYINT(1) NOT NULL DEFAULT 0
    )");
} catch (Exception $e) {
    error_log("Error creando tabla login_attempts: " . $e->getMessage());
}
// A2: Funciones para rate limiting
function verificarRateLimit($conn, $identifier, $maxIntentos = 5, $tiempoBloqueo = 900) {
try {
$stmt = $conn->prepare("SELECT COUNT(*) as intentos, MAX(fecha_intento) as ultimo_intento FROM login_attempts WHERE identifier = ? AND fecha_intento > DATE_SUB(NOW(), INTERVAL ? SECOND)");
$stmt->bindParam(1, $identifier, PDO::PARAM_STR);
$stmt->bindParam(2, $tiempoBloqueo, PDO::PARAM_INT);
$stmt->execute();
$resultado = $stmt->fetch(PDO::FETCH_ASSOC);
if ($resultado && $resultado['intentos'] >= $maxIntentos) {
$tiempoRestante = $tiempoBloqueo - (time() - strtotime($resultado['ultimo_intento']));
return ['bloqueado' => true, 'tiempo_restante' => max(0, $tiempoRestante)];
}
} catch (Exception $e) {
error_log("Error en verificarRateLimit: " . $e->getMessage());
}
return ['bloqueado' => false, 'tiempo_restante' => 0];
}
function registrarIntentoLogin($conn, $identifier, $exitoso) {
try {
$stmt = $conn->prepare("INSERT INTO login_attempts (identifier, fecha_intento, exitoso) VALUES (?, NOW(), ?)");
$stmt->bindParam(1, $identifier, PDO::PARAM_STR);
$stmt->bindParam(2, $exitoso, PDO::PARAM_BOOL);
$stmt->execute();
$stmtClean = $conn->prepare("DELETE FROM login_attempts WHERE fecha_intento < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmtClean->execute();
} catch (Exception $e) {
error_log("Error en registrarIntentoLogin: " . $e->getMessage());
}
}
$error = '';
$success = '';
if (empty($_SESSION['csrf_token'])) {
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['captcha_code'])) {
$_SESSION['captcha_code'] = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
$error = 'Error de seguridad. Recarga la página.';
$conn = getDBConnection();
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'CSRF invalido', 'username' => $_POST['username'] ?? '']);
} else {
$captcha_input = strtoupper(trim($_POST['captcha'] ?? ''));
$session_captcha = $_SESSION['captcha_code'] ?? '';
if (empty($session_captcha)) {
$error = 'CAPTCHA expirado. Recarga la página.';
$conn = getDBConnection();
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'CAPTCHA expirado']);
} elseif ($captcha_input !== $session_captcha) {
$error = 'Código de seguridad incorrecto.';
$conn = getDBConnection();
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'CAPTCHA incorrecto']);
} else {
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
if (empty($username) || empty($password)) {
$error = 'Complete todos los campos';
$conn = getDBConnection();
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'Campos vacíos']);
} else {
// A2: Verificar rate limiting antes de intentar login
$conn = getDBConnection();
$identifier = $_SERVER['REMOTE_ADDR'] . '|' . $username;
$rateLimit = verificarRateLimit($conn, $identifier);
if ($rateLimit['bloqueado']) {
$error = 'Demasiados intentos fallidos. Intente nuevamente en ' . ceil($rateLimit['tiempo_restante'] / 60) . ' minutos.';
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'Rate limit excedido', 'username' => $username]);
} elseif ($auth->login($username, $password)) {
$user = $auth->getCurrentUser();
// A2: Registrar intento exitoso
registrarIntentoLogin($conn, $identifier, true);
// Limpiar sesiones expiradas del usuario
$stmtClean = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
$stmtClean->bindParam(1, $user['id'], PDO::PARAM_INT);
$stmtClean->execute();
// Verificar si existe sesión activa
$stmt = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
$stmt->bindParam(1, $user['id'], PDO::PARAM_INT);
$stmt->execute();
if ($stmt->rowCount() > 0) {
// Forzar cierre de sesión anterior
$stmtKick = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ?");
$stmtKick->bindParam(1, $user['id'], PDO::PARAM_INT);
$stmtKick->execute();
logAuditoria($conn, 'login', 'sesion_forzada', $user['id'], ['razon' => 'Session kick automática']);
}
session_regenerate_id(true);
$_SESSION['session_id'] = session_id();
require_once 'config/session_manager.php';
registerUserSession($conn, $user['id'], session_id());
unset($_SESSION['captcha_code']);
$rol = strtolower($user['rol']);
logAuditoria($conn, 'login', 'acceso', $user['id'], ['rol' => $user['rol']]);
if ($rol === 'empresa') {
header('Location: empresa/dashboard.php');
exit;
} elseif ($rol === 'auditor') {
// A8: Validar que auditor/dashboard.php exista y contenga validación de sesión
$auditorDashboard = 'auditor/dashboard.php';
if (file_exists($auditorDashboard)) {
$contenido = file_get_contents($auditorDashboard);
if (strpos($contenido, 'requireValidSession()') !== false || strpos($contenido, 'verificarSesion') !== false || strpos($contenido, 'session_start()') !== false) {
header('Location: ' . $auditorDashboard);
exit;
} else {
$error = 'Error de configuración: El dashboard de auditor no contiene validación de sesión adecuada.';
logAuditoria($conn, 'login', 'error_config', $user['id'], ['razon' => 'Dashboard auditor sin validación']);
$auth->logout();
}
} else {
$error = 'Error de configuración: Dashboard de auditor no encontrado.';
logAuditoria($conn, 'login', 'error_config', $user['id'], ['razon' => 'Dashboard auditor no existe']);
$auth->logout();
}
} elseif (in_array($rol, ['administrador', 'carga', 'operador'])) {
header('Location: admin/dashboard.php');
exit;
} else {
$error = 'Acceso denegado: Rol no autorizado (' . htmlspecialchars($user['rol']) . ').';
$auth->logout();
}
} else {
$error = 'Usuario o contraseña incorrectos';
$conn = getDBConnection();
// A2: Registrar intento fallido para rate limiting
$identifier = $_SERVER['REMOTE_ADDR'] . '|' . $username;
registrarIntentoLogin($conn, $identifier, false);
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'Credenciales incorrectas', 'username' => $username]);
}
}
}
}
unset($_SESSION['captcha_code']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Iniciar Sesión | Sistema de Gestión</title>
<link rel="icon" type="image/x-icon" href="../img/favicon.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
font-family: 'Poppins', sans-serif;
background: linear-gradient(135deg, #2a5298 0%, #667eea 100%);
min-height: 100vh;
display: flex;
align-items: center;
justify-content: center;
padding: 20px;
}
.login-wrapper {
background: rgba(255, 255, 255, 0.98);
border-radius: 24px;
box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
overflow: hidden;
width: 100%;
max-width: 480px;
animation: slideUp 0.6s ease-out;
}
@keyframes slideUp {
from { opacity: 0; transform: translateY(30px); }
to { opacity: 1; transform: translateY(0); }
}
.login-header {
background: linear-gradient(135deg, #2a5298 0%, #667eea 100%);
padding: 40px 30px;
text-align: center;
color: white;
}
.login-header i { font-size: 3.5rem; margin-bottom: 15px; animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
.login-header h2 { font-weight: 600; margin-bottom: 5px; font-size: 1.8rem; }
.login-header p { font-weight: 300; opacity: 0.9; font-size: 0.95rem; }
.login-body { padding: 40px 30px; }
.form-floating { margin-bottom: 20px; }
.form-floating > .form-control {
border: 2px solid #e0e0e0;
border-radius: 12px;
padding: 15px;
font-size: 0.95rem;
transition: all 0.3s ease;
}
.form-floating > .form-control:focus {
border-color: #667eea;
box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
}
.form-floating > label { padding: 15px; color: #6c757d; }
.captcha-section {
background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
border-radius: 12px;
padding: 20px;
margin-bottom: 25px;
border: 2px dashed #667eea;
}
.captcha-label {
font-weight: 600;
color: #667eea;
margin-bottom: 12px;
display: flex;
align-items: center;
gap: 8px;
}
.captcha-image-container { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.captcha-image {
border-radius: 8px;
border: 2px solid #667eea;
cursor: pointer;
transition: transform 0.3s ease;
background: white;
}
.captcha-image:hover { transform: scale(1.02); }
.captcha-refresh {
width: 45px;
height: 45px;
border-radius: 10px;
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
border: none;
color: white;
display: flex;
align-items: center;
justify-content: center;
cursor: pointer;
transition: all 0.3s ease;
}
.captcha-refresh:hover {
transform: rotate(180deg);
box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}
.captcha-hint { font-size: 0.8rem; color: #6c757d; display: flex; align-items: center; gap: 5px; }
.btn-login {
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
border: none;
border-radius: 12px;
padding: 15px;
font-weight: 600;
font-size: 1rem;
letter-spacing: 0.5px;
transition: all 0.3s ease;
width: 100%;
}
.btn-login:hover {
transform: translateY(-2px);
box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
}
.alert-custom {
border-radius: 12px;
border: none;
padding: 15px 20px;
margin-bottom: 25px;
display: flex;
align-items: center;
gap: 12px;
animation: shake 0.5s ease;
}
@keyframes shake {
0%, 100% { transform: translateX(0); }
25% { transform: translateX(-5px); }
75% { transform: translateX(5px); }
}
.alert-danger { background: linear-gradient(135deg, #ffe6e6 0%, #ffcccc 100%); color: #dc3545; }
.alert-success { background: linear-gradient(135deg, #e6ffe6 0%, #ccffcc 100%); color: #28a745; }
.login-footer {
text-align: center;
margin-top: 25px;
padding-top: 20px;
border-top: 1px solid #e0e0e0;
}
.login-footer small {
color: #6c757d;
font-size: 0.85rem;
display: flex;
align-items: center;
justify-content: center;
gap: 8px;
}
.secure-badge {
display: inline-flex;
align-items: center;
gap: 5px;
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
color: white;
padding: 5px 15px;
border-radius: 20px;
font-size: 0.8rem;
margin-top: 10px;
}
.input-icon {
position: absolute;
right: 15px;
top: 50%;
transform: translateY(-50%);
color: #6c757d;
z-index: 5;
}
</style>
</head>
<body>
<div class="login-wrapper">
<div class="login-header">
<i class="fas fa-shield-alt"></i>
<h2>Sistema de Gestión</h2>
</div>
<div class="login-body">
<?php if ($error): ?>
<div class="alert alert-custom alert-danger" role="alert">
<i class="fas fa-exclamation-circle fa-lg"></i>
<span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] == 'session_kicked'): ?>
<div class="alert alert-custom alert-danger" role="alert">
<i class="fas fa-user-slash fa-lg"></i>
<span>Has sido desconectado porque iniciaste sesión en otro dispositivo.</span>
</div>
<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] == 'session_expired'): ?>
<div class="alert alert-custom alert-danger" role="alert">
<i class="fas fa-clock fa-lg"></i>
<span>Su sesión expiró por inactividad</span>
</div>
<?php endif; ?>
<form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<div class="form-floating">
<input type="text" class="form-control" id="username" name="username" placeholder="Usuario" required autofocus>
<label for="username"><i class="fas fa-user me-2"></i>Usuario</label>
<i class="fas fa-user input-icon"></i>
</div>
<div class="form-floating">
<input type="password" class="form-control" id="password" name="password" placeholder="Contraseña" required>
<label for="password"><i class="fas fa-lock me-2"></i>Contraseña</label>
<i class="fas fa-lock input-icon"></i>
</div>
<div class="captcha-section">
<div class="captcha-label">
<i class="fas fa-shield-alt"></i> Verificación de Seguridad
</div>
<div class="captcha-image-container">
<img src="captcha.php?t=<?php echo time(); ?>" alt="CAPTCHA" class="captcha-image" id="captchaImage" onclick="refreshCaptcha()">
<button type="button" class="captcha-refresh" onclick="refreshCaptcha()" title="Refrescar CAPTCHA">
<i class="fas fa-sync-alt"></i>
</button>
</div>
<input type="text" class="form-control" name="captcha" placeholder="Escribe el código de la imagen" required autocomplete="off" style="border-radius: 10px;">
<div class="captcha-hint mt-2">
</div>
</div>
<button type="submit" class="btn btn-login text-white">
<i class="fas fa-sign-in-alt me-2"></i> Ingresar al Sistema
</button>
</form>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?php echo $csp_nonce; ?>">
function refreshCaptcha() {
document.getElementById('captchaImage').src = 'captcha.php?t=' + new Date().getTime();
document.querySelector('input[name="captcha"]').value = '';
document.querySelector('input[name="captcha"]').focus();
}
document.querySelector('form').addEventListener('submit', function() {
const submitBtn = this.querySelector('button[type="submit"]');
submitBtn.disabled = true;
submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Verificando...';
});
</script>
</body>
</html>
