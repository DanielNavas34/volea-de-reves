<?php

const BRAND_NAME = 'Volea de Revés';
const BRAND_IMAGE_FILE = 'fondo-tenis.jpg';

/** @param 'root'|'usuario'|'admin'|'legal' $context */
function brandImageUrl(string $context = 'root'): string
{
    if (!function_exists('appBasePath')) {
        require_once __DIR__ . '/app_paths.php';
    }
    $base = appBasePath();
    if ($base !== '') {
        return $base . 'assets/img/' . BRAND_IMAGE_FILE;
    }

    $prefix = in_array($context, ['usuario', 'admin'], true) ? '../' : '';

    return $prefix . 'assets/img/' . BRAND_IMAGE_FILE;
}

/** Título de pestaña: «Sección · Volea de revés» (o solo marca si $sectionTitle está vacío). */
function brandPageTitle(string $sectionTitle = ''): string
{
    $sectionTitle = trim($sectionTitle);
    if ($sectionTitle === '') {
        return BRAND_NAME;
    }

    return $sectionTitle . ' · ' . BRAND_NAME;
}

/**
 * Emite &lt;title&gt; y favicon de marca para la pestaña del navegador.
 *
 * @param 'root'|'usuario'|'admin'|'legal' $context
 */
function renderBrandHead(string $context = 'root', string $sectionTitle = ''): void
{
    $icon = htmlspecialchars(brandImageUrl($context), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars(brandPageTitle($sectionTitle), ENT_QUOTES, 'UTF-8');
    echo "<title>{$title}</title>\n";
    echo "    <link rel=\"icon\" href=\"{$icon}\" type=\"image/jpeg\">\n";
    echo "    <link rel=\"apple-touch-icon\" href=\"{$icon}\">\n";
}
