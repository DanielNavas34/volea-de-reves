<?php
// /logout.php
// Script para destruir la sesión de forma segura

require_once 'includes/security.php';
require_once 'includes/app_paths.php';

// Limpiar todas las variables de sesión
$_SESSION = array();

// Destruir la cookie de sesión en el navegador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión en el servidor
session_destroy();

// Redirigir a la portada principal
redirectTo('index.php');
?>