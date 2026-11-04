<?php

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/perfil_helpers.php';
require_once __DIR__ . '/standings_service.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/legacy_schema.php';

function homeUsaEsquemaPadel(PDO $db): bool
{
    require_once __DIR__ . '/bootstrap.php';

    return voleaPadelInstallReadyOrEmpty($db);
}

function fetchGruposLigaActivos(PDO $db): array
{
    return $db->query(
        "SELECT g.id, g.nombre, c.id AS competicion_id, c.nombre AS competicion_nombre
         FROM competicion_grupos g
         INNER JOIN competiciones c ON c.id = g.competicion_id
         WHERE c.tipo = 'liga' AND c.estado IN ('abierta', 'en_curso')
         ORDER BY c.nombre ASC, g.orden ASC, g.nombre ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function fetchClasificacionGrupoPublica(PDO $db, int $competicionId, int $grupoId): array
{
    $rows = leagueStandingsService($db)->fetchGrupoStandings($competicionId, $grupoId);
    foreach ($rows as &$row) {
        $row['nombre_lista'] = $row['nombre_display'] ?? 'Pareja';
    }
    unset($row);

    return $rows;
}

function fetchUltimosResultadosPublicos(PDO $db, int $limit = 12): array
{
    if (!homeUsaEsquemaPadel($db)) {
        return [];
    }
    $limit = max(1, min(30, $limit));
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $sql = "SELECT p.id, p.fecha, p.ganador_pareja_id, p.pareja_a_id, p.pareja_b_id,
                   c.nombre AS liga_nombre, g.nombre AS grupo_nombre,
                   ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
            FROM partidos p
            INNER JOIN competiciones c ON c.id = p.competicion_id
            LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
            INNER JOIN parejas pa ON pa.id = p.pareja_a_id
            INNER JOIN parejas pb ON pb.id = p.pareja_b_id
            WHERE p.estado = 'validado'
            ORDER BY p.fecha DESC, p.id DESC
            LIMIT {$limit}";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['resultado'] = padelFormatSetsForPartido($db, (int) $row['id']);
        $row['grupo_label'] = trim($row['liga_nombre'] . ($row['grupo_nombre'] ? ' · ' . $row['grupo_nombre'] : ''));
    }
    unset($row);

    return $rows;
}

/** Datos de portada pública (alerta torneo, grupos, resultados). */
function loadPublicHomePage(PDO $db): array
{
    parejasTieneNombreDisplay($db);

    $usaEsquemaPadel = homeUsaEsquemaPadel($db);
    $torneo_alerta = null;
    $grupos = [];
    $ultimosResultados = [];

    if ($usaEsquemaPadel) {
        $tipoIn = competicionTipoPullSqlIn();
        $stmt_alerta = $db->query(
            "SELECT nombre AS titulo FROM competiciones
             WHERE tipo IN ({$tipoIn}) AND estado = 'abierta'
             AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
             ORDER BY COALESCE(fecha_inicio, '9999-12-31') ASC
             LIMIT 1"
        );
        $torneo_alerta = $stmt_alerta->fetch(PDO::FETCH_ASSOC) ?: null;
        $grupos = fetchGruposLigaActivos($db);
        $ultimosResultados = fetchUltimosResultadosPublicos($db, 12);
    }

    return [
        'usaEsquemaPadel' => $usaEsquemaPadel,
        'torneo_alerta' => $torneo_alerta,
        'grupos' => $grupos,
        'ultimosResultados' => $ultimosResultados,
    ];
}

function fetchClasificacionHomePublic(PDO $db, array $grupo, bool $usaEsquemaPadel): array
{
    if (!$usaEsquemaPadel) {
        return [];
    }

    return fetchClasificacionGrupoPublica($db, (int) $grupo['competicion_id'], (int) $grupo['id']);
}
