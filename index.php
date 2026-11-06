<?php
require_once 'includes/bootstrap.php';
appEnsureSession();

require_once 'config/database.php';
require_once 'includes/public_home_helpers.php';
require_once 'includes/padel_ui_helpers.php';
require_once 'includes/panel_url.php';
require_once 'includes/brand.php';
require_once 'includes/usuario_avatar.php';

$db = getDBConnection();
$homeAvatarCtx = !empty($_SESSION['usuario_id']) ? usuarioAvatarContextForSession($db) : null;
$instalacionPendiente = !voleaPadelInstallReadyOrEmpty($db);
$home = loadPublicHomePage($db);

$usaEsquemaPadel = $home['usaEsquemaPadel'];
$pull_alerta = $home['torneo_alerta'];
$grupos = $home['grupos'];
$ultimosResultados = $home['ultimosResultados'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Volea de revés — ligas, Pulls y clasificaciones de pádel.">
    <?php renderBrandHead('root'); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body class="public-app bg-padel">

<?php include __DIR__ . '/includes/navbar_public.php'; ?>

<main class="public-main">
    <section class="public-hero card" aria-labelledby="hero-title">
        <p class="public-hero-kicker">App de competición</p>
        <h1 id="hero-title" class="public-hero-title">Volea de revés</h1>
        <p class="public-hero-lead">Ligas por parejas y Pulls con inscripción individual: rotación de pista y compañeros.</p>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cuenta_eliminada'): ?>
            <div class="alert alert-success public-hero-alert" role="status">
                Tu cuenta ha sido eliminada permanentemente.
            </div>
        <?php endif; ?>

        <?php if ($instalacionPendiente): ?>
            <div class="alert alert-warning public-hero-alert" role="alert">
                Base de datos no configurada. <a href="instalacion_requerida.php">Ver instrucciones</a>
            </div>
        <?php endif; ?>

        <?php if ($pull_alerta): ?>
            <div class="alert alert-info public-hero-alert" role="status">
                Pull abierta: <strong><?= htmlspecialchars($pull_alerta['titulo']) ?></strong>.
                <a href="pulls.php">Ver Pulls</a>
            </div>
        <?php endif; ?>

        <div class="hero-auth-buttons public-hero-actions">
            <?php if (isset($_SESSION['usuario_id'])): ?>
                <?php if ($homeAvatarCtx !== null): ?>
                    <?= usuarioSaludoConAvatarHtml($homeAvatarCtx, 44, 'public-session-chip usuario-saludo-chip') ?>
                <?php else: ?>
                    <span class="public-session-chip">Hola, <?= htmlspecialchars($_SESSION['nombre'] ?? 'jugador') ?></span>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(panelUrlForSession()) ?>" class="btn btn-primary">Ir a mi panel</a>
                <a href="<?= htmlspecialchars(appLogoutUrl()) ?>" class="btn btn-danger-soft">Cerrar sesión</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">Iniciar sesión</a>
                <a href="registro.php" class="btn btn-secondary">Registrarse</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($usaEsquemaPadel && $ultimosResultados !== []): ?>
        <section class="card public-section results-section" aria-labelledby="results-heading">
            <h2 id="results-heading" class="public-section-title">Últimos resultados</h2>
            <p class="text-muted public-section-sub">Partidos validados en ligas activas.</p>
            <div class="results-list">
                <?php foreach ($ultimosResultados as $res): ?>
                    <?php $ganoA = (int) $res['ganador_pareja_id'] === (int) $res['pareja_a_id']; ?>
                    <article class="result-card">
                        <div class="meta"><?= htmlspecialchars($res['grupo_label']) ?> · <?= date('d/m/Y', strtotime($res['fecha'])) ?></div>
                        <div class="matchup enfrentamiento-parejas">
                            <span class="<?= $ganoA ? 'winner-name' : '' ?>"><?= padelHtmlEtiquetaPareja($res['etiqueta_a']) ?></span>
                            <span class="vs-badge vs-badge--inline">vs</span>
                            <span class="<?= !$ganoA ? 'winner-name' : '' ?>"><?= padelHtmlEtiquetaPareja($res['etiqueta_b']) ?></span>
                        </div>
                        <div class="score"><?= htmlspecialchars($res['resultado']) ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="public-section" aria-labelledby="standings-heading">
        <h2 id="standings-heading" class="public-section-title public-section-title--light">Clasificación</h2>
        <div class="public-standings-grid">
            <?php if ($grupos !== []): ?>
                <?php foreach ($grupos as $grupo): ?>
                    <?php $clasificacion = fetchClasificacionHomePublic($db, $grupo, $usaEsquemaPadel); ?>
                    <article class="card public-standings-card">
                        <h3 class="public-standings-card-title"><?php
                            if ($usaEsquemaPadel && !empty($grupo['competicion_nombre'])) {
                                echo htmlspecialchars($grupo['competicion_nombre'] . ' — ' . $grupo['nombre']);
                            } else {
                                echo htmlspecialchars($grupo['nombre']);
                            }
                        ?></h3>
                        <div class="table-responsive standings-scroll">
                            <table class="<?= $usaEsquemaPadel ? padelLeagueTableClass() : 'league-table' ?>">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="player-name"><?= $usaEsquemaPadel ? 'Pareja' : 'Jugador' ?></th>
                                    <th>PJ</th>
                                    <th>V</th>
                                    <th>PTS</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($clasificacion !== []): ?>
                                    <?php foreach ($clasificacion as $index => $jugador): ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td class="player-name col-pareja"><?php
                                                if ($usaEsquemaPadel) {
                                                    echo padelHtmlEtiquetaPareja($jugador['nombre_lista'] ?? 'Pareja');
                                                } else {
                                                    echo htmlspecialchars($jugador['nombre'] . ' ' . $jugador['apellidos']);
                                                }
                                            ?></td>
                                            <td><?= $jugador['partidos_jugados'] ?></td>
                                            <td><?= $jugador['partidos_ganados'] ?></td>
                                            <td><strong><?= $jugador['puntos'] ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5">La liga aún no ha comenzado.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <article class="card public-empty-state">
                    <h3>Próximamente</h3>
                    <p class="text-muted">Las categorías de liga se publicarán aquí en cuanto estén activas.</p>
                </article>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/public_footer.php'; ?>
<script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
