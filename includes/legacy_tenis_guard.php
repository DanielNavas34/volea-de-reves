<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/legacy_schema.php';

/**
 * En instalaciones Volea de revés (esquema pádel), bloquea rutas del gestor de tenis individual legacy.
 */
function legacyTenisRoutesBlocked(): bool
{
    static $blocked = null;
    if ($blocked !== null) {
        return $blocked;
    }
    try {
        require_once __DIR__ . '/../config/database.php';
        $db = getDBConnection();
        $blocked = padelCompeticionesSchemaAvailable($db);
    } catch (Throwable $e) {
        $blocked = false;
    }

    return $blocked;
}

function redirectLegacyTenisBlocked(string $panelRelative = 'index.php'): void
{
    if (!legacyTenisRoutesBlocked()) {
        return;
    }
    $sep = str_contains($panelRelative, '?') ? '&' : '?';
    header('Location: ' . $panelRelative . $sep . 'legacy=blocked', true, 302);
    exit;
}
