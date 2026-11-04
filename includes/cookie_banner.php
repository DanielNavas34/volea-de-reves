<?php

require_once __DIR__ . '/app_paths.php';

$footerCtx = $GLOBALS['footer_legal_context'] ?? 'root';
$urlCtx = match ($footerCtx) {
    'legal' => 'legal',
    'usuario' => 'usuario',
    default => 'root',
};
$cookiePolicyUrl = htmlspecialchars(legalPageUrl('cookies.php', $urlCtx));
?>
<div id="cookie-banner" class="cookie-banner hidden" role="dialog" aria-labelledby="cookie-banner-title" aria-live="polite">
    <div class="cookie-content">
        <p id="cookie-banner-title">
            Usamos cookies técnicas necesarias para el inicio de sesión y el funcionamiento de la aplicación.
            Puedes aceptar todas las categorías o limitarte a las esenciales.
            <a href="<?= $cookiePolicyUrl ?>" class="cookie-policy-link">Política de Cookies</a>.
        </p>
        <div class="cookie-buttons">
            <button type="button" id="btn-reject-cookies" class="btn btn-cookie-ghost">Rechazar no esenciales</button>
            <button type="button" id="btn-accept-cookies" class="btn btn-secondary">Aceptar todas</button>
        </div>
    </div>
</div>
