<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/legacy_schema.php';

$db = getDBConnection();

if (!dbColumnExists($db, 'usuarios', 'password_changed_at')) {
    $sql = file_get_contents(__DIR__ . '/add_password_changed_at.sql');
    if ($sql !== false) {
        $db->exec($sql);
        echo "Added usuarios.password_changed_at\n";
    }
} else {
    echo "usuarios.password_changed_at already exists\n";
}
