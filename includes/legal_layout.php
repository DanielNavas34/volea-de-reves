<?php

require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/app_paths.php';

function legalLayoutStart(string $pageTitle): void
{
    require_once __DIR__ . '/bootstrap.php';
    appEnsureSession();
    $GLOBALS['app_base_path'] = '../';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, follow">
    <?php renderBrandHead('root', $pageTitle); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
</head>
<body class="public-app bg-padel">

<?php include __DIR__ . '/navbar_public.php'; ?>

<main class="public-main legal-main">
    <article class="card legal-document">
    <?php
}

function legalLayoutEnd(): void
{
    ?>
    </article>
</main>

<?php
$GLOBALS['footer_legal_context'] = 'legal';
include __DIR__ . '/public_footer.php';
?>
<script src="../assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
    <?php
}
