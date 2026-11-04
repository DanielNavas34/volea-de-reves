<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/pull_service.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/usuario_layout.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$esquemaPadel = usuarioEsquemaPadel($db);
$mis_pulls = [];

if ($esquemaPadel) {
    $mis_pulls = fetchMisPullsUsuario($db, $usuario_id);
}

$total_notificaciones = 0;

usuarioLayoutStart('Mis Pulls', $total_notificaciones);
usuarioPageHeading('Mis Pulls');
?>

<div class="panel-toolbar">
    <a href="pulls.php" class="btn btn-secondary">Ver Pulls abiertas</a>
</div>

<?php if ($mis_pulls !== []): ?>
    <div class="dashboard-cards">
        <?php foreach ($mis_pulls as $p): ?>
            <div class="card usuario-card">
                <h3 class="card-title"><?= htmlspecialchars($p['titulo']) ?></h3>
                <p><?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?> – <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?></p>
                <?php if ($esquemaPadel): ?>
                    <p>Inscripción individual · <?= htmlspecialchars($p['jugador_nombre'] ?? '') ?></p>
                    <p>Estado:
                    <?php
                    $est = (string) ($p['estado'] ?? '');
                    $badgeEst = match ($est) {
                        'confirmada' => 'success',
                        'pendiente' => 'warning',
                        'rechazada', 'baja' => 'danger',
                        default => 'muted',
                    };
                    usuarioBadge($est, $badgeEst);
                    ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?php usuarioCardOpen('Sin inscripciones'); ?>
        <p>Aún no te has apuntado a ninguna Pull. <a href="pulls.php">Explorar Pulls abiertas</a>.</p>
    <?php usuarioCardClose(); ?>
<?php endif; ?>

<?php usuarioLayoutEnd(); ?>
