<?php
function require_role($role_required) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user']) || $_SESSION['user']['rol'] !== $role_required) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Acceso denegado.';
        exit;
    }
}
?>
