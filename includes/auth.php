<?php
// /includes/auth.php
// Middleware para verificar autenticación y roles de usuario

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/perfil_helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/app_paths.php';
require_once __DIR__ . '/legacy_schema.php';

function enforceEmailVerifiedSession(): void
{
    if (!emailVerificationEnabled() || empty($_SESSION['usuario_id'])) {
        return;
    }
    if (($_SESSION['rol'] ?? '') === 'admin') {
        return;
    }
    require_once __DIR__ . '/../config/database.php';
    $db = getDBConnection();
    $stmt = $db->prepare('SELECT email, email_verificado FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['usuario_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['email_verificado'] ?? 0) === 1) {
        return;
    }
    $_SESSION['email_rescate'] = (string) ($row['email'] ?? '');
    session_write_close();
    redirectTo('registro.php');
}

/**
 * Revalida la sesión contra la BD: cuenta borrada o desactivada, o contraseña cambiada → logout.
 * El rol y el tipo de perfil se toman siempre de la BD (un admin degradado pierde el acceso al momento).
 */
function enforceSessionUserState(): void
{
    if (empty($_SESSION['usuario_id'])) {
        return;
    }
    require_once __DIR__ . '/../config/database.php';
    $db = getDBConnection();
    $hasPwdCol = dbColumnExists($db, 'usuarios', 'password_changed_at');
    $pwdCol = $hasPwdCol ? ', password_changed_at' : '';
    $stmt = $db->prepare("SELECT rol, activo, tipo_perfil{$pwdCol} FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int) $_SESSION['usuario_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $valida = $row && (int) ($row['activo'] ?? 0) === 1;
    if ($valida && $hasPwdCol) {
        $dbVal = $row['password_changed_at'] ?? null;
        $sessVal = $_SESSION['password_changed_at'] ?? null;
        $valida = ($dbVal !== null ? (string) $dbVal : '') === ($sessVal !== null ? (string) $sessVal : '');
    }
    if ($valida) {
        $_SESSION['rol'] = (string) $row['rol'];
        $_SESSION['tipo_perfil'] = $row['tipo_perfil'] ?? 'liga';
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    redirectTo('login.php');
}

function requireLogin(): void
{
    requireVoleaPadelInstall();
    if (!isset($_SESSION['usuario_id'])) {
        redirectTo('login.php');
    }
    enforceSessionUserState();
    enforceEmailVerifiedSession();
}

function requireAdmin(): void
{
    requireLogin();
    if (($_SESSION['rol'] ?? '') !== 'admin') {
        header('HTTP/1.1 403 Forbidden');
        die('Acceso denegado. Esta sección requiere privilegios de administrador.');
    }
}

function requireLiga(): void
{
    requireLogin();
    if (tipoPerfilEsPull($_SESSION['tipo_perfil'] ?? null) && ($_SESSION['rol'] ?? '') !== 'admin') {
        header('HTTP/1.1 403 Forbidden');
        die('Acceso denegado. Esta sección es exclusiva para jugadores de la liga por parejas.');
    }
}
