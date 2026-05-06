<?php
/**
* session_manager.php
*
* Gestión centralizada de sesiones con validación contra base de datos.
*
* Vulnerabilidades resueltas:
*   [C2] requireValidSession() consulta user_sessions en BD.
*   [C4] getUserRoleFromDB() revalida el rol directamente desde BD.
*
* FIX: ahora es autosuficiente — incluye database.php con __DIR__ para que
* funcione correctamente sin importar desde qué carpeta se haga el require.
*/
// Garantizar que getDBConnection() esté disponible sin importar desde dónde
// se incluya este archivo. __DIR__ apunta siempre a config/, no al llamador.
if (!function_exists('getDBConnection')) {
require_once __DIR__ . '/database.php';
}
/**
* Helper interno: redirige al login con un código de error.
* Calcula la ruta relativa correcta al index.php raíz, sea cual sea
* la profundidad del script actual (funciona en XAMPP y en servidor).
*/
if (!function_exists('_redirectToLogin')) {
function _redirectToLogin(string $errorCode = 'session_expired'): void {
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$docRoot    = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$relative   = ltrim(str_replace($docRoot, '', $scriptPath), '/');
$depth      = max(0, substr_count($relative, '/'));
$prefix     = str_repeat('../', $depth);
header('Location: ' . $prefix . 'index.php?error=' . $errorCode);
exit();
}
}
if (!function_exists('requireValidSession')) {
/**
* [C2] Valida que la sesión activa exista en user_sessions en BD.
* Redirige al login si no existe o expiró.
*/
function requireValidSession(): bool {
if (session_status() !== PHP_SESSION_ACTIVE) {
_redirectToLogin('session_expired');
}
$userId = $_SESSION['usuario_id'] ?? null;
// Fallback a session_id() nativo por si $_SESSION['session_id']
// aún no fue persistido (race condition post-login).
$sessionId = $_SESSION['session_id'] ?? session_id();
if (!$userId || !$sessionId) {
_redirectToLogin('session_expired');
}
try {
$conn = getDBConnection();
$stmt = $conn->prepare(
"SELECT id FROM user_sessions
WHERE user_id      = ?
AND session_id   = ?
AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
LIMIT 1"
);
$stmt->execute([$userId, $sessionId]);
if (!$stmt->fetch()) {
session_destroy();
_redirectToLogin('session_expired');
}
// Refrescar last_activity para mantener la sesión viva
$upd = $conn->prepare(
"UPDATE user_sessions
SET last_activity = NOW()
WHERE user_id = ? AND session_id = ?"
);
$upd->execute([$userId, $sessionId]);
} catch (Exception $e) {
error_log("requireValidSession error: " . $e->getMessage());
session_destroy();
_redirectToLogin('session_expired');
}
return true;
}
}
if (!function_exists('getUserRoleFromDB')) {
/**
* [C4] Revalida el rol del usuario directamente desde BD.
* Usar en cada request sensible en lugar de leer $_SESSION['rol'].
*/
function getUserRoleFromDB(int $userId): ?string {
try {
$conn = getDBConnection();
$stmt = $conn->prepare(
"SELECT rol FROM usuarios WHERE id = ? AND activo = 1 LIMIT 1"
);
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
return $row ? strtolower($row['rol']) : null;
} catch (Exception $e) {
error_log("getUserRoleFromDB error: " . $e->getMessage());
return null;
}
}
}
if (!function_exists('registerUserSession')) {
/**
* Registra una nueva sesión activa en user_sessions.
* Llamar inmediatamente después del login exitoso.
*/
function registerUserSession($conn, int $userId, string $sessionId): void {
try {
$stmt = $conn->prepare(
"INSERT INTO user_sessions (user_id, session_id, last_activity)
VALUES (?, ?, NOW())
ON DUPLICATE KEY UPDATE
session_id    = VALUES(session_id),
last_activity = NOW()"
);
$stmt->execute([$userId, $sessionId]);
} catch (Exception $e) {
error_log("registerUserSession error: " . $e->getMessage());
}
}
}
if (!function_exists('destroyUserSession')) {
/**
* Elimina la sesión de BD y destruye la sesión PHP.
* Usar en logout y en kicks.
*/
function destroyUserSession(int $userId, string $sessionId): void {
try {
$conn = getDBConnection();
$stmt = $conn->prepare(
"UPDATE user_sessions
SET last_activity = NOW()
WHERE user_id = ? AND session_id = ?"
);
$stmt->execute([$userId, $sessionId]);
} catch (Exception $e) {
error_log("destroyUserSession error: " . $e->getMessage());
}
if (isset($_COOKIE[session_name()])) {
setcookie(session_name(), '', time() - 3600, '/', '', true, true);
}
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
session_destroy();
}
}
}
/*
* -------------------------------------------------------------------------
* PATRÓN DE USO — copiar al inicio de cada página protegida
* -------------------------------------------------------------------------
*
* <?php
* if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
* require_once __DIR__ . '/../config/session_manager.php';
*
* requireValidSession();
*
* $rol = getUserRoleFromDB((int) $_SESSION['usuario_id']);
* if ($rol !== 'administrador') {
*     header('Location: ../index.php');
*     exit();
* }
*
* -------------------------------------------------------------------------
* SQL para crear la tabla si no existe (ejecutar en phpMyAdmin):
* -------------------------------------------------------------------------
*
* CREATE TABLE IF NOT EXISTS user_sessions (
*     id            INT AUTO_INCREMENT PRIMARY KEY,
*     user_id       INT          NOT NULL,
*     session_id    VARCHAR(128) NOT NULL,
*     last_activity TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
*                                ON UPDATE CURRENT_TIMESTAMP,
*     UNIQUE KEY uq_user_session (user_id, session_id),
*     INDEX idx_last_activity (last_activity)
* );
*
* -------------------------------------------------------------------------
*/