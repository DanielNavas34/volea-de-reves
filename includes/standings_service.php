<?php

/**
 * Clasificación de liga — puntos configurables y desempates (Fase 7).
 */

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/partido_snapshot.php';

final class LeagueStandingsService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{victoria:int, derrota:int} */
    public function getPointsConfig(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $rows = $this->db->query(
            "SELECT clave, valor FROM configuracion WHERE clave IN ('liga_puntos_victoria', 'liga_puntos_derrota')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $cache = [
            'victoria' => (int) ($rows['liga_puntos_victoria'] ?? 180),
            'derrota' => (int) ($rows['liga_puntos_derrota'] ?? 50),
        ];

        return $cache;
    }

    public function ensureClasificacionRow(int $competicionId, ?int $grupoId, int $parejaId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM clasificacion_parejas
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid
             LIMIT 1'
        );
        $stmt->execute(['cid' => $competicionId, 'pid' => $parejaId, 'gid' => $grupoId]);
        if ($stmt->fetch()) {
            return;
        }
        $this->db->prepare(
            'INSERT INTO clasificacion_parejas (competicion_id, grupo_id, pareja_id) VALUES (:cid, :gid, :pid)'
        )->execute(['cid' => $competicionId, 'gid' => $grupoId, 'pid' => $parejaId]);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    public function compareStandingsRows(array $a, array $b): int
    {
        $keys = [
            fn (array $r): int => (int) ($r['puntos'] ?? 0),
            fn (array $r): int => (int) ($r['partidos_ganados'] ?? 0),
            fn (array $r): int => (int) ($r['sets_favor'] ?? 0) - (int) ($r['sets_contra'] ?? 0),
            fn (array $r): int => (int) ($r['juegos_favor'] ?? 0) - (int) ($r['juegos_contra'] ?? 0),
        ];
        foreach ($keys as $getter) {
            $va = $getter($a);
            $vb = $getter($b);
            if ($va !== $vb) {
                return $vb <=> $va;
            }
        }

        return (int) ($a['pareja_id'] ?? $a['id'] ?? 0) <=> (int) ($b['pareja_id'] ?? $b['id'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function sortStandingsRows(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $this->compareStandingsRows($a, $b));

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchGrupoStandings(int $competicionId, ?int $grupoId, bool $withParejaLabel = true): array
    {
        if ($withParejaLabel) {
            $etiq = sqlParejaEtiqueta('p', $this->db);
            $stmt = $this->db->prepare(
                "SELECT cp.pareja_id, cp.pareja_id AS id, ({$etiq}) AS nombre_display,
                        cp.puntos, cp.partidos_jugados, cp.partidos_ganados,
                        cp.sets_favor, cp.sets_contra, cp.juegos_favor, cp.juegos_contra
                 FROM clasificacion_parejas cp
                 INNER JOIN parejas p ON p.id = cp.pareja_id
                 WHERE cp.competicion_id = :cid AND cp.grupo_id <=> :gid"
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT cp.pareja_id, cp.puntos, cp.partidos_jugados, cp.partidos_ganados,
                        cp.sets_favor, cp.sets_contra, cp.juegos_favor, cp.juegos_contra
                 FROM clasificacion_parejas cp
                 WHERE cp.competicion_id = :cid AND cp.grupo_id <=> :gid'
            );
        }
        $stmt->execute(['cid' => $competicionId, 'gid' => $grupoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->sortStandingsRows($rows);
    }

    /**
     * Ranking informativo de toda la competición (todos los grupos).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchCompeticionGlobalStandings(int $competicionId): array
    {
        $etiq = sqlParejaEtiqueta('p', $this->db);
        $stmt = $this->db->prepare(
            "SELECT cp.pareja_id, cp.pareja_id AS id, ({$etiq}) AS nombre_display,
                    cp.puntos, cp.partidos_jugados, cp.partidos_ganados,
                    cp.sets_favor, cp.sets_contra, cp.juegos_favor, cp.juegos_contra,
                    g.nombre AS grupo_nombre, cp.grupo_id
             FROM clasificacion_parejas cp
             INNER JOIN parejas p ON p.id = cp.pareja_id
             LEFT JOIN competicion_grupos g ON g.id = cp.grupo_id
             WHERE cp.competicion_id = :cid"
        );
        $stmt->execute(['cid' => $competicionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->sortStandingsRows($rows);
    }

    public function posicionParejaEnGrupo(int $competicionId, ?int $grupoId, int $parejaId): ?int
    {
        $rows = $this->fetchGrupoStandings($competicionId, $grupoId, false);
        foreach ($rows as $i => $row) {
            if ((int) $row['pareja_id'] === $parejaId) {
                return $i + 1;
            }
        }

        return null;
    }

    public function posicionParejaEnCompeticionGlobal(int $competicionId, int $parejaId): ?int
    {
        $rows = $this->fetchCompeticionGlobalStandings($competicionId);
        foreach ($rows as $i => $row) {
            if ((int) $row['pareja_id'] === $parejaId) {
                return $i + 1;
            }
        }

        return null;
    }

    public function applyValidatedMatchPoints(int $partidoId): void
    {
        $stmt = $this->db->prepare(
            'SELECT competicion_id, grupo_id, pareja_a_id, pareja_b_id, ganador_pareja_id, estado
             FROM partidos WHERE id = :id'
        );
        $stmt->execute(['id' => $partidoId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || !$p['ganador_pareja_id']) {
            throw new RuntimeException('Partido sin ganador definido.');
        }
        if ($p['estado'] !== 'validado') {
            throw new RuntimeException('El partido debe estar validado antes de aplicar puntos.');
        }

        $ganador = (int) $p['ganador_pareja_id'];
        $perdedor = $ganador === (int) $p['pareja_a_id'] ? (int) $p['pareja_b_id'] : (int) $p['pareja_a_id'];
        $compId = (int) $p['competicion_id'];
        $grupoId = $p['grupo_id'] !== null ? (int) $p['grupo_id'] : null;
        $pts = $this->getPointsConfig();

        $this->ensureClasificacionRow($compId, $grupoId, $ganador);
        $this->ensureClasificacionRow($compId, $grupoId, $perdedor);

        $win = $this->db->prepare(
            'UPDATE clasificacion_parejas SET puntos = puntos + :pts, partidos_jugados = partidos_jugados + 1, partidos_ganados = partidos_ganados + 1
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid'
        );
        $win->execute(['pts' => $pts['victoria'], 'cid' => $compId, 'pid' => $ganador, 'gid' => $grupoId]);

        $loss = $this->db->prepare(
            'UPDATE clasificacion_parejas SET puntos = puntos + :pts, partidos_jugados = partidos_jugados + 1
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid'
        );
        $loss->execute(['pts' => $pts['derrota'], 'cid' => $compId, 'pid' => $perdedor, 'gid' => $grupoId]);

        $this->applySetStatsToClasificacion(
            $partidoId,
            (int) $p['pareja_a_id'],
            (int) $p['pareja_b_id'],
            $compId,
            $grupoId
        );

        padelEnsurePartidoIntegrantesSnapshot($this->db, $partidoId);
    }

    public function revertValidatedMatchPoints(int $partidoId): void
    {
        $stmt = $this->db->prepare(
            'SELECT competicion_id, grupo_id, pareja_a_id, pareja_b_id, ganador_pareja_id FROM partidos WHERE id = :id'
        );
        $stmt->execute(['id' => $partidoId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || !$p['ganador_pareja_id']) {
            return;
        }

        $ganador = (int) $p['ganador_pareja_id'];
        $perdedor = $ganador === (int) $p['pareja_a_id'] ? (int) $p['pareja_b_id'] : (int) $p['pareja_a_id'];
        $compId = (int) $p['competicion_id'];
        $grupoId = $p['grupo_id'] !== null ? (int) $p['grupo_id'] : null;
        $pts = $this->getPointsConfig();

        $win = $this->db->prepare(
            'UPDATE clasificacion_parejas SET puntos = puntos - :pts, partidos_jugados = partidos_jugados - 1, partidos_ganados = partidos_ganados - 1
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid'
        );
        $win->execute(['pts' => $pts['victoria'], 'cid' => $compId, 'pid' => $ganador, 'gid' => $grupoId]);

        $loss = $this->db->prepare(
            'UPDATE clasificacion_parejas SET puntos = puntos - :pts, partidos_jugados = partidos_jugados - 1
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid'
        );
        $loss->execute(['pts' => $pts['derrota'], 'cid' => $compId, 'pid' => $perdedor, 'gid' => $grupoId]);

        $this->revertSetStatsFromClasificacion(
            $partidoId,
            (int) $p['pareja_a_id'],
            (int) $p['pareja_b_id'],
            $compId,
            $grupoId
        );
    }

    /** Recalcula filas del grupo desde partidos validados (mantenimiento / tests). */
    public function recalcularGrupo(int $competicionId, int $grupoId): void
    {
        $this->db->prepare(
            'UPDATE clasificacion_parejas SET puntos = 0, partidos_jugados = 0, partidos_ganados = 0,
             sets_favor = 0, sets_contra = 0, juegos_favor = 0, juegos_contra = 0
             WHERE competicion_id = :cid AND grupo_id = :gid'
        )->execute(['cid' => $competicionId, 'gid' => $grupoId]);

        $stmt = $this->db->prepare(
            "SELECT id FROM partidos WHERE competicion_id = :cid AND grupo_id = :gid AND estado = 'validado' ORDER BY id ASC"
        );
        $stmt->execute(['cid' => $competicionId, 'gid' => $grupoId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $partidoId) {
            $this->applyValidatedMatchPoints((int) $partidoId);
        }
    }

    private function applySetStatsToClasificacion(
        int $partidoId,
        int $parejaAId,
        int $parejaBId,
        int $competicionId,
        ?int $grupoId
    ): void {
        [$setsA, $setsB, $juegosA, $juegosB] = $this->aggregateSetStats($partidoId);
        if ($setsA + $setsB === 0) {
            return;
        }
        $upd = $this->db->prepare(
            'UPDATE clasificacion_parejas SET
                sets_favor = sets_favor + :sf, sets_contra = sets_contra + :sc,
                juegos_favor = juegos_favor + :jf, juegos_contra = juegos_contra + :jc
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid'
        );
        $upd->execute(['sf' => $setsA, 'sc' => $setsB, 'jf' => $juegosA, 'jc' => $juegosB, 'cid' => $competicionId, 'pid' => $parejaAId, 'gid' => $grupoId]);
        $upd->execute(['sf' => $setsB, 'sc' => $setsA, 'jf' => $juegosB, 'jc' => $juegosA, 'cid' => $competicionId, 'pid' => $parejaBId, 'gid' => $grupoId]);
    }

    private function revertSetStatsFromClasificacion(
        int $partidoId,
        int $parejaAId,
        int $parejaBId,
        int $competicionId,
        ?int $grupoId
    ): void {
        [$setsA, $setsB, $juegosA, $juegosB] = $this->aggregateSetStats($partidoId);
        if ($setsA + $setsB === 0) {
            return;
        }
        $upd = $this->db->prepare(
            'UPDATE clasificacion_parejas SET
                sets_favor = sets_favor - :sf, sets_contra = sets_contra - :sc,
                juegos_favor = juegos_favor - :jf, juegos_contra = juegos_contra - :jc
             WHERE competicion_id = :cid AND pareja_id = :pid AND grupo_id <=> :gid'
        );
        $upd->execute(['sf' => $setsA, 'sc' => $setsB, 'jf' => $juegosA, 'jc' => $juegosB, 'cid' => $competicionId, 'pid' => $parejaAId, 'gid' => $grupoId]);
        $upd->execute(['sf' => $setsB, 'sc' => $setsA, 'jf' => $juegosB, 'jc' => $juegosA, 'cid' => $competicionId, 'pid' => $parejaBId, 'gid' => $grupoId]);
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function aggregateSetStats(int $partidoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT juegos_pareja_a, juegos_pareja_b FROM partido_sets WHERE partido_id = :id ORDER BY numero_set ASC'
        );
        $stmt->execute(['id' => $partidoId]);
        $setsA = 0;
        $setsB = 0;
        $juegosA = 0;
        $juegosB = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ja = (int) $row['juegos_pareja_a'];
            $jb = (int) $row['juegos_pareja_b'];
            $juegosA += $ja;
            $juegosB += $jb;
            if ($ja > $jb) {
                $setsA++;
            } elseif ($jb > $ja) {
                $setsB++;
            }
        }

        return [$setsA, $setsB, $juegosA, $juegosB];
    }
}

function leagueStandingsService(PDO $db): LeagueStandingsService
{
    return new LeagueStandingsService($db);
}
