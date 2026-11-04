<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function run_schema_tests(): array
{
    $results = [];
    $db = getDBConnection();
    $dbName = env('DB_NAME', 'liga_padel');

    $results[] = ['name' => 'database_connection', 'ok' => true];

    $required = [
        'usuarios', 'parejas', 'pareja_miembros', 'competiciones', 'competicion_grupos',
        'inscripciones', 'partidos', 'partido_sets', 'clasificacion_parejas', 'configuracion',
    ];

    foreach ($required as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
        );
        $stmt->execute([$dbName, $table]);
        $exists = (int) $stmt->fetchColumn() > 0;
        $results[] = [
            'name' => 'table_' . $table,
            'ok' => $exists,
        ];
        test_assert($exists, "Falta la tabla $table en $dbName");
    }

    $stmt = $db->prepare('SELECT valor FROM configuracion WHERE clave = ?');
    $stmt->execute(['liga_puntos_victoria']);
    $victoria = $stmt->fetchColumn();
    test_assert($victoria === '180', 'liga_puntos_victoria debe ser 180');
    $results[] = ['name' => 'config_liga_puntos_victoria', 'ok' => true];

    return $results;
}
