<?php

require_once __DIR__ . '/padel_ui_helpers.php';

/**
 * Layout unificado del área usuario (patrón de usuario/dashboard.php).
 * Uso: usuarioLayoutStart($titulo, $totalNotificaciones); … contenido … usuarioLayoutEnd();
 */

function usuarioLayoutStart(string $pageTitle, int $totalNotificaciones = 0, string $extraHeadHtml = ''): void
{
    require_once __DIR__ . '/brand.php';
    require_once __DIR__ . '/app_paths.php';
    require_once __DIR__ . '/usuario_avatar.php';
    $GLOBALS['app_base_path'] = '../';
    $GLOBALS['total_notificaciones'] = $totalNotificaciones;
    $GLOBALS['usuario_avatar_ctx'] = null;
    if (!empty($_SESSION['usuario_id'])) {
        require_once __DIR__ . '/../config/database.php';
        try {
            $GLOBALS['usuario_avatar_ctx'] = usuarioAvatarContextForSession(getDBConnection());
        } catch (Throwable) {
            $GLOBALS['usuario_avatar_ctx'] = usuarioAvatarContextForSession(null);
        }
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderBrandHead('usuario', $pageTitle); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <?= $extraHeadHtml ?>
</head>
<body class="bg-padel usuario-area tema-padel">
<?php include __DIR__ . '/navbar_usuario.php'; ?>
<div class="dashboard-wrapper usuario-dashboard">
    <main class="main-content usuario-main">
    <?php
}

function usuarioLayoutEnd(string $inlineScript = ''): void
{
    ?>
    </main>
</div>
<?php include __DIR__ . '/usuario_footer.php'; ?>
<?php if ($inlineScript !== ''): ?>
<script><?= $inlineScript ?></script>
<?php endif; ?>
<script src="../assets/js/app.js"></script>
</body>
</html>
    <?php
}

/**
 * @param array{nombre?: string, apellidos?: string, foto_perfil?: ?string}|null $avatarUser
 */
function usuarioPageHeading(string $title, ?array $avatarUser = null): void
{
    if (!function_exists('brandImageUrl')) {
        require_once __DIR__ . '/brand.php';
    }
    if ($avatarUser === null) {
        $avatarUser = $GLOBALS['usuario_avatar_ctx'] ?? null;
    }
    echo '<header class="usuario-page-heading">';
    echo '<span class="usuario-title-logo-wrap" aria-hidden="true">';
    if ($avatarUser !== null) {
        require_once __DIR__ . '/usuario_avatar.php';
        echo usuarioAvatarHtml($avatarUser, 52, 'usuario-avatar--heading');
    } else {
        echo '<img src="' . htmlspecialchars(brandImageUrl('usuario')) . '" alt="" class="usuario-title-logo" width="52" height="52">';
    }
    echo '</span>';
    echo '<h1 class="panel-title">' . htmlspecialchars($title) . '</h1>';
    echo '</header>';
}

/** @param bool $escape false solo si el mensaje ya está sanitizado o contiene HTML controlado */
function usuarioAlert(string $message, string $variant = 'info', bool $escape = true): void
{
    $class = match ($variant) {
        'success' => 'alert alert-success',
        'error' => 'alert alert-error',
        'warning' => 'alert alert-warning',
        default => 'alert alert-info',
    };
    $body = $escape ? htmlspecialchars($message) : $message;
    echo '<div class="' . $class . '">' . $body . '</div>';
}

function usuarioPanelSubtitle(string $text): void
{
    echo '<p class="panel-subtitle">' . htmlspecialchars($text) . '</p>';
}

function usuarioCardOpen(string $title = '', string $accent = ''): void
{
    $classes = 'card usuario-card';
    if ($accent !== '') {
        $classes .= ' card-accent-' . $accent;
    }
    echo '<div class="' . $classes . '">';
    if ($title !== '') {
        echo '<h3 class="card-title">' . htmlspecialchars($title) . '</h3>';
    }
}

function usuarioCardClose(): void
{
    echo '</div>';
}

function usuarioBadge(string $label, string $variant = 'success'): void
{
    $allowed = ['success', 'warning', 'info', 'muted', 'danger'];
    if (!in_array($variant, $allowed, true)) {
        $variant = 'info';
    }
    echo '<span class="badge badge-' . $variant . '">' . htmlspecialchars($label) . '</span>';
}
