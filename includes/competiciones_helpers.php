<?php

require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/perfil_helpers.php';

/** Convierte estado del formulario legacy (abierto/cerrado) al enum de competiciones. */
function competicionEstadoFromForm(string $estadoForm): string
{
    return match ($estadoForm) {
        'abierto' => 'abierta',
        'cancelado' => 'cancelada',
        'cerrado' => 'finalizada',
        'aplazado' => 'borrador',
        default => 'borrador',
    };
}

/** Convierte estado de competiciones al texto esperado por plantillas legacy. */
function competicionEstadoToForm(string $estadoDb): string
{
    return match ($estadoDb) {
        'abierta' => 'abierto',
        'en_curso' => 'abierto',
        'cancelada' => 'cancelado',
        'finalizada' => 'cerrado',
        'borrador' => 'aplazado',
        default => 'cerrado',
    };
}

/** Pulls (antes torneos) con alias legacy titulo, cartel, estado. */
function fetchTorneosAdmin(PDO $db): array
{
    return fetchPullsAdmin($db);
}

function fetchPullsAdmin(PDO $db): array
{
    if (!padelCompeticionesSchemaAvailable($db)) {
        return [];
    }

    $tipoIn = competicionTipoPullSqlIn();
    $rows = $db->query(
        "SELECT id, nombre AS titulo, descripcion, cartel_ruta AS cartel, fecha_inicio, fecha_fin, estado
         FROM competiciones
         WHERE tipo IN ({$tipoIn})
         ORDER BY COALESCE(fecha_inicio, '9999-12-31') DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['estado'] = competicionEstadoToForm((string) $row['estado']);
        $row['puntuable'] = 0;
    }
    unset($row);

    return $rows;
}
