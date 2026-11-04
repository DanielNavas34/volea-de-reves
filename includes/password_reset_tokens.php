<?php

/** Token plano (email) → hash almacenado en BD (SHA-256 hex). */
function passwordResetTokenHash(string $plainToken): string
{
    return hash('sha256', $plainToken);
}

function passwordResetTokenGenerate(): string
{
    return bin2hex(random_bytes(32));
}

function passwordResetTokenMatches(?string $storedHash, string $plainToken): bool
{
    if ($storedHash === null || $storedHash === '') {
        return false;
    }
    $plain = trim($plainToken);
    if ($plain === '') {
        return false;
    }
    // Compatibilidad: tokens antiguos en claro (64 hex) durante migración
    if (strlen($storedHash) === 64 && ctype_xdigit($storedHash) && hash_equals($storedHash, $plain)) {
        return true;
    }

    return hash_equals($storedHash, passwordResetTokenHash($plain));
}

/** Normaliza valor guardado en BD a hash SHA-256. */
function passwordResetTokenStoreHash(string $plainToken): string
{
    return passwordResetTokenHash($plainToken);
}

function passwordResetFindUserId(PDO $db, string $plainToken): ?int
{
    $plain = trim($plainToken);
    if ($plain === '') {
        return null;
    }
    $hash = passwordResetTokenStoreHash($plain);
    $stmt = $db->prepare(
        'SELECT id, token_recuperacion FROM usuarios
         WHERE (token_recuperacion = :hash OR token_recuperacion = :plain) AND expiracion_token > NOW()
         LIMIT 1'
    );
    $stmt->execute(['hash' => $hash, 'plain' => $plain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !passwordResetTokenMatches((string) ($row['token_recuperacion'] ?? ''), $plain)) {
        return null;
    }

    return (int) $row['id'];
}
