<?php
// /logout.php
// Script para destruir la sesión de forma segura (exige token CSRF para que otra web no pueda cerrarla)

require_once 'includes/security.php';
require_once 'includes/app_paths.php';

$token = (string) ($_POST['csrf_token'] ?? $_GET['t'] ?? '');
if (!empty($_SESSION['usuario_id']) && !verifyCSRFToken($token)) {
    $csrf = generateCSRFToken();
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrar sesión</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-padel">
    <main style="max-width: 420px; margin: 4rem auto; padding: 1.5rem; background: #fff; border-radius: 8px; text-align: center;">
        <p>¿Quieres cerrar la sesión?</p>
        <form method="post" action="<?= htmlspecialchars(appUrl('logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="btn btn-danger-soft">Cerrar sesión</button>
            <a href="<?= htmlspecialchars(appUrl('index.php')) ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </main>
</body>
</html>
    <?php
    exit;
}

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
