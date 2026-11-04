<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/brand.php';

appEnsureSession();

$healthUrl = 'health.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderBrandHead('root', 'Instalación'); ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-gray">
<main class="container" style="max-width:560px;margin:3rem auto;padding:1.5rem;">
    <div class="card usuario-card">
        <h1 style="color:var(--padel-teal-dark);">Configuración pendiente</h1>
        <p>Esta instalación de <strong>Volea de revés</strong> necesita la base de datos <code>liga_padel</code> (tabla <code>competiciones</code> y esquema pádel).</p>
        <ol style="line-height:1.7;margin:1rem 0;">
            <li>Copia <code>.env.example</code> → <code>.env</code></li>
            <li>Inicia Docker e importa <code>sql/liga_padel_init.sql</code></li>
            <li>Comprueba <a href="<?= htmlspecialchars($healthUrl) ?>">health.php</a> → <code>{"ok":true}</code></li>
        </ol>
        <p><a href="index.php" class="btn btn-secondary">Volver al inicio</a></p>
    </div>
</main>
</body>
</html>
