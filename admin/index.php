<?php
// admin/index.php

// [M3] Verificar y asegurar sesión activa antes de acceder a $_SESSION
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// [C2] Validar sesión contra BD — requireValidSession() redirige al login si falla o está expirada en BD
require_once __DIR__ . '/../config/session_manager.php';
requireValidSession();

// Safeguard: verificar que el ID de usuario existe tras la validación
if (empty($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit();
}

// [C4] Revalidar rol directamente desde BD para prevenir escalada de privilegios vía manipulación de sesión
$rol = function_exists('getUserRoleFromDB') 
    ? getUserRoleFromDB((int)$_SESSION['usuario_id']) 
    : ($_SESSION['rol'] ?? '');

$roles_permitidos = ['administrador', 'carga', 'operador'];
if (!in_array($rol, $roles_permitidos, true)) {
    header('Location: ../index.php');
    exit();
}

// [B2] Validar parámetro redirect — estrictamente rutas internas (evita Open Redirect / Phishing)
$redirect_raw = $_GET['redirect'] ?? '';
if (
    !empty($redirect_raw) 
    && strpos($redirect_raw, '/') === 0 
    && strpos($redirect_raw, '//') === false
) {
    // Sanitización adicional para evitar caracteres inesperados en rutas
    $redirect = filter_var($redirect_raw, FILTER_SANITIZE_URL);
    header('Location: ' . $redirect);
    exit();
}

// Redirigir por defecto al dashboard correspondiente según rol
header('Location: dashboard.php');
exit();
