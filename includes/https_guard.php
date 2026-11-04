<?php

require_once __DIR__ . '/load_env.php';

function appIsHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $trust = env('TRUSTED_PROXY');
    if ($trust !== null && in_array(strtolower(trim($trust)), ['1', 'true', 'yes', 'on'], true)) {
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto === 'https') {
            return true;
        }
    }

    return false;
}

function appForceHttpsEnabled(): bool
{
    if (env('APP_ENV', 'production') === 'local') {
        return false;
    }
    $force = env('FORCE_HTTPS', 'true');
    if ($force === null || $force === '') {
        return true;
    }

    return in_array(strtolower(trim($force)), ['1', 'true', 'yes', 'on'], true);
}

function appEnforceHttpsIfRequired(): void
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }
    if (!appForceHttpsEnabled() || appIsHttpsRequest()) {
        return;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}
