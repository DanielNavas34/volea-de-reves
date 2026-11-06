<?php

require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/padel_loader_messages.php';
require_once 'includes/brand.php';
require_once 'includes/mailer.php';
require_once 'includes/auth_rate_limit.php';
require_once 'includes/password_reset_tokens.php';
require_once 'includes/app_paths.php';
require_once 'includes/legacy_schema.php';

$loaderMensaje = padelLoaderMensajeAleatorio();

$error = '';
$success = '';
$vista_actual = 'pedir_email';
$enlace_simulado = '';
$token_valido = '';
$ip = requestClientIp();

$db = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    $token = (string) $_GET['token'];
    if (passwordResetFindUserId($db, $token) !== null) {
        $vista_actual = 'nueva_password';
        $token_valido = $token;
    } else {
        $error = 'El enlace de recuperación no es válido o ha caducado. Vuelve a solicitarlo.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();

    if (isset($_POST['accion']) && $_POST['accion'] === 'solicitar') {
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $rate = authRecoveryRateLimitCheck($db, $ip, $email);
            if (!$rate['allowed']) {
                authHttpError(429, (string) $rate['message']);
            }
            authRecoveryRateLimitRecord($db, $ip, $email);

            $stmt = $db->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $plainToken = passwordResetTokenGenerate();
                $tokenHash = passwordResetTokenStoreHash($plainToken);
                $expiracion = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $db->prepare(
                    'UPDATE usuarios SET token_recuperacion = :token, expiracion_token = :exp WHERE id = :id'
                )->execute(['token' => $tokenHash, 'exp' => $expiracion, 'id' => $user['id']]);

                if (enviarCorreoRecuperacion($email, $plainToken)) {
                    if (mailShowRecoveryLinkInUi()) {
                        $enlace_simulado = mailPublicRecoveryUrl($plainToken);
                    }
                }
            }
        }
        $vista_actual = 'mensaje_enviado';
    } elseif (isset($_POST['accion']) && $_POST['accion'] === 'resetear') {
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');

        if ($password !== $confirm_password) {
            $error = 'Las contraseñas no coinciden.';
            $vista_actual = 'nueva_password';
            $token_valido = $token;
        } elseif (($pwdError = passwordPolicyError($password)) !== null) {
            $error = $pwdError;
            $vista_actual = 'nueva_password';
            $token_valido = $token;
        } else {
            $uid = passwordResetFindUserId($db, $token);
            if ($uid !== null) {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $hasPwdCol = dbColumnExists($db, 'usuarios', 'password_changed_at');
                if ($hasPwdCol) {
                    $upd = $db->prepare(
                        'UPDATE usuarios SET password = :password, token_recuperacion = NULL, expiracion_token = NULL, password_changed_at = NOW() WHERE id = :id'
                    );
                } else {
                    $upd = $db->prepare(
                        'UPDATE usuarios SET password = :password, token_recuperacion = NULL, expiracion_token = NULL WHERE id = :id'
                    );
                }
                $upd->execute(['password' => $hashed_password, 'id' => $uid]);
                $vista_actual = 'exito';
            } else {
                $error = 'El enlace de recuperación ha caducado durante el proceso.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderBrandHead('root', 'Recuperar contraseña'); ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-padel">

    <div id="padel-loader" class="loader-wrapper">
        <div class="loader-content">
            <div class="padel-ball-loader" aria-hidden="true"></div>
            <p><?= htmlspecialchars($loaderMensaje) ?></p>
        </div>
    </div>

    <div class="auth-container glass-overlay">
        
        <?php if ($vista_actual === 'pedir_email'): ?>
            <form method="POST" action="<?= htmlspecialchars(appUrl('recuperar_password.php')) ?>" class="auth-form modern-form">
                
                <a href="<?= htmlspecialchars(appUrl('login.php')) ?>" class="btn-back">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 5px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Volver
                </a>
                
                <div class="form-header">
                    <h2>Recuperar Contraseña</h2>
                    <p>Introduce el correo electrónico con el que te registraste y te enviaremos un enlace seguro.</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= sanitizeOutput($error) ?></div>
                <?php endif; ?>
                
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="accion" value="solicitar">
                
                <div class="form-group modern-input">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" id="email" name="email" placeholder="ejemplo@correo.com" required autofocus>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-modern">Enviar enlace</button>
            </form>

        <?php elseif ($vista_actual === 'mensaje_enviado'): ?>
            <div class="auth-form modern-form">
                
                <div class="form-header">
                    <h2>Revisa tu bandeja de entrada</h2>
                    <p>Si el correo introducido está en nuestra base de datos, recibirás un enlace de recuperación en los próximos minutos.</p>
                </div>
                
                <?php if ($enlace_simulado !== '' && mailShowRecoveryLinkInUi()): ?>
                    <div class="alert" style="background-color: #fff3cd; color: #856404; font-size: 0.85rem; border-left: 4px solid #ffeeba; margin-top: 20px; word-break: break-all; padding: 15px;">
                        <strong>Modo desarrollo (correo simulado)</strong><br>
                        <a href="<?= sanitizeOutput($enlace_simulado) ?>" style="color: #0056b3; text-decoration: underline; font-weight: bold;">Abrir enlace de recuperación</a>
                    </div>
                <?php endif; ?>

                <a href="<?= htmlspecialchars(appUrl('login.php')) ?>" class="btn btn-secondary btn-block btn-modern" style="margin-top: 20px; text-align: center; text-decoration: none;">Volver al inicio de sesión</a>
            </div>

        <?php elseif ($vista_actual === 'nueva_password'): ?>
            <form method="POST" action="<?= htmlspecialchars(appUrl('recuperar_password.php')) ?>" class="auth-form modern-form">
                
                <div class="form-header">
                    <h2>Nueva Contraseña</h2>
                    <p>Por favor, crea una nueva contraseña segura.</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= sanitizeOutput($error) ?></div>
                <?php endif; ?>
                
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="accion" value="resetear">
                <input type="hidden" name="token" value="<?= sanitizeOutput($token_valido) ?>">
                
                <div class="form-group modern-input">
                    <label for="password">Nueva Contraseña (Mín. 8 char.)</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" placeholder="••••••••" required minlength="8" autofocus>
                        <span class="toggle-password" title="Mostrar/Ocultar contraseña">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </span>
                    </div>
                </div>
                
                <div class="form-group modern-input">
                    <label for="confirm_password">Confirmar Nueva Contraseña</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required minlength="8">
                        <span class="toggle-password" title="Mostrar/Ocultar contraseña">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-modern">Guardar Contraseña</button>
            </form>

        <?php elseif ($vista_actual === 'exito'): ?>
            <div class="auth-form modern-form" style="text-align: center;">
                <div class="form-header" style="margin-bottom: 0;">
                    <h2 style="color: #28a745; margin-bottom: 15px;">¡Contraseña Actualizada!</h2>
                    <p>Tu contraseña ha sido cambiada correctamente.</p>
                </div>
                <a href="<?= htmlspecialchars(appUrl('login.php')) ?>" class="btn btn-primary btn-block btn-modern" style="margin-top: 30px; text-decoration: none;">Iniciar Sesión</a>
            </div>
        <?php endif; ?>

    </div>

    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                const loader = document.getElementById('padel-loader');
                if (loader) loader.classList.add('fade-out');
            }, 2000); 
        });

        document.querySelectorAll('.toggle-password').forEach(toggle => {
            toggle.addEventListener('click', function () {
                const input = this.previousElementSibling;
                const eyeIcon = this.querySelector('svg');
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                if (type === 'text') {
                    eyeIcon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
                } else {
                    eyeIcon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
                }
            });
        });
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>
