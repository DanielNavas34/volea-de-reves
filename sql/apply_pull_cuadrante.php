<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();

$alters = [
    "ALTER TABLE competiciones ADD COLUMN pull_num_pistas TINYINT UNSIGNED NULL DEFAULT 3",
    "ALTER TABLE competiciones ADD COLUMN pull_capacidad_jugadores SMALLINT UNSIGNED NULL",
    "ALTER TABLE competiciones ADD COLUMN pull_ronda_actual INT UNSIGNED NOT NULL DEFAULT 0",
];

foreach ($alters as $sql) {
    try {
        $db->exec($sql);
        echo "[OK] {$sql}\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "[SKIP] columna ya existe\n";
        } else {
            echo "[WARN] {$e->getMessage()}\n";
        }
    }
}

$ddl = file_get_contents(__DIR__ . '/add_pull_cuadrante.sql');
if ($ddl === false) {
    exit(1);
}
foreach (preg_split('/;\s*\n/', $ddl) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with(strtoupper($stmt), 'ALTER')) {
        continue;
    }
    try {
        $db->exec($stmt);
        echo "[OK] " . substr(str_replace("\n", ' ', $stmt), 0, 60) . "…\n";
    } catch (PDOException $e) {
        echo "[WARN] {$e->getMessage()}\n";
    }
}

echo "Cuadrante Pull listo.\n";
