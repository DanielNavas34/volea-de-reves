<?php
/**
 * Punto único de carga: includes/env.php (local/hosting, gitignore) o env.php.example (repo).
 */
if (function_exists('env')) {
    return;
}

$dir = __DIR__;
$local = $dir . DIRECTORY_SEPARATOR . 'env.php';
$example = $dir . DIRECTORY_SEPARATOR . 'env.php.example';

if (is_readable($local)) {
    require_once $local;
} elseif (is_readable($example)) {
    require_once $example;
} else {
    throw new RuntimeException(
        'No se encontró includes/env.php ni includes/env.php.example. Copia env.php.example → env.php.'
    );
}
