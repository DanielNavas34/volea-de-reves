<?php

require_once __DIR__ . '/padel_ui_helpers.php';
require_once __DIR__ . '/app_paths.php';

/** Menú lateral admin. $adminNavActive: dashboard|pulls|usuarios|parejas|partidos|calendario|incidencias|grupos */
function renderAdminSidebar(string $adminNavActive = 'dashboard'): void
{
    $GLOBALS['app_base_path'] = '../';
    $link = static function (string $key, string $path, string $label) use ($adminNavActive): string {
        $hash = '';
        if (str_contains($path, '#')) {
            [$path, $frag] = explode('#', $path, 2);
            $hash = '#' . $frag;
        }
        $href = appUrl('admin/' . $path . '.php') . $hash;
        $class = $adminNavActive === $key ? 'btn btn-primary' : 'btn btn-secondary';
        return '<a href="' . htmlspecialchars($href) . '" class="' . $class . '">' . htmlspecialchars($label) . '</a>';
    };
    ?>
    <aside class="sidebar bg-gray">
        <h3>Panel Admin</h3>
        <nav>
            <?= $link('dashboard', 'dashboard', 'Inicio') ?>
            <?= $link('pulls', 'dashboard#pulls', 'Pulls') ?>
            <?= $link('usuarios', 'usuarios', 'Jugadores') ?>
            <?= $link('parejas', 'parejas', 'Parejas') ?>
            <?= $link('partidos', 'partidos', 'Partidos') ?>
            <?= $link('calendario', 'calendario_pendientes', 'Calendario pendiente') ?>
            <?= $link('incidencias', 'incidencias_partidos', 'Incidencias') ?>
            <?= $link('grupos', 'grupos', 'Ligas y grupos') ?>
            <a href="<?= htmlspecialchars(appUrl('logout.php')) ?>" class="btn" style="background-color: #dc3545; color: white; margin-top: 2rem;">Cerrar Sesión</a>
        </nav>
    </aside>
    <?php
}

/** Título de pestaña + favicon para páginas admin. */
function renderAdminPageHead(string $sectionTitle = 'Admin'): void
{
    require_once __DIR__ . '/brand.php';
    renderBrandHead('admin', $sectionTitle);
}
