<?php

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$sql = file_get_contents(__DIR__ . '/add_resultado_reportado_en.sql');
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer add_resultado_reportado_en.sql\n");
    exit(1);
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt === '') {
        continue;
    }
    try {
        $db->exec($stmt);
        echo "OK: " . substr(str_replace("\n", ' ', $stmt), 0, 80) . "…\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "Skip: resultado_reportado_en ya existe\n";
        } else {
            throw $e;
        }
    }
}
