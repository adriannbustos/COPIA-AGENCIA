<?php
// logout.php — ubicado en config/
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session_manager.php';

// destroyUserSession() limpia BD + cookie + $_SESSION + session_destroy()
if (isset($_SESSION['usuario_id'], $_SESSION['session_id'])) {
    destroyUserSession((int) $_SESSION['usuario_id'], $_SESSION['session_id']);
} else {
    // Si no hay datos de sesión, destruir igual la sesión PHP
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}

header('Location: ../index.php');
exit;
