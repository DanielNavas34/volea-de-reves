<?php
require_once 'includes/bootstrap.php';
appEnsureSession();

require_once 'config/database.php';
require_once 'includes/competiciones_helpers.php';
require_once 'includes/perfil_helpers.php';
require_once 'includes/brand.php';

$db = getDBConnection();
$pulls = [];
$instalacionPendiente = !voleaPadelInstallReadyOrEmpty($db);

if (!$instalacionPendiente) {
    $tipoIn = competicionTipoPullSqlIn();
    $pulls = $db->query(
        "SELECT id, nombre AS titulo, descripcion, cartel_ruta AS cartel, fecha_inicio, fecha_fin,
                CASE estado
                    WHEN 'abierta' THEN 'abierto'
                    WHEN 'cancelada' THEN 'cancelado'
                    WHEN 'finalizada' THEN 'cerrado'
                    ELSE 'cerrado'
                END AS estado,
                0 AS puntuable
         FROM competiciones
         WHERE tipo IN ({$tipoIn})
           AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
         ORDER BY COALESCE(fecha_inicio, '9999-12-31') ASC"
    )->fetchAll();
}

$ctaInscripcion = isset($_SESSION['usuario_id']) ? 'usuario/pulls.php' : 'login.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderBrandHead('root', 'Pulls'); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body class="public-app bg-padel">

<?php include __DIR__ . '/includes/navbar_public.php'; ?>

<main class="public-main">
    <a href="index.php" class="btn btn-secondary" style="margin-bottom: 1rem; display: inline-block;">← Volver al inicio</a>

    <section class="card public-section">
        <h1 class="public-section-title">Próximas Pulls</h1>
        <p class="text-muted public-section-sub">Inscripción individual. Juega, rota de pista, cambia de pareja y sube de nivel.</p>

        <?php if ($instalacionPendiente): ?>
            <div class="alert alert-warning">Configura la base de datos para listar Pulls. <a href="instalacion_requerida.php">Instrucciones</a></div>
        <?php elseif ($pulls !== []): ?>
            <div class="public-standings-grid">
                <?php foreach ($pulls as $pull): ?>
                    <article class="card public-standings-card">
                        <?php if (!empty($pull['cartel'])): ?>
                            <img src="assets/img/torneos/<?= htmlspecialchars($pull['cartel']) ?>" alt="" style="width:100%;max-height:220px;object-fit:cover;border-radius:8px;margin-bottom:12px;">
                        <?php endif; ?>
                        <h3 class="public-standings-card-title"><?= htmlspecialchars($pull['titulo']) ?></h3>
                        <p class="text-muted-inline"><?= nl2br(htmlspecialchars($pull['descripcion'] ?? '')) ?></p>
                        <p><strong>Del</strong> <?= date('d/m/Y', strtotime($pull['fecha_inicio'])) ?>
                            <strong> al </strong><?= date('d/m/Y', strtotime($pull['fecha_fin'])) ?></p>
                        <?php if (($pull['estado'] ?? '') === 'abierto'): ?>
                            <a href="<?= htmlspecialchars($ctaInscripcion) ?>" class="btn btn-primary">Inscribirme</a>
                        <?php else: ?>
                            <span class="badge badge-muted"><?= htmlspecialchars(strtoupper((string) $pull['estado'])) ?></span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No hay Pulls publicadas ahora mismo.</p>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/includes/public_footer.php'; ?>
<script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
