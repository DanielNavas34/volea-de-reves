<?php

require_once __DIR__ . '/env.php';

/** Esquema pádel (competiciones, parejas, etc.) — tiene prioridad sobre tablas legacy residuales. */
function padelCompeticionesSchemaAvailable(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $dbName = env('DB_NAME', 'liga_padel');
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
    );
    $stmt->execute([$dbName, 'competiciones']);
    $cache = (int) $stmt->fetchColumn() > 0;

    return $cache;
}

/** Comprueba si el esquema legacy de tenis (liga individual) sigue presente. */
function legacyLeagueSchemaAvailable(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (padelCompeticionesSchemaAvailable($db)) {
        $dbName = env('DB_NAME', 'liga_padel');
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$dbName, 'partidos', 'pareja_a_id']);
        $cache = (int) $stmt->fetchColumn() === 0;
        return $cache;
    }

    $dbName = env('DB_NAME', 'liga_padel');
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$dbName, 'partidos', 'jugador1_id']);
    $cache = (int) $stmt->fetchColumn() > 0;

    return $cache;
}

function dbColumnExists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (int) $stmt->fetchColumn() > 0;

    return $cache[$key];
}

function legacyTorneosSchemaAvailable(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (padelCompeticionesSchemaAvailable($db)) {
        $cache = false;
        return false;
    }

    $dbName = env('DB_NAME', 'liga_padel');
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
    );
    $stmt->execute([$dbName, 'torneos']);
    $cache = (int) $stmt->fetchColumn() > 0;

    return $cache;
}
