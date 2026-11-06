<?php
require_once __DIR__ . '/panel_url.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/app_paths.php';
?>
<nav class="navbar public-nav" aria-label="Navegación principal">
    <a href="<?= htmlspecialchars(appUrl('index.php')) ?>" class="public-brand" aria-label="<?= htmlspecialchars(BRAND_NAME) ?> — Inicio">
        <span class="public-brand-mark">
            <img src="<?= htmlspecialchars(brandImageUrl('root')) ?>" alt="" class="public-brand-photo" width="44" height="44">
        </span>
        <span class="public-brand-text">
            <span class="public-brand-name"><?= htmlspecialchars(BRAND_NAME) ?></span>
            <span class="public-brand-tag">Ligas y Pulls de pádel</span>
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
        <li><a href="<?= htmlspecialchars(appUrl('index.php')) ?>">Inicio</a></li>
        <li><a href="<?= htmlspecialchars(appUrl('pulls.php')) ?>">Pulls</a></li>
        <?php if (!isset($_SESSION['usuario_id'])): ?>
            <li><a href="<?= htmlspecialchars(appUrl('login.php')) ?>">Iniciar sesión</a></li>
            <li><a href="<?= htmlspecialchars(appUrl('registro.php')) ?>">Registrarse</a></li>
        <?php else: ?>
            <li><a href="<?= htmlspecialchars(appUrl(panelUrlForSession())) ?>">Mi panel</a></li>
            <li><a href="<?= htmlspecialchars(appLogoutUrl()) ?>">Cerrar sesión</a></li>
        <?php endif; ?>
    </ul>
</nav>
