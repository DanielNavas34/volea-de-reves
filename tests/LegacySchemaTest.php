<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/legacy_schema.php';

function test_legacy_schema_prefers_padel(): bool
{
    $db = getDBConnection();
    if (!padelCompeticionesSchemaAvailable($db)) {
        echo "[SKIP] legacy_schema: no competiciones table\n";
        return true;
    }
    if (legacyTorneosSchemaAvailable($db)) {
        echo "[FAIL] legacyTorneosSchemaAvailable should be false when competiciones exists\n";
        return false;
    }
    $db->query('SELECT COUNT(*) FROM competicion_grupos');
    echo "[OK] legacy_schema_prefers_padel\n";
    return true;
}
