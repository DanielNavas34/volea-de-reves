<?php
// /includes/security.php — CSRF y utilidades (sesión vía bootstrap.php).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/app_errors.php';
appEnsureSession();

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }
    return false;
}

/** Rechaza POST sin token CSRF válido (403). */
function requirePostCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Error de validación CSRF.');
    }
}

function sanitizeOutput($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Límite de registros por IP (tabla registro_attempts).
 * En local suele estar desactivado vía .env; en producción activar REGISTRATION_RATE_LIMIT=true.
 */
function registrationRateLimitEnabled(): bool
{
    require_once __DIR__ . '/env.php';
    $explicit = env('REGISTRATION_RATE_LIMIT');
    if ($explicit !== null && $explicit !== '') {
        return in_array(strtolower(trim($explicit)), ['1', 'true', 'yes', 'on'], true);
    }

    return env('APP_ENV', 'production') !== 'local';
}
?>