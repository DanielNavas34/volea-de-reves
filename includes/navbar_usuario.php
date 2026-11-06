<?php
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/app_paths.php';
if (!isset($GLOBALS['app_base_path']) || $GLOBALS['app_base_path'] === '') {
    $GLOBALS['app_base_path'] = '../';
}
?>
<nav class="navbar usuario-navbar" aria-label="Navegación del panel">
    <a href="<?= htmlspecialchars(appUrl('index.php')) ?>" class="public-brand usuario-brand" aria-label="<?= htmlspecialchars(BRAND_NAME) ?> — Inicio">
        <span class="public-brand-mark">
            <img src="<?= htmlspecialchars(brandImageUrl('usuario')) ?>" alt="" class="public-brand-photo" width="44" height="44">
        </span>
        <span class="public-brand-text">
            <span class="public-brand-name"><?= htmlspecialchars(BRAND_NAME) ?></span>
            <span class="public-brand-tag">Mi panel</span>
        </span>
    </a>

    <div class="nav-menu-trigger">
        <span class="nav-menu-label" role="button" tabindex="0" onclick="document.getElementById('hamburger-btn').click();" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('hamburger-btn').click();}">Menú</span>
        <button type="button" class="hamburger" id="hamburger-btn" aria-label="Abrir menú">
            <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="50" cy="50" r="46" fill="#c5e063" stroke="#FFFFFF" stroke-width="5"/>
                <path d="M 25 15 Q 65 50 25 85" fill="none" stroke="#FFFFFF" stroke-width="5" stroke-linecap="round"/>
                <path d="M 75 15 Q 35 50 75 85" fill="none" stroke="#FFFFFF" stroke-width="5" stroke-linecap="round"/>
            </svg>
        </button>
    </div>

    <ul class="nav-links" id="nav-links">
        <li><a href="<?= htmlspecialchars(appUrl('usuario/dashboard.php')) ?>">Inicio</a></li>
        <li><a href="<?= htmlspecialchars(appUrl('usuario/pareja.php')) ?>">Mi pareja</a></li>

        <?php if (isset($_SESSION['tipo_perfil']) && $_SESSION['tipo_perfil'] === 'liga'): ?>
            <li><a href="<?= htmlspecialchars(appUrl('usuario/ligas.php')) ?>">Ligas</a></li>
            <li>
                <a href="<?= htmlspecialchars(appUrl('usuario/partidos.php')) ?>" class="nav-link-with-badge">
                    Mis Partidos
                    <?php if (isset($total_notificaciones) && $total_notificaciones > 0): ?>
                        <span class="nav-badge-count"><?= (int) $total_notificaciones ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="<?= htmlspecialchars(appUrl('usuario/clasificacion.php')) ?>">Clasificación</a></li>
            <li><a href="<?= htmlspecialchars(appUrl('usuario/historial.php')) ?>">Historial</a></li>
        <?php endif; ?>

        <li><a href="<?= htmlspecialchars(appUrl('usuario/pulls.php')) ?>">Pulls</a></li>
        <li><a href="<?= htmlspecialchars(appUrl('usuario/mis_pulls.php')) ?>">Mis Pulls</a></li>
        <li><a href="<?= htmlspecialchars(appUrl('usuario/perfil.php')) ?>">Mi perfil</a></li>
        <li><a href="<?= htmlspecialchars(appUrl('index.php')) ?>">Página Principal</a></li>
        <li><a href="<?= htmlspecialchars(appLogoutUrl()) ?>" class="nav-link-logout">Cerrar Sesión</a></li>
    </ul>
</nav>
