<?php

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$sql = file_get_contents(__DIR__ . '/update_torneos_a_pull.sql');
if ($sql && preg_match('/CREATE TABLE IF NOT EXISTS inscripciones_pull[\s\S]*?;/', $sql, $m)) {
    $db->exec($m[0]);
    echo "inscripciones_pull OK\n";
} else {
    echo "CREATE block not found\n";
    exit(1);
}
