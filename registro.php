<?php
// /registro.php
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/mailer.php';
require_once 'includes/padel_loader_messages.php';
require_once 'includes/perfil_helpers.php';
require_once 'includes/brand.php';
require_once 'includes/auth_rate_limit.php';
require_once 'includes/app_paths.php';
$loaderMensaje = padelLoaderMensajeAleatorio();

$db = getDBConnection();
requireVoleaPadelInstall($db);

$error = '';
$success = '';
$mensaje_rescate = ''; 
$vista_actual = 'registro'; 
$email_registrado = ''; 
$ip = requestClientIp();

// =================================================================
// Bote de Rescate (Rebote desde login)
// =================================================================
if (isset($_SESSION['email_rescate'])) {
    $vista_actual = 'verificacion';
    $email_registrado = $_SESSION['email_rescate'];
    $mensaje_rescate = "¡Hola de nuevo! Te acabamos de enviar un nuevo código PIN para que puedas continuar.";
    unset($_SESSION['email_rescate']); 

    // Generamos un PIN nuevo y fresco al rebotar
    $codigo_pin_rescate = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiracion_rescate = date('Y-m-d H:i:s', strtotime('+15 minutes')); 
    
    $upd = $db->prepare("UPDATE usuarios SET codigo_verificacion = :pin, intentos_pin = 0, expiracion_pin = :expiracion WHERE email = :email AND email_verificado = 0");
    $upd->execute(['pin' => $codigo_pin_rescate, 'expiracion' => $expiracion_rescate, 'email' => $email_registrado]);
    
    enviarCorreoPIN($email_registrado, $codigo_pin_rescate);
}
// =================================================================

// Variables para mantener los datos en caso de error
$nombre = '';
$apellidos = '';
$email = '';
$telefono = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();

    // FASE 1: PROCESAR EL FORMULARIO DE REGISTRO
    if (isset($_POST['accion']) && $_POST['accion'] == 'registrar') {
        
        if (registrationRateLimitEnabled()) {
            $stmt_ip = $db->prepare(
                "SELECT COUNT(*) FROM registro_attempts WHERE ip = :ip AND fecha_intento >= (NOW() - INTERVAL 30 MINUTE)"
            );
            $stmt_ip->execute(['ip' => $ip]);
            if ((int) $stmt_ip->fetchColumn() >= 3) {
                die('Has superado el límite de registros permitidos. Por seguridad, inténtalo de nuevo en 30 minutos.');
            }
        }

        $nombre = htmlspecialchars(trim($_POST['nombre']));
        $apellidos = htmlspecialchars(trim($_POST['apellidos']));
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $telefono = htmlspecialchars(trim($_POST['telefono']));
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        $tipo_perfil = tipoPerfilDesdeRegistro($_POST);
        $email_regex = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.(com|es|net|org|info|eu|cat|gal|eus|edu)$/i';
        $telefono_regex = '/^[67][0-9]{8}$/';

        if (empty($_POST['acepta_terminos'])) {
            $error = 'Debes aceptar los Términos de Uso y la Política de Privacidad para crear una cuenta.';
        } elseif ($password !== $confirm_password) {
            $error = "Las contraseñas no coinciden.";
        } elseif (strlen($password) < 8) {
            $error = "La contraseña debe tener al menos 8 caracteres.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match($email_regex, $email)) {
            $error = "El formato del correo no es válido o la extensión no está permitida (Usa .com, .es, .net, etc.).";
        } elseif (!preg_match($telefono_regex, $telefono)) {
            $error = "El teléfono debe ser un móvil español válido de 9 dígitos (empezando por 6 o 7).";
        } else {
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = :email");
            $stmt->execute(['email' => $email]);
            
            if ($stmt->fetch()) {
                $error = 'No pudimos completar el registro con estos datos. Si ya tienes cuenta, inicia sesión o recupera tu contraseña.';
                if (registrationRateLimitEnabled()) {
                    $db->prepare('INSERT INTO registro_attempts (ip) VALUES (:ip)')->execute(['ip' => $ip]);
                }
            } else {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                $codigo_pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes')); 
                
                $sql = "INSERT INTO usuarios (nombre, apellidos, email, telefono, password, email_verificado, activo, codigo_verificacion, intentos_pin, expiracion_pin, tipo_perfil) 
                        VALUES (:nombre, :apellidos, :email, :telefono, :password, 0, 0, :pin, 0, :expiracion, :tipo_perfil)";
                $stmt = $db->prepare($sql);
                
                try {
                    $stmt->execute([
                        'nombre' => $nombre,
                        'apellidos' => $apellidos,
                        'email' => $email,
                        'telefono' => $telefono,
                        'password' => $hashed_password,
                        'pin' => $codigo_pin,
                        'expiracion' => $expiracion,
                        'tipo_perfil' => $tipo_perfil
                    ]);
                    
                    if (registrationRateLimitEnabled()) {
                        $db->prepare('INSERT INTO registro_attempts (ip) VALUES (:ip)')->execute(['ip' => $ip]);
                    }

                    if (!emailVerificationEnabled()) {
                        $uid = (int) $db->lastInsertId();
                        $db->prepare(
                            'UPDATE usuarios SET email_verificado = 1, activo = 1, codigo_verificacion = NULL WHERE id = :id'
                        )->execute(['id' => $uid]);
                        $vista_actual = 'exito';
                        session_regenerate_id(true);
                        $_SESSION['usuario_id'] = $uid;
                        $_SESSION['nombre'] = $nombre;
                        $_SESSION['rol'] = 'usuario';
                        $_SESSION['tipo_perfil'] = $tipo_perfil;
                    } elseif (enviarCorreoPIN($email, $codigo_pin)) {
                        $vista_actual = 'verificacion';
                        $email_registrado = $email;
                    } else {
                        $db->prepare("DELETE FROM usuarios WHERE email = :email")->execute(['email' => $email]);
                        $error = 'Hubo un problema de conexión al enviar el correo con el PIN. Inténtalo de nuevo en unos minutos.';
                    }
                    
                } catch (PDOException $e) {
                    $error = "Error al registrar el usuario. Inténtalo de nuevo más tarde.";
                }
            }
        }
    }
    
    elseif (isset($_POST['accion']) && $_POST['accion'] === 'reenviar_pin') {
        $email = filter_var($_POST['email_verificacion'] ?? '', FILTER_SANITIZE_EMAIL);
        $vista_actual = 'verificacion';
        $email_registrado = $email;
        if ($email === '' || !emailVerificationEnabled()) {
            $error = 'No se puede reenviar el código.';
        } elseif (!authPinResendRateLimitCheck($db, $ip)) {
            authHttpError(429, 'Demasiadas solicitudes. Espera unos minutos antes de reenviar el código.');
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            authPinResendRateLimitRecord($db, $ip);
            $stmt = $db->prepare(
                'SELECT id FROM usuarios WHERE email = :email AND email_verificado = 0 LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $codigo_pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $db->prepare(
                    'UPDATE usuarios SET codigo_verificacion = :pin, intentos_pin = 0, expiracion_pin = :exp WHERE email = :email AND email_verificado = 0'
                )->execute(['pin' => $codigo_pin, 'exp' => $expiracion, 'email' => $email]);
                if (!enviarCorreoPIN($email, $codigo_pin)) {
                    $error = 'Hubo un problema de conexión al enviar el correo con el PIN. Inténtalo de nuevo en unos minutos.';
                } else {
                    $mensaje_rescate = 'Si el correo está pendiente de verificación, recibirás un nuevo PIN.';
                }
            } else {
                $mensaje_rescate = 'Si el correo está pendiente de verificación, recibirás un nuevo PIN.';
            }
        }
    }

    // FASE 2: VERIFICAR EL PIN INTRODUCIDO
    elseif (isset($_POST['accion']) && $_POST['accion'] == 'verificar') {
        $email = filter_var($_POST['email_verificacion'], FILTER_SANITIZE_EMAIL);
        $pin_introducido = trim($_POST['pin']);
        
        $stmt = $db->prepare("SELECT id, nombre, rol, codigo_verificacion, intentos_pin, expiracion_pin, tipo_perfil FROM usuarios WHERE email = :email AND email_verificado = 0 LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        
        if ($user) {
            if (strtotime($user['expiracion_pin']) < time()) {
                $error = "El código PIN ha caducado. Vuelve a iniciar sesión para solicitar uno nuevo.";
                $vista_actual = 'verificacion';
                $email_registrado = $email;
            } elseif ($user['intentos_pin'] >= 5) {
                $error = "Has superado el límite de intentos fallidos. Por seguridad, solicita un código nuevo iniciando sesión de nuevo.";
                $vista_actual = 'verificacion';
                $email_registrado = $email;
            } else {
                // --- MEDIDA DE SEGURIDAD: hash_equals para evitar Timing Attacks ---
                if (hash_equals((string)$user['codigo_verificacion'], (string)$pin_introducido)) {
                    
                    $upd = $db->prepare("UPDATE usuarios SET email_verificado = 1, activo = 1, codigo_verificacion = NULL, intentos_pin = 0, expiracion_pin = NULL WHERE id = :id");
                    $upd->execute(['id' => $user['id']]);
                    mailClearDevPin($email);
                    
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = $user['id'];
                    $_SESSION['nombre'] = $user['nombre'];
                    $_SESSION['rol'] = $user['rol'];
                    $_SESSION['tipo_perfil'] = $user['tipo_perfil']; 
                    
                    $vista_actual = 'exito';
                } else {
                    $db->prepare("UPDATE usuarios SET intentos_pin = intentos_pin + 1 WHERE id = :id")->execute(['id' => $user['id']]);
                    
                    $intentos_restantes = 4 - $user['intentos_pin'];
                    $error = "El código introducido es incorrecto. Te quedan $intentos_restantes intento(s).";
                    $vista_actual = 'verificacion'; 
                    $email_registrado = $email;
                }
            }
        } else {
            $error = "Usuario no encontrado o ya verificado.";
            $vista_actual = 'verificacion'; 
            $email_registrado = $email;
        }
    }
}

// Validación de Redirección (Open Redirect)
$redirect_seguro = false;
if ($vista_actual === 'exito' && isset($_SESSION['redirect_after_login'])) {
    $url_destino = $_SESSION['redirect_after_login'];
    unset($_SESSION['redirect_after_login']);
    
    $parsed = parse_url($url_destino);
    if (empty($parsed['scheme']) && empty($parsed['host']) && strpos($url_destino, '//') === false) {
        $redirect_seguro = $url_destino; 
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderBrandHead('root', 'Registrarse'); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime('assets/css/style.css') ?>">
    <style>
        .auth-form--verify { max-width: 100%; }
        .alert-info-verify {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
            margin-bottom: 20px;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.95rem;
            word-break: break-word;
        }
        .verify-email { word-break: break-all; }
        .pin-input {
            font-size: clamp(1.25rem, 5vw, 2rem);
            letter-spacing: clamp(0.25rem, 2vw, 0.5rem);
            text-align: center;
            padding: 15px 10px;
            font-weight: bold;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            background-color: #f8fafc;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        .pin-input:focus {
            background-color: #fff;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 4px rgba(0, 51, 102, 0.1);
            outline: none;
        }
    </style>
</head>
<body class="bg-padel">

    <div id="padel-loader" class="loader-wrapper">
        <div class="loader-content">
            <div class="padel-ball-loader" aria-hidden="true"></div>
            <p><?= htmlspecialchars($loaderMensaje) ?></p>
        </div>
    </div>

    <div class="auth-container glass-overlay">
        
        <!-- VISTA 1: FORMULARIO DE REGISTRO -->
        <?php if ($vista_actual === 'registro'): ?>
            <form id="registroForm" method="POST" action="<?= htmlspecialchars(appUrl('registro.php')) ?>" class="auth-form modern-form register-form">
                
                <a href="<?= htmlspecialchars(appUrl('login.php')) ?>" class="btn-back">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 5px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Volver
                </a>

                <div class="form-header">
                    <h2>Alta de jugador</h2>
                    <p>Crea tu cuenta para ligas por parejas o Pulls con inscripción individual.</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= sanitizeOutput($error) ?></div>
                <?php endif; ?>
                
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="accion" value="registrar">
                
                <div class="form-row">
                    <div class="form-group form-col modern-input">
                        <label for="nombre">Nombre</label>
                        <input type="text" id="nombre" name="nombre" value="<?= $nombre ?>" placeholder="Tu nombre" required>
                    </div>
                    <div class="form-group form-col modern-input">
                        <label for="apellidos">Apellidos</label>
                        <input type="text" id="apellidos" name="apellidos" value="<?= $apellidos ?>" placeholder="Tus apellidos" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group form-col modern-input">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" name="email" value="<?= $email ?>" placeholder="ejemplo@correo.com" required pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.(com|es|net|org|info|eu|cat|gal|eus|edu|COM|ES|NET|ORG|INFO|EU|CAT|GAL|EUS|EDU)$" title="Introduce un correo válido terminado en .com, .es, .net, etc.">
                    </div>
                    <div class="form-group form-col modern-input">
                        <label for="telefono">Teléfono</label>
                        <input type="tel" id="telefono" name="telefono" value="<?= $telefono ?>" placeholder="600000000" required pattern="[67][0-9]{8}" title="Introduce un número de teléfono móvil español válido de 9 dígitos (debe empezar por 6 o 7)">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group form-col modern-input">
                        <label for="password">Contraseña (Mín. 8 char.)</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" placeholder="••••••••" required minlength="8">
                            <span class="toggle-password" title="Mostrar/Ocultar contraseña">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                    <line x1="1" y1="1" x2="23" y2="23"></line>
                                </svg>
                            </span>
                        </div>
                    </div>
                    <div class="form-group form-col modern-input">
                        <label for="confirm_password">Confirmar Contraseña</label>
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
                </div>
                
                <div class="form-group pull-modalidad-box">
                    <label class="pull-modalidad-label" for="modalidad_pull">
                        <input type="checkbox" id="modalidad_pull" name="modalidad_pull" value="1">
                        <span>
                            <strong>Modalidad Pull</strong><br>
                            <small class="pull-modalidad-hint">Inscripción individual. Juega, rota de pista, cambia de pareja y sube de nivel.</small><br>
                            <small class="pull-modalidad-note">Marca esta opción si no quieres la liga por parejas fijas y prefieres jugar pull.</small>
                        </span>
                    </label>
                </div>

                <div class="form-group legal-consent-box">
                    <label class="legal-consent-label" for="acepta_terminos">
                        <input type="checkbox" id="acepta_terminos" name="acepta_terminos" value="1" required>
                        <span>He leído y acepto los <a href="legal/terminos.php" target="_blank" rel="noopener noreferrer">Términos de Uso</a> y la <a href="legal/privacidad.php" target="_blank" rel="noopener noreferrer">Política de Privacidad</a>.</span>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-modern" style="margin-top: 1.5rem;">Siguiente paso</button>
                
                <div class="register-prompt">
                    <span>¿Ya tienes cuenta?</span>
                    <a href="<?= htmlspecialchars(appUrl('login.php')) ?>" class="register-link">Inicia sesión</a>
                </div>
            </form>
        
        <!-- VISTA 2: INTRODUCIR EL PIN OTP -->
        <?php elseif ($vista_actual === 'verificacion'): ?>
            <div class="auth-verify-card">
            <form method="POST" action="<?= htmlspecialchars(appUrl('registro.php')) ?>" class="auth-form modern-form auth-form--verify">
                
                <div class="form-header">
                    <h2>Verifica tu Correo</h2>
                    <p>Hemos enviado un código de 6 dígitos a <strong class="verify-email"><?= sanitizeOutput($email_registrado) ?></strong>.</p>
                </div>
                
                <?php if ($mensaje_rescate): ?>
                    <div class="alert alert-info-verify">
                        <?= sanitizeOutput($mensaje_rescate) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= sanitizeOutput($error) ?></div>
                <?php endif; ?>
                
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="email_verificacion" value="<?= sanitizeOutput($email_registrado) ?>">
                
                <div class="form-group">
                    <input type="text" name="pin" id="pin_verificacion" class="pin-input" maxlength="6" pattern="\d{6}" inputmode="numeric" placeholder="000000" required autocomplete="one-time-code" autofocus>
                </div>
                
                <button type="submit" name="accion" value="verificar" class="btn btn-primary btn-block btn-modern">Verificar y Entrar</button>
                <?php if (emailVerificationEnabled()): ?>
                <button type="submit" name="accion" value="reenviar_pin" class="btn btn-secondary btn-block btn-modern" formnovalidate>Reenviar código por email</button>
                <?php endif; ?>
            </form>
            </div>
        
        <!-- VISTA 3: MENSAJE DE ÉXITO FINAL DINÁMICO -->
        <?php elseif ($vista_actual === 'exito'): ?>
            <div class="auth-form modern-form" style="text-align: center;">
                <div class="form-header" style="margin-bottom: 0;">
                    <h2 style="color: #28a745; margin-bottom: 15px;">¡Correo Verificado!</h2>
                    <p>Tu cuenta ha sido validada y está <strong>activa</strong>.</p>
                </div>
                
                <!-- REDIRECCIÓN SEGURA AL TORNEO -->
                <?php if ($redirect_seguro): ?>
                    <div class="alert" style="background-color: #d4edda; color: #155724; border-left: 4px solid #28a745; margin: 25px 0;">
                        Tu perfil está listo. Ya puedes continuar con tu inscripción.
                    </div>
                    <a href="<?= sanitizeOutput($redirect_seguro) ?>" class="btn btn-primary btn-block btn-modern">Continuar inscripción</a>
                
                <!-- REDIRECCIONES NORMALES -->
                <?php elseif (tipoPerfilEsPull($_SESSION['tipo_perfil'] ?? null)): ?>
                    <div class="alert" style="background-color: #d4edda; color: #155724; border-left: 4px solid #28a745; margin: 25px 0;">
                        Tu perfil Pull está listo. Apúntate a un pozo con inscripción individual.
                    </div>
                    <a href="<?= htmlspecialchars(appUrl('usuario/pulls.php')) ?>" class="btn btn-primary btn-block btn-modern">Ver Pulls abiertas</a>
                <?php else: ?>
                    <div class="alert" style="background-color: #f8f9fa; color: #383d41; border-left: 4px solid #6c757d; border: 1px solid #e2e8f0; margin: 25px 0;">
                        Estás a la espera de que el administrador te asigne una categoría para poder competir en la liga.
                    </div>
                    <a href="<?= htmlspecialchars(appUrl('usuario/dashboard.php')) ?>" class="btn btn-primary btn-block btn-modern">Ir a mi Perfil</a>
                <?php endif; ?>
                
            </div>
        <?php endif; ?>

    </div>

    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                const loader = document.getElementById('padel-loader');
                if (loader) loader.classList.add('fade-out');
            }, 1500); 
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
    <script src="assets/js/app.js?v=<?= filemtime('assets/js/app.js') ?>"></script>
</body>
</html>