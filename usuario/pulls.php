<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/pull_service.php';
require_once '../includes/usuario_layout.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$esquemaPadel = usuarioEsquemaPadel($db);
$mensaje = '';
$error = '';
$csrf = generateCSRFToken();

if (!$esquemaPadel) {
    header('Location: ../pulls.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inscribir_pull'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Error CSRF.');
    }
    $compId = (int) ($_POST['competicion_id'] ?? 0);
    if ($compId <= 0) {
        $error = 'Pull no válida.';
    } else {
        try {
            inscribirUsuarioEnPull($db, $usuario_id, $compId);
            $mensaje = 'Inscripción individual enviada. El organizador la confirmará.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException) {
            $error = 'Ya estás inscrito en esta Pull o no se pudo completar la inscripción.';
        } catch (RuntimeException $e) {
            require_once '../includes/app_errors.php';
            $error = appSafeExceptionMessage($e, 'No se pudo completar la inscripción.');
        }
    }
}

$pulls = fetchPullsAbiertas($db);
$inscritos = fetchPullIdsInscritoUsuario($db, $usuario_id);
$total_notificaciones = 0;

usuarioLayoutStart('Pulls abiertas', $total_notificaciones);
usuarioPageHeading('Pulls abiertas');
usuarioPanelSubtitle('Pozo / americano: entras solo, juegas partidos cortos y rotas de pista y de pareja.');

if ($mensaje) {
    usuarioAlert($mensaje, 'success');
}
if ($error) {
    usuarioAlert($error, 'error');
}
?>

<div class="dashboard-cards">
    <?php foreach ($pulls as $p): ?>
        <div class="card usuario-card">
            <h3 class="card-title"><?= htmlspecialchars($p['titulo']) ?></h3>
            <p><?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?> – <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?></p>
            <p class="text-muted-inline"><?= htmlspecialchars(mb_substr(strip_tags($p['descripcion'] ?? ''), 0, 140)) ?>…</p>
            <?php if (in_array((int) $p['id'], $inscritos, true)): ?>
                <?php usuarioBadge('Inscrito', 'success'); ?>
            <?php else: ?>
                <form method="POST" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="competicion_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" name="inscribir_pull" class="btn btn-primary">Inscribirme (individual)</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($pulls === []): ?>
        <?php usuarioCardOpen(''); ?>
            <p>No hay Pulls abiertas ahora mismo. Vuelve pronto o consulta con tu club.</p>
        <?php usuarioCardClose(); ?>
    <?php endif; ?>
</div>

<?php usuarioLayoutEnd(); ?>
