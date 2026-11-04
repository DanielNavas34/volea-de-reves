<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$sql = file_get_contents(__DIR__ . '/update_torneos_a_pull.sql');
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer update_torneos_a_pull.sql\n");
    exit(1);
}

$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));

foreach ($statements as $statement) {
    if ($statement === '' || str_starts_with($statement, '--')) {
        continue;
    }
    try {
        $db->exec($statement);
        echo "[OK] " . substr(str_replace("\n", ' ', $statement), 0, 80) . "…\n";
    } catch (PDOException $e) {
        echo "[WARN] " . $e->getMessage() . "\n";
    }
}

echo "Migración Pull finalizada.\n";
