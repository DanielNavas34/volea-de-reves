<?php

require_once __DIR__ . '/env.php';

/** IP del cliente (respeta un proxy de confianza si TRUSTED_PROXY=true). */
function requestClientIp(): string
{
    $trust = env('TRUSTED_PROXY');
    $trusted = $trust !== null && in_array(strtolower(trim($trust)), ['1', 'true', 'yes', 'on'], true);
    if ($trusted && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $first = $parts[0] ?? '';
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function authHttpError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title></head><body><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</p><p><a href="/login">Volver</a></p></body></html>';
    exit;
}

/**
 * @return array{allowed: bool, message: string|null}
 */
function authLoginRateLimitCheck(PDO $db, string $ip, string $email, int $maxEmail = 5, int $maxIp = 20, int $minutes = 5): array
{
    $minutes = max(1, min(60, $minutes));
    $interval = (string) (int) $minutes;

    $stmtIp = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND fecha_intento >= (NOW() - INTERVAL {$interval} MINUTE)"
    );
    $stmtIp->execute(['ip' => $ip]);
    $fallosIp = (int) $stmtIp->fetchColumn();

    $stmtEmail = $db->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE email = :email AND fecha_intento >= (NOW() - INTERVAL {$interval} MINUTE)"
    );
    $stmtEmail->execute(['email' => $email]);
    $fallosEmail = (int) $stmtEmail->fetchColumn();

    if ($fallosIp >= $maxIp || $fallosEmail >= $maxEmail) {
        return [
            'allowed' => false,
            'message' => 'Demasiados intentos fallidos. Por seguridad, el acceso ha sido bloqueado temporalmente. Inténtelo de nuevo en unos minutos.',
        ];
    }

    return ['allowed' => true, 'message' => null];
}

function authLoginRateLimitRecord(PDO $db, string $ip, string $email): void
{
    $db->prepare('INSERT INTO login_attempts (ip, email) VALUES (:ip, :email)')->execute([
        'ip' => $ip,
        'email' => $email,
    ]);
}

function authLoginRateLimitClear(PDO $db, string $ip, string $email): void
{
    $db->prepare('DELETE FROM login_attempts WHERE ip = :ip AND email = :email')->execute([
        'ip' => $ip,
        'email' => $email,
    ]);
}

/** Mismos límites que login para solicitudes de recuperación de contraseña. */
function authRecoveryRateLimitCheck(PDO $db, string $ip, string $email): array
{
    return authLoginRateLimitCheck($db, $ip, $email, 5, 20, 5);
}

function authRecoveryRateLimitRecord(PDO $db, string $ip, string $email): void
{
    authLoginRateLimitRecord($db, $ip, $email);
}

function authPinResendRateLimitCheck(PDO $db, string $ip, int $max = 5, int $minutes = 15): bool
{
    $minutes = max(1, min(120, $minutes));
    $interval = (string) (int) $minutes;
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM registro_attempts WHERE ip = :ip AND fecha_intento >= (NOW() - INTERVAL {$interval} MINUTE)"
    );
    $stmt->execute(['ip' => $ip]);

    return (int) $stmt->fetchColumn() < $max;
}

function authPinResendRateLimitRecord(PDO $db, string $ip): void
{
    $db->prepare('INSERT INTO registro_attempts (ip) VALUES (:ip)')->execute(['ip' => $ip]);
}
