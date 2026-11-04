<?php

/**
 * Bootstrap único de aplicación (Fase 3).
 * Carga entorno, sesión segura y comprobaciones de esquema Volea de revés.
 */

require_once __DIR__ . '/load_env.php';
require_once __DIR__ . '/https_guard.php';

appEnforceHttpsIfRequired();

function appEnsureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (env('APP_ENV', 'production') !== 'local' && appIsHttpsRequest()) {
        ini_set('session.cookie_secure', '1');
    }

    session_start();

    if (!isset($_SESSION['ultima_regeneracion'])) {
        session_regenerate_id(true);
        $_SESSION['ultima_regeneracion'] = time();
    } elseif (time() - $_SESSION['ultima_regeneracion'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['ultima_regeneracion'] = time();
    }
}

function instalacionRequeridaUrl(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/(admin|usuario|legal)/#', $script)) {
        return '../instalacion_requerida.php';
    }

    return 'instalacion_requerida.php';
}

function voleaPadelInstallReady(PDO $db): bool
{
    require_once __DIR__ . '/legacy_schema.php';

    return padelCompeticionesSchemaAvailable($db);
}

/** Áreas autenticadas y registro: exigen esquema liga_padel. */
function requireVoleaPadelInstall(?PDO $db = null): void
{
    if ($db === null) {
        require_once __DIR__ . '/../config/database.php';
        $db = getDBConnection();
    }
    if (!voleaPadelInstallReady($db)) {
        header('Location: ' . instalacionRequeridaUrl(), true, 302);
        exit;
    }
}

/** Portada y páginas públicas: datos vacíos si falta migración (sin fatal). */
function voleaPadelInstallReadyOrEmpty(PDO $db): bool
{
    try {
        return voleaPadelInstallReady($db);
    } catch (Throwable $e) {
        return false;
    }
}
