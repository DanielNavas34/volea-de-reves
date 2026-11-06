<?php

require_once __DIR__ . '/load_env.php';

/** Detalle técnico (SQL, etc.) solo en local o con APP_DEBUG=true. */
function appExposeErrorDetails(): bool
{
    if (env('APP_DEBUG', 'false') === 'true') {
        return true;
    }

    return env('APP_ENV', 'production') === 'local';
}

/**
 * Fuera de local/debug: sin errores en pantalla, log en storage/logs y página 500 genérica
 * para excepciones no capturadas.
 */
function appInstallErrorHandling(): void
{
    if (appExposeErrorDetails()) {
        return;
    }

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'php-errors.log');
    }

    set_exception_handler(static function (Throwable $e): void {
        error_log(sprintf(
            'Uncaught %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title></head><body>'
            . '<p>Ha ocurrido un error inesperado. Inténtalo de nuevo en unos minutos.</p>'
            . '<p><a href="/">Volver al inicio</a></p></body></html>';
    });
}

/**
 * Mensaje seguro para UI. InvalidArgumentException = reglas de negocio legibles.
 */
function appSafeExceptionMessage(Throwable $e, string $generic = 'No se pudo completar la operación. Inténtalo de nuevo.'): string
{
    if ($e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }
    if (appExposeErrorDetails()) {
        return $e->getMessage();
    }

    return $generic;
}
