<?php
$GLOBALS['footer_legal_context'] = 'usuario';
require_once __DIR__ . '/app_paths.php';
?>
<footer class="main-footer public-footer usuario-site-footer">
    <div class="footer-content">
        <div class="footer-brand-block">
            <p><strong>Volea de revés</strong> &copy; <?= date('Y') ?></p>
            <?php
            require_once __DIR__ . '/legal_footer_nav.php';
            renderLegalFooterNav('usuario');
            ?>
        </div>
    </div>
</footer>

<?php include __DIR__ . '/cookie_banner.php'; ?>
