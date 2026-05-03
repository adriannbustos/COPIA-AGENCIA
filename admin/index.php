<?php
// M3: Validar estado de sesión antes de acceder a $_SESSION
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// C2: Implementar requireValidSession() de session_manager.php para validación contra BD
require_once __DIR__ . '/../config/session_manager.php';
if (!requireValidSession()) {
    // B2: Validar parámetro redirect - solo rutas internas permitidas (lista blanca)
    $redirect_raw = $_SERVER['REQUEST_URI'] ?? '';
    // Lista blanca de prefijos seguros: solo rutas relativas internas de la aplicación
    $safe_prefixes = ['../index.php', '../admin/', '../panel/', 'index.php', 'admin/', 'panel/'];
    $is_internal = false;
    foreach ($safe_prefixes as $prefix) {
        if (strpos($redirect_raw, $prefix) === 0) {
            $is_internal = true;
            break;
        }
    }
    // Construir redirección segura: solo incluir redirect si pasa validación
    $redirect = $is_internal ? urlencode($redirect_raw) : '';
    header('Location: ../index.php' . ($redirect ? '?redirect=' . $redirect : ''));
    exit();
}


