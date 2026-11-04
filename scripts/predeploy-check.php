<?php

declare(strict_types=1);

/**
 * Checklist local antes de subir a preproducción o hacer push a GitHub.
 * Uso: php scripts/predeploy-check.php
 */

$root = dirname(__DIR__);
$fail = 0;

function check(bool $ok, string $label): void
{
    global $fail;
    if ($ok) {
        echo "[OK] {$label}\n";
    } else {
        echo "[FAIL] {$label}\n";
        $fail++;
    }
}

echo "=== Pre-deploy check ===\n\n";

$gitDir = $root . DIRECTORY_SEPARATOR . '.git';
if (is_dir($gitDir)) {
    chdir($root);
    exec('git ls-files --error-unmatch .env 2>NUL', $outEnv, $codeEnv);
    check($codeEnv !== 0, 'Git: .env no está trackeado');
    exec('git ls-files --error-unmatch config/mail.local.php 2>NUL', $outMail, $codeMail);
    check($codeMail !== 0, 'Git: config/mail.local.php no está trackeado');
} else {
    echo "[INFO] Repositorio git no inicializado — ejecuta git init antes del push\n";
}

if (is_file($root . '/.env')) {
    echo "[INFO] .env local presente (correcto en dev; no subir a Git)\n";
}

$gitignore = @file_get_contents($root . '/.gitignore');
check($gitignore !== false && str_contains($gitignore, '.env'), '.gitignore incluye .env');
check($gitignore !== false && str_contains($gitignore, 'mail.local.php'), '.gitignore incluye mail.local.php');

$grepSecretPatterns = [
    $root . '/config/database.php' => ['DB_PASSWORD\s*=\s*[\'"][^\'"]+[\'"]'],
];
foreach ($grepSecretPatterns as $file => $patterns) {
    $content = @file_get_contents($file);
    if ($content === false) {
        check(false, 'No se pudo leer ' . $file);
        continue;
    }
    foreach ($patterns as $p) {
        check(!preg_match('/' . $p . '/', $content), basename($file) . ' sin contraseña en claro');
    }
}

check(is_readable($root . '/.env.staging.example'), '.env.staging.example presente');
check(is_readable($root . '/docs/pre-despliegue-staging.md'), 'doc pre-despliegue presente');

$htaccess = @file_get_contents($root . '/.htaccess');
check($htaccess !== false && str_contains($htaccess, '.env'), '.htaccess bloquea .env');
check($htaccess !== false && str_contains($htaccess, 'storage'), '.htaccess bloquea storage/');

check(is_readable($root . '/includes/cron_guard.php'), 'cron_guard.php presente');
check(is_readable($root . '/includes/app_errors.php'), 'app_errors.php presente');

$envPath = $root . '/.env';
if (is_readable($envPath)) {
    require_once $root . '/includes/env.php';
    echo "\n--- Avisos .env local (no bloquean) ---\n";
    $env = env('APP_ENV', '');
    $debug = env('APP_DEBUG', '');
    if ($env !== 'local' && $debug === 'true') {
        echo "[WARN] APP_DEBUG=true con APP_ENV={$env} — desactivar en preproducción\n";
    }
    if (env('APP_ENV', '') !== 'local' && strtolower((string) env('MAIL_DRIVER', '')) === 'fake') {
        echo "[WARN] MAIL_DRIVER=fake fuera de local — usar smtp en staging\n";
    }
    if (env('APP_ENV', '') !== 'local' && trim((string) env('CRON_SECRET', '')) === '') {
        echo "[WARN] CRON_SECRET vacío — cron HTTP quedará en 403 (CLI sigue OK)\n";
    }
} else {
    echo "\n[INFO] Sin .env local; en servidor copia .env.staging.example → .env\n";
}

echo "\n=== Resumen: {$fail} fallo(s) ===\n";
exit($fail > 0 ? 1 : 0);
