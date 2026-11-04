<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/pair_service.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/padel_guardrails.php';
require_once '../includes/usuario_layout.php';
require_once '../includes/app_paths.php';

requireLogin();
$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$mensaje = '';
$error = '';
$pairs = pairService($db);

if (!usuarioEsquemaPadel($db)) {
    die('Esta sección requiere el esquema de pádel.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'invitar') {
        $invitadoId = (int) ($_POST['invitado_usuario_id'] ?? 0);
        if ($invitadoId > 0) {
            $result = $pairs->enviarInvitacionAUsuario($usuario_id, $invitadoId);
        } else {
            $result = ['ok' => false, 'message' => 'Selecciona un jugador de la lista.'];
        }
        if ($result['ok']) {
            $mensaje = $result['message'];
        } else {
            $error = $result['message'];
        }
    }

    if ($accion === 'responder_invitacion') {
        $result = $pairs->responderInvitacion(
            $usuario_id,
            (int) ($_POST['invitacion_id'] ?? 0),
            (string) ($_POST['respuesta'] ?? '')
        );
        if ($result['ok']) {
            $mensaje = $result['message'];
        } else {
            $error = $result['message'];
        }
    }

    if ($accion === 'disolver') {
        $result = $pairs->disolverParejaDeUsuario($usuario_id);
        if ($result['ok']) {
            $mensaje = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

$parejaInfo = fetchParejaActivaDeUsuario($db, $usuario_id);
$bloqueoDisolucionUi = null;
if ($parejaInfo) {
    $bloqueoDisolucionUi = parejaMotivoBloqueoDisolucion($db, (int) $parejaInfo['id']);
}
$miembros = $parejaInfo ? fetchMiembrosPareja($db, (int) $parejaInfo['id']) : [];
$invitaciones = fetchInvitacionesParejaPendientes($db, $usuario_id);
$total_notificaciones = count($invitaciones);
$csrf = generateCSRFToken();

usuarioLayoutStart('Mi pareja', $total_notificaciones);
usuarioPageHeading('Mi pareja');
if ($mensaje) {
    usuarioAlert($mensaje, 'success');
}
if ($error) {
    usuarioAlert($error, 'error');
}
if (!empty($parejaInfo['nota_admin'])) {
    usuarioAlert('<strong>Mensaje del administrador</strong><br>' . nl2br(htmlspecialchars((string) $parejaInfo['nota_admin'])), 'info', false);
}
?>

<?php if ($parejaInfo): ?>
    <?php usuarioCardOpen($parejaInfo['etiqueta']); ?>
        <p>Estado: <?php usuarioBadge($parejaInfo['estado'], $parejaInfo['estado'] === 'activa' ? 'success' : 'info'); ?></p>
        <ul class="list-members">
            <?php foreach ($miembros as $m): ?>
                <li><?= htmlspecialchars($m['nombre'] . ' ' . $m['apellidos']) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($bloqueoDisolucionUi): ?>
            <?php usuarioAlert($bloqueoDisolucionUi, 'warning'); ?>
        <?php else: ?>
            <form method="POST" class="form-stack" onsubmit="return confirm('¿Disolver la pareja?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="disolver">
                <button type="submit" class="btn btn-danger">Disolver pareja</button>
            </form>
        <?php endif; ?>
    <?php usuarioCardClose(); ?>
<?php else: ?>
    <?php if ($invitaciones): ?>
        <?php usuarioCardOpen('Invitaciones pendientes', 'success'); ?>
            <?php foreach ($invitaciones as $inv): ?>
                <p><strong><?= htmlspecialchars($inv['nombre'] . ' ' . $inv['apellidos']) ?></strong> te invita a formar pareja.</p>
                <form method="POST" class="card-actions">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion" value="responder_invitacion">
                    <input type="hidden" name="invitacion_id" value="<?= (int) $inv['id'] ?>">
                    <button name="respuesta" value="aceptar" class="btn btn-primary">Aceptar</button>
                    <button name="respuesta" value="rechazar" class="btn btn-secondary">Rechazar</button>
                </form>
            <?php endforeach; ?>
        <?php usuarioCardClose(); ?>
    <?php endif; ?>
    <?php usuarioCardOpen('Buscar compañero'); ?>
        <p class="text-muted-inline">Escribe nombre y/o apellidos. Solo aparecen jugadores <strong>sin pareja</strong> que coincidan con tu búsqueda.</p>
        <form method="POST" class="form-stack" id="form-invitacion-pareja">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="invitar">
            <input type="hidden" name="invitado_usuario_id" id="invitado_usuario_id" value="">
            <label for="buscar_companero" class="sr-only">Buscar jugador</label>
            <input type="search" id="buscar_companero" autocomplete="off" placeholder="Ej. María López o López" class="input-usuario" aria-describedby="companero-busqueda-ayuda">
            <p id="companero-busqueda-ayuda" class="text-muted-inline" style="font-size:0.85rem;margin:6px 0 0;">Mínimo 2 caracteres.</p>
            <ul id="companero-resultados" class="companero-resultados" role="listbox" aria-label="Jugadores disponibles"></ul>
            <p id="companero-seleccion" class="companero-seleccion" hidden></p>
            <button type="submit" class="btn btn-primary" id="btn-enviar-invitacion" disabled>Enviar invitación</button>
        </form>
        <script>
        (function () {
            var url = <?= json_encode(appUrl('usuario/buscar_companero.php'), JSON_UNESCAPED_UNICODE) ?>;
            var input = document.getElementById('buscar_companero');
            var list = document.getElementById('companero-resultados');
            var hidden = document.getElementById('invitado_usuario_id');
            var selLabel = document.getElementById('companero-seleccion');
            var btn = document.getElementById('btn-enviar-invitacion');
            var timer = null;
            var selectedLabel = '';

            function clearSelection() {
                hidden.value = '';
                selectedLabel = '';
                selLabel.hidden = true;
                selLabel.textContent = '';
                btn.disabled = true;
            }

            function selectUser(id, label) {
                hidden.value = String(id);
                selectedLabel = label;
                selLabel.textContent = 'Invitarás a: ' + label;
                selLabel.hidden = false;
                btn.disabled = false;
                list.innerHTML = '';
            }

            function renderEmpty(msg) {
                list.innerHTML = '<li class="companero-resultados__vacio">' + msg + '</li>';
            }

            input.addEventListener('input', function () {
                clearSelection();
                var q = input.value.trim();
                if (timer) clearTimeout(timer);
                if (q.length < 2) {
                    list.innerHTML = '';
                    return;
                }
                timer = setTimeout(function () {
                    fetch(url + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var users = data.usuarios || [];
                            list.innerHTML = '';
                            if (!users.length) {
                                renderEmpty('Ningún jugador disponible coincide con esa búsqueda.');
                                return;
                            }
                            users.forEach(function (u) {
                                var li = document.createElement('li');
                                li.setAttribute('role', 'option');
                                li.tabIndex = 0;
                                li.className = 'companero-resultados__item';
                                li.textContent = u.label;
                                li.addEventListener('click', function () {
                                    input.value = u.label;
                                    selectUser(u.id, u.label);
                                });
                                li.addEventListener('keydown', function (e) {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        input.value = u.label;
                                        selectUser(u.id, u.label);
                                    }
                                });
                                list.appendChild(li);
                            });
                        })
                        .catch(function () {
                            renderEmpty('No se pudo cargar la búsqueda. Inténtalo de nuevo.');
                        });
                }, 280);
            });

            document.getElementById('form-invitacion-pareja').addEventListener('submit', function (e) {
                if (!hidden.value) {
                    e.preventDefault();
                    alert('Elige un jugador de la lista antes de enviar.');
                }
            });
        })();
        </script>
    <?php usuarioCardClose(); ?>
<?php endif; ?>

<?php usuarioLayoutEnd(); ?>
