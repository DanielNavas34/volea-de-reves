<?php

require_once __DIR__ . '/../includes/mailer.php';

function test_mailer_verification_helpers(): bool
{
    foreach (['mailDriver', 'emailVerificationEnabled', 'mailUsesFakeTransport', 'enviarCorreoPIN'] as $fn) {
        if (!function_exists($fn)) {
            echo "[FAIL] mailer_missing_{$fn}\n";

            return false;
        }
    }

    $skip = env('SKIP_EMAIL_VERIFICATION');
    if ($skip === null || trim($skip) === '') {
        if (!emailVerificationEnabled()) {
            echo "[FAIL] email_verification_should_be_enabled\n";

            return false;
        }
    }

    echo "[OK] mailer_verification_helpers\n";

    return true;
}

function test_enviar_correo_pin_fake_driver(): bool
{
    if (mailDriver() !== 'fake') {
        echo "[OK] enviar_correo_pin_fake_skip\n";

        return true;
    }

    $email = 'test-pin-' . bin2hex(random_bytes(4)) . '@example.test';
    $pin = '123456';
    if (!enviarCorreoPIN($email, $pin)) {
        echo "[FAIL] enviar_correo_pin_fake\n";

        return false;
    }
    if (mailGetDevPin($email) !== $pin) {
        echo "[FAIL] enviar_correo_pin_dev_session\n";

        return false;
    }
    echo "[OK] enviar_correo_pin_fake\n";

    return true;
}

function test_mailer_recovery_helpers(): bool
{
    foreach (['enviarCorreoRecuperacion', 'mailPublicRecoveryUrl', 'mailShowRecoveryLinkInUi'] as $fn) {
        if (!function_exists($fn)) {
            echo "[FAIL] mailer_recovery_missing_{$fn}\n";

            return false;
        }
    }
    $url = mailPublicRecoveryUrl('abc123token');
    if ($url === '' || !str_contains($url, 'abc123token')) {
        echo "[FAIL] mail_public_recovery_url\n";

        return false;
    }
    if (str_contains($url, '.php')) {
        echo "[FAIL] mail_public_recovery_url_no_php\n";

        return false;
    }
    echo "[OK] mailer_recovery_helpers\n";

    return true;
}
