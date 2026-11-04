<?php

require_once __DIR__ . '/load_env.php';

/**
 * Cron: CLI o HTTP con CRON_SECRET (?cron_secret= o cabecera X-Cron-Secret).
 */
function cronRequireAuthorizedRunner(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }

    $expected = trim((string) env('CRON_SECRET', ''));
    if ($expected === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    $provided = (string) ($_GET['cron_secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}
