<?php
/**
 * CLI: php sql/apply_foto_perfil.php
 * Añade usuarios.foto_perfil si no existe.
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/legacy_schema.php';

$db = getDBConnection();
if (dbColumnExists($db, 'usuarios', 'foto_perfil')) {
    echo "usuarios.foto_perfil already exists\n";
    exit(0);
}
$sql = file_get_contents(__DIR__ . '/add_foto_perfil.sql');
$db->exec($sql);
echo "Added usuarios.foto_perfil\n";
