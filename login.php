<?php
// =============================================================================
// CONFIGURACIÓN SEGURA DE SESIONES (DEBE IR ANTES DE session_start())
// =============================================================================
// [C3 - RESUELTO] Cookie segura activada dinámicamente según protocolo
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
ini_set('session.cookie_secure', 1);
}
ini_set('session.cookie_httponly', 1);          // Bloquea acceso JS a la cookie
ini_set('session.cookie_samesite', 'Strict');   // Previene CSRF vía cookies
ini_set('session.use_strict_mode', 1);          // Rechaza IDs de sesión no inicializados
ini_set('session.cookie_lifetime', 1800);       // Expiración: 30 min
ini_set('session.use_only_cookies', 1);         // Desactiva paso de ID por URL
// Cabeceras de seguridad HTTP
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 0"); // Desactivado en favor de CSP moderno
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains", false); // HSTS
// [A5 - RESUELTO] Generación de nonce para CSP con comillas simples obligatorias
$csp_nonce = bin2hex(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$csp_nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'nonce-{$csp_nonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self';");
session_start();
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/auditoria_func.php';
// Crear tabla de intentos si no existe
$conn = getDBConnection();
try {
$conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
id INT AUTO_INCREMENT PRIMARY KEY,
identifier VARCHAR(255) NOT NULL,
fecha_intento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
exitoso TINYINT(1) NOT NULL DEFAULT 0,
eliminado TINYINT(1) NOT NULL DEFAULT 0,
INDEX idx_identifier (identifier),
INDEX idx_fecha (fecha_intento)
)");
// Asegurar columna para soft delete en login_attempts
@$conn->exec("ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS eliminado TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) {
error_log("Error creando tabla login_attempts: " . $e->getMessage());
}
// [A2 - RESUELTO] Rate Limiting
function verificarRateLimit($conn, $identifier, $maxIntentos = 5, $tiempoBloqueo = 900) {
try {
$stmt = $conn->prepare("SELECT COUNT(*) as intentos, MAX(fecha_intento) as ultimo_intento FROM login_attempts WHERE identifier = ? AND fecha_intento > DATE_SUB(NOW(), INTERVAL ? SECOND) AND exitoso = 0 AND eliminado = 0");
$stmt->execute([$identifier, $tiempoBloqueo]);
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
$stmt->execute([$identifier, (int)$exitoso]);
// Limpieza automática con UPDATE para preservar historial de auditoría (soft delete)
$stmtCleanup = $conn->prepare("UPDATE login_attempts SET eliminado = 1, fecha_eliminacion = NOW() WHERE fecha_intento < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND eliminado = 0");
$stmtCleanup->execute();
} catch (Exception $e) {
error_log("Error en registrarIntentoLogin: " . $e->getMessage());
}
}
$error = '';
$success = '';
// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['captcha_code'])) {
$_SESSION['captcha_code'] = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
// Validación CSRF estricta
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
$error = 'Error de seguridad. Recarga la página.';
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'Token CSRF inválido']);
} elseif (empty($_SESSION['captcha_code'])) {
$error = 'CAPTCHA expirado. Recarga la página.';
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'CAPTCHA expirado']);
} else {
$captcha_input = strtoupper(trim($_POST['captcha'] ?? ''));
if ($captcha_input !== $_SESSION['captcha_code']) {
$error = 'Código de seguridad incorrecto.';
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'CAPTCHA incorrecto']);
} else {
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
if ($username === '' || $password === '') {
$error = 'Complete todos los campos';
} else {
// [A2] Verificar bloqueo por intentos
$identifier = $_SERVER['REMOTE_ADDR'] . '|' . $username;
$rateLimit = verificarRateLimit($conn, $identifier);
if ($rateLimit['bloqueado']) {
$error = 'Demasiados intentos fallidos. Intente nuevamente en ' . ceil($rateLimit['tiempo_restante'] / 60) . ' minutos.';
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'Rate limit excedido']);
} elseif ($auth->login($username, $password)) {
$user = $auth->getCurrentUser();
registrarIntentoLogin($conn, $identifier, true);
// Limpieza de sesiones antiguas con UPDATE para preservar historial (soft delete)
$stmtClean = $conn->prepare("UPDATE user_sessions SET activa = 0, fecha_cierre = NOW() WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND activa = 1");
$stmtClean->execute([$user['id']]);
// Kick de sesión activa con UPDATE para preservar historial (soft delete)
$stmtKick = $conn->prepare("UPDATE user_sessions SET activa = 0, fecha_cierre = NOW(), motivo_cierre = 'session_kick' WHERE user_id = ? AND activa = 1");
$stmtKick->execute([$user['id']]);
if ($stmtKick->rowCount() > 0) {
logAuditoria($conn, 'login', 'sesion_forzada', $user['id'], ['razon' => 'Session kick automática']);
}
// Regenerar ID para prevenir fijación de sesión
session_regenerate_id(false);
// [C2/C4 - PREPARADO] Almacenar datos críticos en sesión
$_SESSION['usuario_id'] = (int) $user['id'];
$_SESSION['session_id'] = session_id();
$_SESSION['rol']        = $user['rol'];
require_once 'config/session_manager.php';
registerUserSession($conn, $user['id'], session_id());
unset($_SESSION['captcha_code']);
$rol = strtolower($user['rol']);
logAuditoria($conn, 'login', 'acceso', $user['id'], ['rol' => $rol]);
// Forzar escritura antes del redirect (evita race conditions)
session_write_close();
// [A8 - RESUELTO] Redirección segura sin validación frágil (strpos)
if in_array($rol, ['administrador', 'carga', 'operador'])) {
header('Location: admin/dashboard.php');
exit;
} else {
$error = 'Acceso denegado: Rol no autorizado.';
$auth->logout();
}
} else {
$error = 'Usuario o contraseña incorrectos';
registrarIntentoLogin($conn, $identifier, false);
// [M2 - CUMPLIDO] No se loggea el username en producción para evitar info leakage
logAuditoria($conn, 'login_fallo', 'acceso', null, ['razon' => 'Credenciales incorrectas']);
}
}
}
}
// Invalidar token CSRF post-POST para mitigar replay
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
unset($_SESSION['captcha_code']);
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
<style nonce="<?php echo htmlspecialchars($csp_nonce); ?>">
/* (Tus estilos originales se mantienen intactos) */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #2a5298 0%, #667eea 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.login-wrapper { background: rgba(255, 255, 255, 0.98); border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); overflow: hidden; width: 100%; max-width: 480px; animation: slideUp 0.6s ease-out; }
@keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.login-header { background: linear-gradient(135deg, #2a5298 0%, #667eea 100%); padding: 40px 30px; text-align: center; color: white; }
.login-header i { font-size: 3.5rem; margin-bottom: 15px; animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
.login-header h2 { font-weight: 600; margin-bottom: 5px; font-size: 1.8rem; }
.login-body { padding: 40px 30px; }
.form-floating { margin-bottom: 20px; }
.form-floating > .form-control { border: 2px solid #e0e0e0; border-radius: 12px; padding: 15px; font-size: 0.95rem; transition: all 0.3s ease; }
.form-floating > .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15); }
.captcha-section { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 2px dashed #667eea; }
.captcha-image { border-radius: 8px; border: 2px solid #667eea; cursor: pointer; background: white; }
.captcha-refresh { width: 45px; height: 45px; border-radius: 10px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; cursor: pointer; }
.btn-login { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; border-radius: 12px; padding: 15px; font-weight: 600; color: white; width: 100%; }
.alert-custom { border-radius: 12px; padding: 15px 20px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; }
.alert-danger { background: linear-gradient(135deg, #ffe6e6 0%, #ffcccc 100%); color: #dc3545; }
</style>
</head>
<body>
<div class="login-wrapper">
<div class="login-header"><i class="fas fa-shield-alt"></i><h2>Sistema de Gestión</h2></div>
<div class="login-body">
<?php if ($error): ?>
<div class="alert-custom alert-danger"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>
<?php if (isset($_GET['error']) && in_array($_GET['error'], ['session_kicked', 'session_expired'])): ?>
<div class="alert-custom alert-danger"><i class="fas fa-user-slash"></i><span><?php echo htmlspecialchars($_GET['error'] === 'session_kicked' ? 'Sesión cerrada por nuevo inicio en otro dispositivo.' : 'Sesión expirada por inactividad.'); ?></span></div>
<?php endif; ?>
<form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
<div class="form-floating"><input type="text" class="form-control" id="username" name="username" placeholder="Usuario" required autocomplete="username"><label for="username"><i class="fas fa-user me-2"></i>Usuario</label></div>
<div class="form-floating"><input type="password" class="form-control" id="password" name="password" placeholder="Contraseña" required autocomplete="current-password"><label for="password"><i class="fas fa-lock me-2"></i>Contraseña</label></div>
<div class="captcha-section">
<div class="captcha-image-container">
<img src="captcha.php?t=<?php echo time(); ?>" alt="CAPTCHA" class="captcha-image" id="captchaImage" onclick="this.src='captcha.php?t='+new Date().getTime()">
<button type="button" class="captcha-refresh" onclick="document.getElementById('captchaImage').click()"><i class="fas fa-sync-alt"></i></button>
</div>
<input type="text" class="form-control mt-2" name="captcha" placeholder="Código de verificación" required autocomplete="off">
</div>
<button type="submit" class="btn btn-login"><i class="fas fa-sign-in-alt me-2"></i> Ingresar</button>
</form>
</div>
</div>
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.querySelector('form').addEventListener('submit', function() {
const btn = this.querySelector('button[type="submit"]');
btn.disabled = true;
btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Verificando...';
});
</script>
</body>
</html>