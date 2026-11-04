<?php
/**
 * Enlace por email legacy (token_verificacion). Volea de revés usa PIN en registro.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
appEnsureSession();
require_once __DIR__ . '/includes/app_paths.php';

$_SESSION['flash_verificacion'] = 'La verificación se hace con el código PIN en el registro. Si ya tienes cuenta, inicia sesión.';
redirectTo('registro.php');
