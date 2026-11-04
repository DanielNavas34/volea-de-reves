<?php

/**
 * Historial y estadísticas por pareja / jugador (Fase 8).
 */

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/partido_snapshot.php';
require_once __DIR__ . '/standings_service.php';
require_once __DIR__ . '/usuario_padel_helpers.php';

final class LeagueHistoryService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function fetchPartidosPareja(int $parejaId, ?string $estado = 'validado', ?int $limit = 50): array
    {
        return fetchPartidosParejaUsuario($this->db, $parejaId, $estado, $limit);
    }

    /**
     * Partidos validados en los que el jugador aparece en snapshot (integrantes al validar).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchPartidosJugador(int $usuarioId, ?int $limit = 50): array
    {
        if ($usuarioId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit ?? 50));
        $etiqA = sqlParejaEtiqueta('pa', $this->db);
        $etiqB = sqlParejaEtiqueta('pb', $this->db);

        if (padelSnapshotTableAvailable($this->db)) {
            $sql = "SELECT DISTINCT p.*, c.nombre AS comp_nombre, g.nombre AS grupo_nombre,
                    ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b,
                    s.pareja_id AS mi_pareja_en_partido
                    FROM partidos p
                    INNER JOIN partido_integrantes_snapshot s ON s.partido_id = p.id AND s.usuario_id = :uid
                    INNER JOIN competiciones c ON c.id = p.competicion_id
                    LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
                    INNER JOIN parejas pa ON pa.id = p.pareja_a_id
                    INNER JOIN parejas pb ON pb.id = p.pareja_b_id
                    WHERE p.estado = 'validado'
                    ORDER BY p.fecha DESC, p.id DESC
                    LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['uid' => $usuarioId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $parejaId = fetchParejaIdUsuario($this->db, $usuarioId);
            if (!$parejaId) {
                return [];
            }
            $rows = fetchPartidosParejaUsuario($this->db, $parejaId, 'validado', $limit);
            foreach ($rows as &$row) {
                $row['mi_pareja_en_partido'] = $parejaId;
            }
            unset($row);

            return $rows;
        }

        foreach ($rows as &$row) {
            $row['resultado'] = padelFormatSetsForPartido($this->db, (int) $row['id']);
        }
        unset($row);

        return $rows;
    }

    /** @return array{partidos_jugados:int, partidos_ganados:int, partidos_perdidos:int} */
    public function resumenJugador(int $usuarioId): array
    {
        $partidos = $this->fetchPartidosJugador($usuarioId, 100);
        $ganados = 0;
        foreach ($partidos as $p) {
            $miPareja = (int) ($p['mi_pareja_en_partido'] ?? 0);
            if ($miPareja > 0 && (int) ($p['ganador_pareja_id'] ?? 0) === $miPareja) {
                $ganados++;
            }
        }
        $pj = count($partidos);

        return [
            'partidos_jugados' => $pj,
            'partidos_ganados' => $ganados,
            'partidos_perdidos' => $pj - $ganados,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resumenPareja(int $parejaId, ?array $contexto): ?array
    {
        if (!$contexto) {
            return null;
        }
        $standings = leagueStandingsService($this->db);
        $compId = (int) $contexto['competicion_id'];
        $grupoId = isset($contexto['grupo_id']) ? (int) $contexto['grupo_id'] : null;
        $clasif = fetchClasificacionPareja($this->db, $parejaId, $contexto);
        if (!$clasif) {
            return null;
        }

        return [
            'puntos' => (int) $clasif['puntos'],
            'partidos_jugados' => (int) $clasif['partidos_jugados'],
            'partidos_ganados' => (int) $clasif['partidos_ganados'],
            'posicion_grupo' => (int) ($clasif['posicion'] ?? 0),
            'posicion_global' => ($contexto['modalidad_liga'] ?? '') === 'grupos'
                ? ($standings->posicionParejaEnCompeticionGlobal($compId, $parejaId) ?? 0)
                : (int) ($clasif['posicion'] ?? 0),
            'sets_favor' => (int) ($clasif['sets_favor'] ?? 0),
            'sets_contra' => (int) ($clasif['sets_contra'] ?? 0),
        ];
    }
}

function leagueHistoryService(PDO $db): LeagueHistoryService
{
    return new LeagueHistoryService($db);
}
