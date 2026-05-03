<?php
require_once 'database.php';
class Auth {
private $conn;
public function __construct() {
$this->conn = getDBConnection();
}
// Login
public function login($username, $password) {
try {
$stmt = $this->conn->prepare("
SELECT u.*, e.nombre as empresa_nombre
FROM usuarios u
LEFT JOIN empresas e ON u.empresa_id = e.id
WHERE u.username = :username AND u.activo = TRUE
");
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();
if ($user && password_verify($password, $user['password'])) {
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['nombre_completo'] = $user['nombre_completo'];
$_SESSION['email'] = $user['email'];
$_SESSION['rol'] = $user['rol'];
$_SESSION['empresa_id'] = $user['empresa_id'];
$_SESSION['empresa_nombre'] = $user['empresa_nombre'];
$_SESSION['sucursal_id'] = $user['sucursal_id'];
$_SESSION['logged_in'] = true;
return true;
}
return false;
} catch(PDOException $e) {
error_log($e->getMessage());
return false;
}
}
// Verificar si está logueado
public function isLoggedIn() {
return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}
// Verificar rol
public function hasRole($role) {
return $this->isLoggedIn() && $_SESSION['rol'] === $role;
}
// Obtener usuario actual
public function getCurrentUser() {
if (!$this->isLoggedIn()) {
return null;
}
return [
'id' => $_SESSION['user_id'],
'username' => $_SESSION['username'],
'nombre_completo' => $_SESSION['nombre_completo'],
'email' => $_SESSION['email'],
'rol' => $_SESSION['rol'],
'empresa_id' => $_SESSION['empresa_id'],
'empresa_nombre' => $_SESSION['empresa_nombre'],
'sucursal_id' => $_SESSION['sucursal_id']
];
}
// Logout
public function logout() {
    //PASO 1: Obtener el ID del usuario ANTES de destruir la sesión
    $user_id = null;
    if ($this->isLoggedIn() && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    //PASO 2: Regenerar ID de sesión (previene fijación de sesión)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    
    //PASO 3: Limpiar cookies de sesión explícitamente
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"] ?? false,
            $params["httponly"] ?? true
        );
    }
    
    //PASO 4: Destruir la sesión de PHP
    session_destroy();
    
    //PASO 5: Eliminar sesión de la base de datos (si tenemos el ID)
    if ($user_id && function_exists('removeUserSession')) {
        try {
            $conn = getDBConnection();
            removeUserSession($conn, $user_id);
        } catch(PDOException $e) {
            error_log("Error al eliminar sesión de BD: " . $e->getMessage());
        }
    }
    
    // PASO 6: Limpiar variables de sesión residuales
    $_SESSION = [];
    
    return true;
}
// Registrar cambio
public function logChange($tabla, $registro_id, $tipo, $campo = null, $valor_anterior = null, $valor_nuevo = null) {
if (!$this->isLoggedIn()) return;
try {
$stmt = $this->conn->prepare("
INSERT INTO cambios_registro
(usuario_id, tabla, registro_id, tipo_cambio, campo_modificado, valor_anterior, valor_nuevo, ip_address)
VALUES (:usuario_id, :tabla, :registro_id, :tipo_cambio, :campo_modificado, :valor_anterior, :valor_nuevo, :ip_address)
");
$stmt->execute([
'usuario_id' => $_SESSION['user_id'],
'tabla' => $tabla,
'registro_id' => $registro_id,
'tipo_cambio' => $tipo,
'campo_modificado' => $campo,
'valor_anterior' => $valor_anterior,
'valor_nuevo' => $valor_nuevo,
'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
} catch(PDOException $e) {
error_log($e->getMessage());
}
}
}
$auth = new Auth();
?>