<?php
/**
 * FUNCIONES COMUNES DEL SISTEMA
 */

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('generarUUID')) {
    function generarUUID() {
        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', 
            mt_rand(0, 65535), mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535)
        );
    }
}

if (!function_exists('formatearFecha')) {
    function formatearFecha($fecha, $formato = 'd/m/Y') {
        if (empty($fecha)) return '';
        return date($formato, strtotime($fecha));
    }
}
?>