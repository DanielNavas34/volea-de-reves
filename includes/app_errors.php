<?php

require_once __DIR__ . '/env.php';

/** Detalle técnico (SQL, etc.) solo en local o con APP_DEBUG=true. */
function appExposeErrorDetails(): bool
{
    if (env('APP_DEBUG', 'false') === 'true') {
        return true;
    }

    return env('APP_ENV', 'production') === 'local';
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
