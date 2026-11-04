<?php
// login.php
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/padel_loader_messages.php';
require_once 'includes/brand.php';
require_once 'includes/perfil_helpers.php';
require_once 'includes/mailer.php';
require_once 'includes/auth_rate_limit.php';
require_once 'includes/app_paths.php';
require_once 'includes/legacy_schema.php';
$loaderMensaje = padelLoaderMensajeAleatorio();

$db = getDBConnection();
requireVoleaPadelInstall($db);

$error = '';
$ip = requestClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();

    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Credenciales incorrectas.';
    } else {
        $rate = authLoginRateLimitCheck($db, $ip, $email);
        if (!$rate['allowed']) {
            authHttpError(429, (string) $rate['message']);
        }

        $pwdChangedCol = dbColumnExists($db, 'usuarios', 'password_changed_at')
            ? ', password_changed_at'
            : '';
        $stmt = $db->prepare(
            "SELECT id, nombre, password, rol, activo, email_verificado, tipo_perfil{$pwdChangedCol} FROM usuarios WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // 4. Prevención de Timing Attacks (Tiempo de respuesta constante)
        $dummy_hash = '$2y$10$usesomesillystringfore2uDLvq1Dp/kMwHQI.e.T9.T/1y4Dq';
        $password_valida = false;

        if ($user) {
            $password_valida = password_verify($password, $user['password']);
        } else {
            password_verify($password, $dummy_hash);
        }

        if ($user && $password_valida) {
            authLoginRateLimitClear($db, $ip, $email);

            if (!$user['email_verificado'] && emailVerificationEnabled()) {
                $_SESSION['email_rescate'] = $email;
                redirectTo('registro.php');
            }
            if (!$user['email_verificado'] && !emailVerificationEnabled()) {
                $db->prepare('UPDATE usuarios SET email_verificado = 1, activo = 1 WHERE id = :id')->execute(['id' => $user['id']]);
                $user['email_verificado'] = 1;
                $user['activo'] = 1;
            }
            elseif (!$user['activo']) {
                $error = "Tu cuenta está desactivada. Contacta con la administración del club.";
            } 
            else {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['nombre'] = $user['nombre'];
                $_SESSION['rol'] = $user['rol'];
                $_SESSION['tipo_perfil'] = $user['tipo_perfil'] ?? 'liga';
                $_SESSION['password_changed_at'] = $user['password_changed_at'] ?? null;
                require_once __DIR__ . '/includes/usuario_avatar.php';
                usuarioAvatarRefreshSession($db, (int) $user['id']);

                if (isset($_SESSION['redirect_after_login'])) {
                    $url_destino = $_SESSION['redirect_after_login'];
                    unset($_SESSION['redirect_after_login']);
                    $parsed = parse_url($url_destino);
                    if (empty($parsed['scheme']) && empty($parsed['host']) && !str_contains($url_destino, '//')) {
                        header('Location: ' . appUrl($url_destino));
                    } else {
                        redirectTo('usuario/dashboard.php');
                    }
                    exit;
                }
                if ($user['rol'] === 'admin') {
                    redirectTo('admin/dashboard.php');
                }
                if (tipoPerfilEsPull($user['tipo_perfil'] ?? null)) {
                    redirectTo('usuario/mis_pulls.php');
                }
                redirectTo('usuario/dashboard.php');
            }
        } else {
            authLoginRateLimitRecord($db, $ip, $email);
            $rateAfter = authLoginRateLimitCheck($db, $ip, $email);
            $error = $rateAfter['allowed']
                ? 'Credenciales incorrectas.'
                : (string) $rateAfter['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderBrandHead('root', 'Iniciar sesión'); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime('assets/css/style.css') ?>">
</head>
<body class="bg-padel">
    
    <div id="padel-loader" class="loader-wrapper">
        <div class="loader-content">
            <div class="padel-ball-loader" aria-hidden="true"></div>
            <p><?= htmlspecialchars($loaderMensaje) ?></p>
        </div>
    </div>

    <!-- CONTENEDOR LOGIN MODERNO -->
    <div class="auth-container glass-overlay">
        <form id="loginForm" method="POST" action="<?= htmlspecialchars(appUrl('login.php')) ?>" class="auth-form modern-form">
            
            <a href="<?= htmlspecialchars(appUrl('index.php')) ?>" class="btn-back">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 5px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Volver
            </a>
            
            <div class="form-header">
                <h2>Acceso a la Liga</h2>
                <p>Introduce tus credenciales para continuar</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= sanitizeOutput($error) ?></div>
            <?php endif; ?>
            
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            
            <div class="form-group modern-input">
                <label for="email">Correo Electrónico</label>
                <input type="email" id="email" name="email" placeholder="ejemplo@correo.com" required>
            </div>
            
            <div class="form-group modern-input">
                <div class="password-header">
                    <label for="password">Contraseña</label>
                    <a href="<?= htmlspecialchars(appUrl('recuperar_password.php')) ?>" class="forgot-link">¿La has olvidado?</a>
                </div>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                    <span class="toggle-password" id="togglePassword" title="Mostrar/Ocultar contraseña">
                        <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </span>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block btn-modern">Entrar al panel</button>
            
            <div class="register-prompt">
                <span>¿No tienes cuenta?</span>
                <a href="<?= htmlspecialchars(appUrl('registro.php')) ?>" class="register-link">Regístrate ahora</a>
            </div>
        </form>
    </div>

    <script>
        // 1. ANIMACIÓN DE CARGA (1.5 segundos exactos)
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.getElementById('padel-loader').classList.add('fade-out');
            }, 1500); 
        });

        // 2. TOGGLE PARA VER/OCULTAR CONTRASEÑA
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eye-icon');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                eyeIcon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
            } else {
                eyeIcon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
            }
        });
    </script>
   <script src="assets/js/app.js?v=<?= filemtime('assets/js/app.js') ?>"></script>
</body>
</html>