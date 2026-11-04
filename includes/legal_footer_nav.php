<?php

require_once __DIR__ . '/app_paths.php';

/** @param 'root'|'usuario'|'legal' $context */
function renderLegalFooterNav(string $context = 'root'): void
{
    $links = [
        'aviso-legal.php' => 'Aviso legal',
        'privacidad.php' => 'Privacidad',
        'terminos.php' => 'Términos de uso',
        'cookies.php' => 'Cookies',
    ];
    echo '<nav class="footer-legal-nav" aria-label="Información legal">';
    $first = true;
    foreach ($links as $file => $label) {
        if (!$first) {
            echo '<span class="footer-legal-sep" aria-hidden="true">·</span>';
        }
        $first = false;
        $href = htmlspecialchars(legalPageUrl($file, $context));
        echo '<a href="' . $href . '">' . htmlspecialchars($label) . '</a>';
    }
    echo '</nav>';
}
