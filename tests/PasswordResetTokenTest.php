<?php

require_once __DIR__ . '/../includes/password_reset_tokens.php';
require_once __DIR__ . '/../includes/app_paths.php';

function test_password_reset_token_hash_and_match(): bool
{
    $plain = passwordResetTokenGenerate();
    $hash = passwordResetTokenStoreHash($plain);
    if (!passwordResetTokenMatches($hash, $plain)) {
        echo "[FAIL] password_reset_token_hash_match\n";

        return false;
    }
    if (passwordResetTokenMatches($hash, bin2hex(random_bytes(32)))) {
        echo "[FAIL] password_reset_token_rejects_wrong\n";

        return false;
    }
    echo "[OK] password_reset_token_hash_match\n";

    return true;
}

function test_password_reset_rejects_stored_hash_as_token(): bool
{
    $hash = passwordResetTokenStoreHash(passwordResetTokenGenerate());
    if (passwordResetTokenMatches($hash, $hash)) {
        echo "[FAIL] password_reset_rejects_stored_hash\n";

        return false;
    }
    echo "[OK] password_reset_rejects_stored_hash\n";

    return true;
}

function test_partido_cuenta_atras_formato(): bool
{
    require_once __DIR__ . '/../includes/partido_validacion_mensajes.php';
    $txt = partidoFormatearCuentaAtras24h(3661);
    if (!str_contains($txt, '01:01:01')) {
        echo "[FAIL] partido_cuenta_atras_formato\n";

        return false;
    }
    echo "[OK] partido_cuenta_atras_formato\n";

    return true;
}

function test_app_clean_path_strips_php(): bool
{
    if (appCleanPath('login.php') !== 'login') {
        echo "[FAIL] app_clean_path_login\n";

        return false;
    }
    if (appCleanPath('usuario/dashboard.php?x=1') !== 'usuario/dashboard?x=1') {
        echo "[FAIL] app_clean_path_query\n";

        return false;
    }
    if (appCleanPath('index.php') !== '') {
        echo "[FAIL] app_clean_path_index\n";

        return false;
    }
    echo "[OK] app_clean_path\n";

    return true;
}
