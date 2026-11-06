<?php

/** Mensaje de error si la contraseña no cumple la política; null si es válida. */
function passwordPolicyError(string $password, string $email = ''): ?string
{
    if (strlen($password) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    // bcrypt ignora todo lo que pase de 72 bytes
    if (strlen($password) > 72) {
        return 'La contraseña no puede superar los 72 caracteres.';
    }
    $lower = mb_strtolower($password, 'UTF-8');
    $comunes = [
        '12345678', '123456789', '1234567890', '87654321', '11111111', '00000000',
        'password', 'password1', 'password123', 'contraseña', 'contrasena', 'qwerty123',
        'qwertyuiop', 'abc12345', 'abcd1234', 'iloveyou', 'tequiero', 'padel123',
        'padelpadel', 'tenis123', 'admin123', 'administrador', 'bienvenido', '1q2w3e4r',
    ];
    if (in_array($lower, $comunes, true) || preg_match('/^(.)\1+$/u', $password)) {
        return 'Esa contraseña es demasiado común. Elige otra más difícil de adivinar.';
    }
    $email = strtolower(trim($email));
    if ($email !== '' && ($lower === $email || $lower === strstr($email, '@', true))) {
        return 'La contraseña no puede ser tu correo electrónico.';
    }

    return null;
}
