<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
try {
    $db->exec('ALTER TABLE notificaciones_usuarios ADD COLUMN url_destino VARCHAR(255) DEFAULT NULL AFTER mensaje');
    echo "OK url_destino\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "SKIP url_destino\n";
    } else {
        throw $e;
    }
}
