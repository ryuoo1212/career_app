<?php
require_once 'config.php';

$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// Clear all session data
$_SESSION = array();

// Destroy session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

if ($isAdmin) {
    redirect('admin_login.php?logout=success');
} else {
    redirect('login.php?logout=success');
}
?>
