<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/app_errors.php';
require_once '../includes/usuario_layout.php';
require_once '../includes/usuario_perfil_service.php';
require_once '../includes/usuario_avatar.php';
require_once '../includes/app_paths.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$mensaje = '';
$error = '';
$csrf = generateCSRFToken();
$fotoDisponible = usuarioPerfilFotoColumnDisponible($db);

$usuario = usuarioPerfilFetch($db, $usuario_id);
if (!$usuario) {
    die('Usuario no encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'actualizar_datos') {
        $result = usuarioPerfilActualizarDatos(
            $db,
            $usuario_id,
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['apellidos'] ?? ''),
            (string) ($_POST['telefono'] ?? '')
        );
        if ($result['ok']) {
            $mensaje = $result['message'];
            $_SESSION['nombre'] = trim((string) $_POST['nombre']);
            $usuario = usuarioPerfilFetch($db, $usuario_id);
            usuarioAvatarRefreshSession($db, $usuario_id);
        } else {
            $error = $result['message'];
        }
    }

    if ($accion === 'cambiar_password') {
        $result = usuarioPerfilCambiarPassword(
            $db,
            $usuario_id,
            (string) ($_POST['password_actual'] ?? ''),
            (string) ($_POST['password_nueva'] ?? ''),
            (string) ($_POST['password_nueva2'] ?? '')
        );
        if ($result['ok']) {
            $mensaje = $result['message'];
            if (array_key_exists('password_changed_at', $result)) {
                $_SESSION['password_changed_at'] = $result['password_changed_at'];
            }
        } else {
            $error = $result['message'];
        }
    }

    if ($accion === 'subir_foto' && isset($_FILES['foto'])) {
        $result = usuarioPerfilSubirFoto($db, $usuario_id, $_FILES['foto']);
        if ($result['ok']) {
            $mensaje = $result['message'];
            $usuario = usuarioPerfilFetch($db, $usuario_id);
            usuarioAvatarRefreshSession($db, $usuario_id);
        } else {
            $error = $result['message'];
        }
    }

    if ($accion === 'quitar_foto') {
        $result = usuarioPerfilQuitarFoto($db, $usuario_id);
        if ($result['ok']) {
            $mensaje = $result['message'];
            $usuario = usuarioPerfilFetch($db, $usuario_id);
            usuarioAvatarRefreshSession($db, $usuario_id);
        } else {
            $error = $result['message'];
        }
    }

    if ($accion === 'eliminar_cuenta') {
        $confirmado = !empty($_POST['confirmar_baja']);
        $result = usuarioPerfilEliminarCuenta(
            $db,
            $usuario_id,
            (string) ($_POST['password_baja'] ?? ''),
            $confirmado
        );
        if ($result['ok']) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            redirectTo('index.php?msg=cuenta_eliminada');
        }
        $error = $result['message'];
    }
}

$fotoUrl = $fotoDisponible ? usuarioPerfilFotoUrl($usuario['foto_perfil'] ?? null) : null;
$total_notificaciones = 0;

usuarioLayoutStart('Mi perfil', $total_notificaciones);
usuarioPageHeading('Mi perfil');
usuarioPanelSubtitle('Gestiona tu foto, datos de contacto, contraseña y opciones de cuenta.');

if ($mensaje !== '') {
    usuarioAlert($mensaje, 'success');
}
if ($error !== '') {
    usuarioAlert($error, 'error');
}

if (!$fotoDisponible) {
    usuarioAlert(
        'Falta la columna foto_perfil en la base de datos. Hay que actualizar el esquema para activar la foto de perfil.',
        'warning'
    );
}
?>

<div class="perfil-grid">
    <?php usuarioCardOpen('Foto de perfil'); ?>
        <div class="perfil-avatar-block">
            <div class="perfil-avatar-preview" id="perfil-avatar-preview">
                <?php if ($fotoUrl): ?>
                    <img src="<?= htmlspecialchars($fotoUrl) ?>" alt="" class="perfil-avatar-img" id="perfil-avatar-img" width="120" height="120">
                <?php else: ?>
                    <span class="perfil-avatar-iniciales" id="perfil-avatar-iniciales"><?= htmlspecialchars(strtoupper(substr($usuario['nombre'], 0, 1) . substr($usuario['apellidos'], 0, 1))) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($fotoDisponible): ?>
                <div class="perfil-upload-col">
                    <p class="perfil-upload-lead">Añade una foto cuadrada; se mostrará en círculo en tu panel.</p>
                    <form method="post" enctype="multipart/form-data" class="perfil-form perfil-form-foto" id="form-subir-foto">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="accion" value="subir_foto">
                        <div class="perfil-file-picker">
                            <input type="file" name="foto" id="foto" class="perfil-file-input" accept="image/jpeg,image/png,image/webp" required>
                            <label for="foto" class="perfil-file-label">
                                <span class="perfil-file-label-title">Pulse aquí para seleccionar la foto</span>
                                <span class="perfil-file-label-hint">JPG, PNG o WebP · máximo 2 MB</span>
                            </label>
                            <p class="perfil-file-name" id="perfil-file-name" aria-live="polite">Ningún archivo seleccionado</p>
                        </div>
                    </form>
                    <div class="perfil-upload-actions">
                        <button type="submit" form="form-subir-foto" class="btn btn-primary btn-sm perfil-btn-upload" id="btn-subir-foto" disabled>Subir foto</button>
                        <?php if ($fotoUrl): ?>
                            <form method="post" class="perfil-form-quitar-foto" onsubmit="return confirm('¿Quitar la foto de perfil?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="quitar_foto">
                                <button type="submit" class="btn btn-secondary btn-sm">Quitar foto</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php usuarioCardClose(); ?>

    <?php usuarioCardOpen('Datos personales'); ?>
        <form method="post" class="perfil-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="actualizar_datos">
            <div class="form-row">
                <div class="form-group">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" maxlength="100" required value="<?= htmlspecialchars($usuario['nombre']) ?>">
                </div>
                <div class="form-group">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" id="apellidos" name="apellidos" maxlength="100" required value="<?= htmlspecialchars($usuario['apellidos']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="email">Correo electrónico</label>
                <input type="email" id="email" value="<?= htmlspecialchars($usuario['email']) ?>" disabled class="input-readonly">
                <p class="form-hint">El email no se puede cambiar desde aquí. Contacta con la organización si necesitas actualizarlo.</p>
            </div>
            <div class="form-group">
                <label for="telefono">Teléfono</label>
                <input type="text" id="telefono" name="telefono" maxlength="15" value="<?= htmlspecialchars($usuario['telefono'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Miembro desde</label>
                <p class="perfil-readonly-value"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $usuario['fecha_registro']))) ?></p>
            </div>
            <button type="submit" class="btn btn-primary">Guardar datos</button>
        </form>
    <?php usuarioCardClose(); ?>

    <?php usuarioCardOpen('Cambiar contraseña'); ?>
        <form method="post" class="perfil-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="cambiar_password">
            <div class="form-group">
                <label for="password_actual">Contraseña actual</label>
                <input type="password" id="password_actual" name="password_actual" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="password_nueva">Nueva contraseña</label>
                <input type="password" id="password_nueva" name="password_nueva" autocomplete="new-password" minlength="8" required>
            </div>
            <div class="form-group">
                <label for="password_nueva2">Repetir nueva contraseña</label>
                <input type="password" id="password_nueva2" name="password_nueva2" autocomplete="new-password" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
        </form>
    <?php usuarioCardClose(); ?>

    <?php usuarioCardOpen('Baja de cuenta', 'danger'); ?>
        <div class="perfil-zona-peligro">
            <p class="perfil-baja-lead">
                Si quieres <strong>eliminar tu cuenta de forma permanente</strong>, lee lo siguiente. La acción no se puede deshacer:
                se borrarán tu usuario, pareja, inscripciones, pulls e historial en la app
                (<a href="<?= htmlspecialchars(appUrl('legal/terminos.php')) ?>">política de baja</a>).
            </p>
            <ol class="perfil-baja-pasos">
                <li>Abre el panel «Eliminar mi cuenta».</li>
                <li>Marca que entiendes que la baja es definitiva.</li>
                <li>Escribe tu contraseña y confirma.</li>
            </ol>
            <details class="perfil-baja-panel" id="perfil-baja-panel">
                <summary class="perfil-baja-summary">Eliminar mi cuenta</summary>
                <form method="post" class="perfil-form-baja" id="form-eliminar-cuenta">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion" value="eliminar_cuenta">
                    <label class="perfil-checkbox-compact">
                        <input type="checkbox" name="confirmar_baja" value="1" required id="confirmar_baja">
                        <span>Entiendo que la baja es permanente e irreversible.</span>
                    </label>
                    <div class="perfil-baja-password-row">
                        <label for="password_baja" class="perfil-baja-password-label">Contraseña</label>
                        <input type="password" id="password_baja" name="password_baja" class="perfil-baja-password-input" autocomplete="current-password" required placeholder="Tu contraseña actual">
                        <button type="submit" class="btn btn-danger btn-sm perfil-btn-eliminar" id="btn-eliminar-cuenta">Eliminar cuenta</button>
                    </div>
                </form>
            </details>
        </div>
    <?php usuarioCardClose(); ?>
</div>

<?php
$inlineScript = <<<'JS'
(function () {
    var fotoInput = document.getElementById('foto');
    var fileNameEl = document.getElementById('perfil-file-name');
    var btnSubir = document.getElementById('btn-subir-foto');
    var preview = document.getElementById('perfil-avatar-preview');
    if (fotoInput && fileNameEl && btnSubir) {
        fotoInput.addEventListener('change', function () {
            var file = fotoInput.files && fotoInput.files[0];
            if (!file) {
                fileNameEl.textContent = 'Ningún archivo seleccionado';
                btnSubir.disabled = true;
                return;
            }
            fileNameEl.textContent = file.name;
            btnSubir.disabled = false;
            if (file.type.indexOf('image/') === 0 && preview) {
                var reader = new FileReader();
                reader.onload = function (ev) {
                    preview.innerHTML = '<img src="' + ev.target.result + '" alt="" class="perfil-avatar-img" width="120" height="120">';
                };
                reader.readAsDataURL(file);
            }
        });
    }
    document.getElementById('form-eliminar-cuenta')?.addEventListener('submit', function (e) {
        if (!document.getElementById('confirmar_baja')?.checked) {
            return;
        }
        if (!confirm('¿Eliminar tu cuenta de forma permanente? No hay vuelta atrás.')) {
            e.preventDefault();
        }
    });
})();
JS;
usuarioLayoutEnd($inlineScript);
