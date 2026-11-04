<?php

require_once __DIR__ . '/standings_service.php';
require_once __DIR__ . '/scoring/MatchFormat.php';
require_once __DIR__ . '/scoring/ScoringValidator.php';

function padelLeagueConfigPoints(PDO $db): array
{
    return leagueStandingsService($db)->getPointsConfig();
}

/** Parsea "6-4, 7-5" o "6-4 7-5" en filas de sets. */
function padelParseSetsString(string $resultado, ?string $formatCode = null): array
{
    $resultado = trim($resultado);
    if ($resultado === '') {
        return [];
    }
    $validator = new ScoringValidator(MatchFormat::normalize($formatCode));
    $partes = preg_split('/[\s,;]+/', $resultado, -1, PREG_SPLIT_NO_EMPTY);
    $sets = [];
    foreach ($partes as $i => $parte) {
        if (!preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', trim($parte), $m)) {
            throw new InvalidArgumentException('Formato de set no válido: ' . $parte);
        }
        $num = $i + 1;
        $ja = (int) $m[1];
        $jb = (int) $m[2];
        if (!$validator->isValidSet($ja, $jb, $num)) {
            throw new InvalidArgumentException('Set ' . $num . ' no válido.');
        }
        $sets[] = [
            'numero' => $num,
            'juegos_a' => $ja,
            'juegos_b' => $jb,
        ];
    }
    if ($sets !== []) {
        $validator->assertEncounterRules($sets);
    }

    return $sets;
}

function padelFormatSetsForPartido(PDO $db, int $partidoId): string
{
    $stmt = $db->prepare(
        'SELECT juegos_pareja_a, juegos_pareja_b FROM partido_sets WHERE partido_id = :id ORDER BY numero_set ASC'
    );
    $stmt->execute(['id' => $partidoId]);
    $parts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $parts[] = $row['juegos_pareja_a'] . '-' . $row['juegos_pareja_b'];
    }

    return implode(', ', $parts);
}

function padelEnsureClasificacionRow(PDO $db, int $competicionId, ?int $grupoId, int $parejaId): void
{
    leagueStandingsService($db)->ensureClasificacionRow($competicionId, $grupoId, $parejaId);
}

function padelApplyValidatedMatchPoints(PDO $db, int $partidoId): void
{
    leagueStandingsService($db)->applyValidatedMatchPoints($partidoId);
}

function padelRevertValidatedMatchPoints(PDO $db, int $partidoId): void
{
    leagueStandingsService($db)->revertValidatedMatchPoints($partidoId);
}

function padelReplacePartidoSets(PDO $db, int $partidoId, string $resultadoTexto): void
{
    $fmt = MatchFormat::DEFAULT;
    $st = $db->prepare('SELECT formato_partido_codigo FROM partidos WHERE id = :id LIMIT 1');
    $st->execute(['id' => $partidoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['formato_partido_codigo'])) {
        $fmt = MatchFormat::normalize($row['formato_partido_codigo']);
    }
    $sets = padelParseSetsString($resultadoTexto, $fmt);
    if ($sets === []) {
        throw new InvalidArgumentException('Indica al menos un set.');
    }
    $db->prepare('DELETE FROM partido_sets WHERE partido_id = :id')->execute(['id' => $partidoId]);
    $ins = $db->prepare(
        'INSERT INTO partido_sets (partido_id, numero_set, juegos_pareja_a, juegos_pareja_b) VALUES (:pid, :n, :a, :b)'
    );
    foreach ($sets as $set) {
        $ins->execute([
            'pid' => $partidoId,
            'n' => $set['numero'],
            'a' => $set['juegos_a'],
            'b' => $set['juegos_b'],
        ]);
    }
}
