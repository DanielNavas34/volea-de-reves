<?php

require_once __DIR__ . '/env.php';

/**
 * Envío real por SMTP (como producción tenis). Modo fake solo si MAIL_DRIVER=fake explícito.
 */

function mailLocalConfig(): array
{
    static $loaded = false;
    static $config = [];

    if (!$loaded) {
        $loaded = true;
        $path = dirname(__DIR__) . '/config/mail.local.php';
        if (is_readable($path)) {
            $data = require $path;
            if (is_array($data)) {
                $config = $data;
            }
        }
    }

    return $config;
}

function mailSetting(string $key, ?string $default = null): ?string
{
    $map = [
        'host' => 'MAIL_HOST',
        'port' => 'MAIL_PORT',
        'username' => 'MAIL_USERNAME',
        'password' => 'MAIL_PASSWORD',
        'encryption' => 'MAIL_ENCRYPTION',
        'from_address' => 'MAIL_FROM_ADDRESS',
        'from_name' => 'MAIL_FROM_NAME',
        'dsn' => 'MAIL_DSN',
    ];

    $envKey = $map[$key] ?? null;
    if ($envKey !== null) {
        $fromEnv = env($envKey);
        if ($fromEnv !== null && trim($fromEnv) !== '') {
            return $fromEnv;
        }
    }

    $local = mailLocalConfig();
    if (array_key_exists($key, $local) && $local[$key] !== null && $local[$key] !== '') {
        return (string) $local[$key];
    }

    return $default;
}

function mailSmtpConfigured(): bool
{
    $dsn = mailSetting('dsn');
    if ($dsn !== null && trim($dsn) !== '') {
        return true;
    }

    $host = mailSetting('host');
    return $host !== null && trim($host) !== '';
}

function mailDriver(): string
{
    $explicit = env('MAIL_DRIVER');
    $driver = ($explicit !== null && trim($explicit) !== '')
        ? strtolower(trim($explicit))
        : 'smtp';

    if ($driver === 'fake' && env('APP_ENV', 'production') !== 'local') {
        return 'smtp';
    }

    return $driver;
}

/** Verificación por PIN (desactivar solo en tests con SKIP_EMAIL_VERIFICATION=true). */
function emailVerificationEnabled(): bool
{
    $skip = env('SKIP_EMAIL_VERIFICATION');
    if ($skip !== null && $skip !== '') {
        return !in_array(strtolower(trim($skip)), ['1', 'true', 'yes', 'on'], true);
    }

    return true;
}

function mailUsesFakeTransport(): bool
{
    return mailDriver() === 'fake';
}

function mailRememberDevPin(string $email, string $pin): void
{
    require_once __DIR__ . '/bootstrap.php';
    appEnsureSession();
    if (!isset($_SESSION['mail_dev_pin']) || !is_array($_SESSION['mail_dev_pin'])) {
        $_SESSION['mail_dev_pin'] = [];
    }
    $_SESSION['mail_dev_pin'][strtolower(trim($email))] = $pin;
}

function mailGetDevPin(string $email): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        return null;
    }
    $key = strtolower(trim($email));
    $pins = $_SESSION['mail_dev_pin'] ?? [];

    return isset($pins[$key]) ? (string) $pins[$key] : null;
}

function mailClearDevPin(string $email): void
{
    if (session_status() === PHP_SESSION_NONE) {
        return;
    }
    $key = strtolower(trim($email));
    unset($_SESSION['mail_dev_pin'][$key]);
}

function mailWriteLog(string $line): void
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/mail.log';
    $stamp = date('Y-m-d H:i:s');
    @file_put_contents($file, "[{$stamp}] {$line}\n", FILE_APPEND | LOCK_EX);
    if (env('APP_DEBUG', 'false') === 'true') {
        error_log('[mail] ' . $line);
    }
}

function mailFromAddress(): string
{
    return mailSetting('from_address', 'noreply@liga-padel.local') ?? 'noreply@liga-padel.local';
}

function mailFromName(): string
{
    require_once __DIR__ . '/brand.php';
    $configured = mailSetting('from_name');
    if ($configured !== null && trim($configured) !== '') {
        return trim($configured);
    }

    return BRAND_NAME;
}

function mailPinHtmlBody(string $pin): string
{
    $pinEsc = htmlspecialchars($pin, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $appName = htmlspecialchars(mailFromName(), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;">
    <h2 style="color: #004085; text-align: center; border-bottom: 2px solid #ffc107; padding-bottom: 10px;">Verificación de cuenta</h2>
    <p style="font-size: 16px; color: #333;">¡Hola!</p>
    <p style="font-size: 16px; color: #333;">Gracias por registrarte. Para completar tu registro, introduce este código de 6 dígitos en la web:</p>
    <div style="text-align: center; margin: 30px 0;">
        <span style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #28a745; background: #e8f5e9; padding: 15px 30px; border-radius: 8px; border: 1px solid #c3e6cb;">{$pinEsc}</span>
    </div>
    <p style="font-size: 14px; color: #666;"><em>Caduca en 15 minutos. Si no has solicitado este registro, ignora este correo.</em></p>
    <div style="text-align: center; margin-top: 30px; font-size: 12px; color: #999; border-top: 1px solid #eee; padding-top: 15px;">
        &copy; {$year} {$appName}
    </div>
</div>
HTML;
}

/** @return bool true si el mensaje se envió (o simulación explícita MAIL_DRIVER=fake) */
function enviarCorreoPIN(string $email, string $pin): bool
{
    $email = trim($email);
    if ($email === '' || $pin === '') {
        return false;
    }

    $subject = 'Tu código de verificación — ' . mailFromName();
    $bodyText = "Tu código PIN es: {$pin}\n\nCaduca en 15 minutos.\n\nSi no has solicitado este registro, ignora este mensaje.";
    $bodyHtml = mailPinHtmlBody($pin);

    if (mailUsesFakeTransport()) {
        mailRememberDevPin($email, $pin);
        if (env('APP_ENV', 'production') === 'local') {
            mailWriteLog("FAKE PIN to={$email} pin={$pin} driver=fake");
        } else {
            mailWriteLog('FAKE PIN suppressed (solo local); configure SMTP');
        }

        return true;
    }

    if (!mailSmtpConfigured()) {
        mailWriteLog('SMTP not configured: set MAIL_HOST (or MAIL_DSN) in .env or config/mail.local.php');

        return false;
    }

    return mailSendSmtp($email, $subject, $bodyText, $bodyHtml);
}

function mailRecoveryHtmlBody(string $resetUrl): string
{
    $urlEsc = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $appName = htmlspecialchars(mailFromName(), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;">
    <h2 style="color: #004085; text-align: center; border-bottom: 2px solid #ffc107; padding-bottom: 10px;">Recuperación de contraseña</h2>
    <p style="font-size: 16px; color: #333;">Hemos recibido una solicitud para restablecer tu contraseña.</p>
    <p style="text-align: center; margin: 28px 0;">
        <a href="{$urlEsc}" style="background-color: #004085; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">Restablecer contraseña</a>
    </p>
    <p style="font-size: 14px; color: #666;">Si el botón no funciona, copia este enlace en el navegador:</p>
    <p style="font-size: 12px; color: #0056b3; word-break: break-all;">{$urlEsc}</p>
    <p style="font-size: 14px; color: #666;"><em>El enlace caduca en 1 hora. Si no has solicitado esto, ignora este correo.</em></p>
    <div style="text-align: center; margin-top: 24px; font-size: 12px; color: #999; border-top: 1px solid #eee; padding-top: 12px;">
        &copy; {$year} {$appName}
    </div>
</div>
HTML;
}

/** URL pública para el enlace de recuperación (sin .php si hay rewrite). */
function mailPublicRecoveryUrl(string $plainToken): string
{
    require_once __DIR__ . '/app_paths.php';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }
    $path = ltrim(appCleanPath('recuperar_password.php'), '/');

    return $scheme . '://' . $host . $scriptDir . '/' . $path . '?token=' . rawurlencode($plainToken);
}

function enviarCorreoRecuperacion(string $email, string $plainToken): bool
{
    $email = trim($email);
    if ($email === '' || $plainToken === '') {
        return false;
    }

    $resetUrl = mailPublicRecoveryUrl($plainToken);
    $subject = 'Recuperar contraseña — ' . mailFromName();
    $bodyText = "Para restablecer tu contraseña visita este enlace (caduca en 1 hora):\n\n{$resetUrl}\n\nSi no has solicitado esto, ignora este mensaje.";
    $bodyHtml = mailRecoveryHtmlBody($resetUrl);

    if (mailUsesFakeTransport()) {
        if (env('APP_ENV', 'production') === 'local') {
            mailWriteLog("FAKE RECOVERY to={$email} url={$resetUrl}");
        } else {
            mailWriteLog('FAKE RECOVERY suppressed (solo local); configure SMTP');
        }

        return true;
    }

    if (!mailSmtpConfigured()) {
        mailWriteLog('SMTP not configured; recovery not sent to=' . $email);

        return false;
    }

    return mailSendSmtp($email, $subject, $bodyText, $bodyHtml);
}

function mailShowRecoveryLinkInUi(): bool
{
    return mailUsesFakeTransport() && env('APP_ENV', 'local') === 'local';
}

function mailSendSmtp(string $to, string $subject, string $bodyText, string $bodyHtml = ''): bool
{
    require_once dirname(__DIR__) . '/libs/PHPMailer/Exception.php';
    require_once dirname(__DIR__) . '/libs/PHPMailer/PHPMailer.php';
    require_once dirname(__DIR__) . '/libs/PHPMailer/SMTP.php';

    $dsn = mailSetting('dsn');
    if ($dsn !== null && trim($dsn) !== '') {
        require_once dirname(__DIR__) . '/libs/PHPMailer/DSNConfigurator.php';

        try {
            $mail = PHPMailer\PHPMailer\DSNConfigurator::mailer(trim($dsn), true);
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(mailFromAddress(), mailFromName());
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML($bodyHtml !== '');
            $mail->Body = $bodyHtml !== '' ? $bodyHtml : $bodyText;
            $mail->AltBody = $bodyText;
            $mail->send();
            mailWriteLog("SMTP(DSN) sent to={$to} subject={$subject}");

            return true;
        } catch (Throwable $e) {
            mailWriteLog('SMTP(DSN) error to=' . $to . ' msg=' . $e->getMessage());

            return false;
        }
    }

    $host = mailSetting('host');
    if ($host === null || trim($host) === '') {
        mailWriteLog("SMTP skipped: MAIL_HOST not set (to={$to})");

        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = trim($host);
        $mail->Port = (int) (mailSetting('port', '587') ?? '587');
        $username = mailSetting('username', '') ?? '';
        $password = mailSetting('password', '') ?? '';
        $mail->SMTPAuth = $username !== '';
        $mail->Username = $username;
        $mail->Password = $password;
        $encryption = strtolower(trim(mailSetting('encryption', 'tls') ?? 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(mailFromAddress(), mailFromName());
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML($bodyHtml !== '');
        $mail->Body = $bodyHtml !== '' ? $bodyHtml : $bodyText;
        $mail->AltBody = $bodyText;

        $mail->send();
        mailWriteLog("SMTP sent to={$to} subject={$subject}");

        return true;
    } catch (Throwable $e) {
        mailWriteLog('SMTP error to=' . $to . ' msg=' . $e->getMessage());

        return false;
    }
}
