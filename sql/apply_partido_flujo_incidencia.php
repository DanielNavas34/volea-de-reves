<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$sql = file_get_contents(__DIR__ . '/add_partido_flujo_incidencia.sql');
foreach (preg_split('/;\s*\R/', $sql) as $q) {
    $q = trim($q);
    if ($q === '' || str_starts_with($q, '--') || preg_match('/^USE\s+/i', $q)) {
        continue;
    }
    try {
        $db->exec($q);
        echo "OK\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'duplicate')) {
            echo "SKIP: " . $e->getMessage() . "\n";
            continue;
        }
        throw $e;
    }
}
echo "Migración flujo/incidencia completada.\n";
