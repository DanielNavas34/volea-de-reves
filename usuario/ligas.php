<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/liga_service.php';
require_once '../includes/competition_service.php';
require_once '../includes/usuario_layout.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
if (!usuarioEsquemaPadel($db)) {
    die('Esta sección requiere el esquema de pádel.');
}
if (($_SESSION['tipo_perfil'] ?? 'liga') !== 'liga') {
    require_once __DIR__ . '/../includes/app_paths.php';
    redirectTo('usuario/pulls.php');
    exit;
}

$mensaje = '';
$error = '';
$csrf = generateCSRFToken();
$parejaId = fetchParejaIdUsuario($db, $usuario_id);
$competition = leagueCompetitionService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inscribir_liga'])) {
    requirePostCsrf();
    $grupoId = (int) ($_POST['grupo_id'] ?? 0);
    if (!$parejaId) {
        $error = 'Necesitas una pareja activa para inscribirte.';
    } elseif ($grupoId <= 0) {
        $error = 'Grupo no válido.';
    } else {
        try {
            $competition->solicitarInscripcion($parejaId, $grupoId);
            $mensaje = 'Solicitud enviada. El administrador confirmará tu inscripción.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = 'No se pudo registrar la inscripción.';
        }
    }
}

$opciones = ligaFetchOpcionesInscripcionUsuario($db);
$estadoPorGrupo = [];
if ($parejaId) {
    foreach ($opciones as $op) {
        $ins = ligaFetchInscripcionParejaGrupo($db, $parejaId, (int) $op['grupo_id']);
        if ($ins) {
            $estadoPorGrupo[(int) $op['grupo_id']] = $ins['estado'];
        }
    }
}

$total_notificaciones = 0;

usuarioLayoutStart('Ligas abiertas', $total_notificaciones);
usuarioPageHeading('Ligas abiertas');
if ($mensaje) {
    usuarioAlert($mensaje, 'success');
}
if ($error) {
    usuarioAlert($error, 'error');
}
if (!$parejaId) {
    usuarioAlert('Forma una pareja activa antes de inscribirte. <a href="pareja.php">Ir a Mi pareja</a>', 'warning', false);
} else {
    usuarioPanelSubtitle('Inscripción como: ' . fetchParejaEtiqueta($db, $parejaId));
}
?>

<div class="dashboard-cards">
    <?php foreach ($opciones as $op): ?>
        <?php
        $gid = (int) $op['grupo_id'];
        $estadoIns = $estadoPorGrupo[$gid] ?? null;
        $labelGrupo = $op['modalidad_liga'] === 'grupos'
            ? $op['liga_nombre'] . ' · ' . $op['grupo_nombre']
            : $op['liga_nombre'];
        ?>
        <div class="card usuario-card">
            <h3 class="card-title"><?= htmlspecialchars($labelGrupo) ?></h3>
            <?php if ($op['modalidad_liga'] === 'grupos'): ?>
                <p class="text-muted-inline">Grupo: <?= htmlspecialchars($op['grupo_nombre']) ?></p>
            <?php endif; ?>
            <?php if ($estadoIns): ?>
                <p>Estado de tu inscripción:
                <?php
                $badge = match ($estadoIns) {
                    'confirmada' => 'success',
                    'pendiente' => 'warning',
                    'rechazada', 'baja' => 'danger',
                    default => 'muted',
                };
                usuarioBadge($estadoIns, $badge);
                ?>
                </p>
            <?php elseif ($parejaId): ?>
                <form method="POST" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="grupo_id" value="<?= $gid ?>">
                    <button type="submit" name="inscribir_liga" class="btn btn-primary">Inscribir pareja</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($opciones === []): ?>
        <?php usuarioCardOpen('Sin ligas publicadas'); ?>
            <p class="text-muted-inline">No hay ligas en estado abierto o en curso con grupos configurados.</p>
        <?php usuarioCardClose(); ?>
    <?php endif; ?>
</div>

<?php usuarioLayoutEnd(); ?>
