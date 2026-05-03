<?php
session_start();
require_once 'database.php';
require_once 'session_manager.php';

if (isset($_SESSION['session_id'])) {
    $conn = getDBConnection();
    logoutUserSession($conn, $_SESSION['session_id']);
}

session_destroy();
header('Location: ../index.php');
exit;
?>