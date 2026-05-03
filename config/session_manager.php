<?php
// config/session_manager.php

/**
 * Registra la sesión del usuario en la BD
 * Elimina sesiones anteriores del mismo usuario (Kick-out)
 */
function registerUserSession($conn, $user_id, $session_id) {
    try {
        // 1. Invalidar sesiones anteriores de este usuario
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // 2. Registrar la nueva sesión
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_activity) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $session_id, PDO::PARAM_STR);
        $stmt->bindParam(3, $ip, PDO::PARAM_STR);
        $stmt->bindParam(4, $agent, PDO::PARAM_STR);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error en registerUserSession: " . $e->getMessage());
        return false;
    }
}

/**
 * Valida que la sesión exista en la BD y esté activa
 */
function validateUserSession($conn, $user_id, $session_id) {
    try {
        if (!$user_id || !$session_id) return false;
        
        $stmt = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND session_id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $session_id, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            return false; // Sesión no existe o expiró
        }
        
        // Actualizar última actividad
        $stmt = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?");
        $stmt->bindParam(1, $session_id, PDO::PARAM_STR);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        error_log("Error en validateUserSession: " . $e->getMessage());
        return false;
    }
}

/**
 * Elimina la sesión de la BD al hacer logout
 */
function logoutUserSession($conn, $session_id) {
    try {
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE session_id = ?");
        $stmt->bindParam(1, $session_id, PDO::PARAM_STR);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error en logoutUserSession: " . $e->getMessage());
        return false;
    }
}

/**
 * Función para incluir en páginas protegidas
 * ✅ RUTAS CORREGIDAS PARA FUNCIONAR DESDE CUALQUIER CARPETA
 */
function requireValidSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
        header('Location: ../login.php?error=session_invalid');
        exit;
    }
    
    // ✅ CORRECCIÓN: Usar dirname(__FILE__) para ruta absoluta
    require_once dirname(__FILE__) . '/database.php';
    $conn = getDBConnection();
    
    if (!validateUserSession($conn, $_SESSION['user_id'], $_SESSION['session_id'])) {
        session_destroy();
        header('Location: ../login.php?error=session_kicked');
        exit;
    }
}
function removeUserSession($conn, $userId) {
    $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->bindParam(1, $userId, PDO::PARAM_INT);
    return $stmt->execute();
}

?>