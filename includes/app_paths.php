<?php

/** Prefijo relativo hacia la raíz de la app (p. ej. '../' desde legal/ o usuario/). */
function appBasePath(): string
{
    return $GLOBALS['app_base_path'] ?? '';
}

/** Quita .php y normaliza index → raíz. Conserva query string. */
function appCleanPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $query = '';
    if (str_contains($path, '?')) {
        [$path, $queryPart] = explode('?', $path, 2);
        $query = '?' . $queryPart;
    }
    $path = ltrim($path, '/');
    if ($path === '' || $path === 'index' || $path === 'index.php') {
        return ltrim($query, '?') !== '' ? ('index' . $query) : '';
    }
    if (str_ends_with(strtolower($path), '.php')) {
        $path = substr($path, 0, -4);
    }

    return $path . $query;
}

function appUrl(string $path): string
{
    $base = appBasePath();
    $clean = appCleanPath($path);
    if ($clean === '' || $clean === 'index') {
        return $base === '' ? '/' : rtrim($base, '/') . '/';
    }

    return $base . $clean;
}

/** Enlace de cierre de sesión con token CSRF (logout.php lo exige). */
function appLogoutUrl(): string
{
    $url = appUrl('logout.php');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $url;
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $url . '?t=' . rawurlencode((string) $_SESSION['csrf_token']);
}

/** Redirección HTTP a ruta sin .php (respeta app_base_path). */
function redirectTo(string $path, int $status = 302): never
{
    $url = appUrl($path);
    if ($url !== '/' && !str_starts_with($url, 'http') && !str_starts_with($url, '../') && $url[0] !== '/') {
        $url = '/' . ltrim($url, '/');
    }
    header('Location: ' . $url, true, $status);
    exit;
}

/** @param 'root'|'usuario'|'legal' $context */
function legalPageUrl(string $file, string $context = 'root'): string
{
    $file = ltrim($file, '/');

    return match ($context) {
        'usuario' => '../legal/' . $file,
        'legal' => $file,
        default => 'legal/' . $file,
    };
}
