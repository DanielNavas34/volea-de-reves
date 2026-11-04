<?php

require_once __DIR__ . '/pull_service.php';
require_once __DIR__ . '/competiciones_helpers.php';

function pullCuadranteSchemaReady(PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $db->query('SELECT pull_num_pistas FROM competiciones LIMIT 1');
        $db->query('SELECT 1 FROM pull_rondas LIMIT 1');
        $ok = true;
    } catch (PDOException) {
        $ok = false;
    }

    return $ok;
}

function pullCapacidadPorPistas(int $numPistas, ?int $override = null): int
{
    if ($override !== null && $override > 0) {
        return $override;
    }

    return max(4, $numPistas * 4);
}

/** @return array<string, mixed>|null */
function pullFetchCompeticion(PDO $db, int $competicionId): ?array
{
    $tipoIn = competicionTipoPullSqlIn();
    $stmt = $db->prepare(
        "SELECT id, nombre, estado, fecha_inicio, fecha_fin, descripcion,
                pull_num_pistas, pull_capacidad_jugadores, pull_ronda_actual
         FROM competiciones WHERE id = :id AND tipo IN ({$tipoIn}) LIMIT 1"
    );
    $stmt->execute(['id' => $competicionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function pullCountInscritos(PDO $db, int $competicionId, bool $soloConfirmados = true): int
{
    if (!pullInscripcionesTableExists($db)) {
        return 0;
    }
    $sql = 'SELECT COUNT(*) FROM inscripciones_pull WHERE competicion_id = :cid';
    if ($soloConfirmados) {
        $sql .= " AND estado = 'confirmada'";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute(['cid' => $competicionId]);

    return (int) $stmt->fetchColumn();
}

/** @return list<int> */
function pullIdsInscritosConfirmados(PDO $db, int $competicionId): array
{
    $stmt = $db->prepare(
        "SELECT usuario_id FROM inscripciones_pull
         WHERE competicion_id = :cid AND estado = 'confirmada'
         ORDER BY fecha_inscripcion ASC"
    );
    $stmt->execute(['cid' => $competicionId]);

    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'usuario_id'));
}

function pullUpdateConfig(PDO $db, int $competicionId, int $numPistas, ?int $capacidad): void
{
    if ($numPistas < 1 || $numPistas > 20) {
        throw new InvalidArgumentException('Número de pistas no válido (1-20).');
    }
    $cap = pullCapacidadPorPistas($numPistas, $capacidad);
    $db->prepare(
        'UPDATE competiciones SET pull_num_pistas = :p, pull_capacidad_jugadores = :c WHERE id = :id'
    )->execute(['p' => $numPistas, 'c' => $cap, 'id' => $competicionId]);
}

/** @return array<string, mixed>|null */
function pullFetchRonda(PDO $db, int $competicionId, int $numero): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM pull_rondas WHERE competicion_id = :cid AND numero = :n LIMIT 1'
    );
    $stmt->execute(['cid' => $competicionId, 'n' => $numero]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return array<string, mixed>|null */
function pullFetchRondaActual(PDO $db, int $competicionId): ?array
{
    $pull = pullFetchCompeticion($db, $competicionId);
    if (!$pull || (int) $pull['pull_ronda_actual'] < 1) {
        return null;
    }

    return pullFetchRonda($db, $competicionId, (int) $pull['pull_ronda_actual']);
}

/**
 * Cuadrante: pista => [ slots 1-4 => jugador row ]
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function pullFetchCuadranteRonda(PDO $db, int $rondaId): array
{
    $stmt = $db->prepare(
        'SELECT s.pista_numero, s.slot, s.usuario_id, u.nombre, u.apellidos
         FROM pull_pista_slots s
         INNER JOIN usuarios u ON u.id = s.usuario_id
         WHERE s.ronda_id = :rid
         ORDER BY s.pista_numero ASC, s.slot ASC'
    );
    $stmt->execute(['rid' => $rondaId]);
    $grid = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $p = (int) $row['pista_numero'];
        $grid[$p][(int) $row['slot']] = $row;
    }

    return $grid;
}

/** @return array<int, string> pista => A|B */
function pullFetchResultadosRonda(PDO $db, int $rondaId): array
{
    $stmt = $db->prepare('SELECT pista_numero, ganador_equipo FROM pull_pista_resultados WHERE ronda_id = :rid');
    $stmt->execute(['rid' => $rondaId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['pista_numero']] = (string) $row['ganador_equipo'];
    }

    return $out;
}

function pullGuardarResultadoPista(PDO $db, int $rondaId, int $pistaNumero, string $ganadorEquipo): void
{
    if (!in_array($ganadorEquipo, ['A', 'B'], true)) {
        throw new InvalidArgumentException('Equipo ganador no válido.');
    }
    $db->prepare(
        'INSERT INTO pull_pista_resultados (ronda_id, pista_numero, ganador_equipo)
         VALUES (:rid, :p, :g)
         ON DUPLICATE KEY UPDATE ganador_equipo = VALUES(ganador_equipo)'
    )->execute(['rid' => $rondaId, 'p' => $pistaNumero, 'g' => $ganadorEquipo]);
}

function pullTodosResultadosCompletos(PDO $db, int $rondaId, int $numPistas, array $cuadrante): bool
{
    $res = pullFetchResultadosRonda($db, $rondaId);
    for ($p = 1; $p <= $numPistas; $p++) {
        if (empty($cuadrante[$p]) || count($cuadrante[$p]) < 4) {
            continue;
        }
        if (!isset($res[$p])) {
            return false;
        }
    }

    return true;
}

/** @param list<int> $userIds */
function pullInsertSlots(PDO $db, int $rondaId, int $pista, array $userIds): void
{
    $slot = 1;
    foreach ($userIds as $uid) {
        if ($uid <= 0) {
            continue;
        }
        $db->prepare(
            'INSERT INTO pull_pista_slots (ronda_id, pista_numero, slot, usuario_id) VALUES (:rid, :p, :s, :u)'
        )->execute(['rid' => $rondaId, 'p' => $pista, 's' => $slot, 'u' => $uid]);
        $slot++;
    }
}

function pullGenerarPrimeraRonda(PDO $db, int $competicionId): int
{
    if (!pullCuadranteSchemaReady($db)) {
        throw new RuntimeException('Ejecuta sql/apply_pull_cuadrante.php');
    }
    $pull = pullFetchCompeticion($db, $competicionId);
    if (!$pull) {
        throw new InvalidArgumentException('Pull no encontrada.');
    }
    $numPistas = max(1, (int) ($pull['pull_num_pistas'] ?? 3));
    $jugadores = pullIdsInscritosConfirmados($db, $competicionId);
    if ((int) $pull['pull_ronda_actual'] > 0) {
        throw new InvalidArgumentException('Ya existe un cuadrante. Usa Rotar pistas para avanzar.');
    }
    if (count($jugadores) < 4) {
        throw new InvalidArgumentException('Se necesitan al menos 4 jugadores con inscripción confirmada.');
    }
    shuffle($jugadores);
    $cap = pullCapacidadPorPistas($numPistas, $pull['pull_capacidad_jugadores'] ? (int) $pull['pull_capacidad_jugadores'] : null);
    $jugadores = array_slice($jugadores, 0, $cap);

    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT INTO pull_rondas (competicion_id, numero, estado) VALUES (:cid, 1, 'en_juego')"
        )->execute(['cid' => $competicionId]);
        $rondaId = (int) $db->lastInsertId();

        $idx = 0;
        for ($p = 1; $p <= $numPistas; $p++) {
            $court = [];
            for ($s = 0; $s < 4 && $idx < count($jugadores); $s++) {
                $court[] = $jugadores[$idx++];
            }
            if (count($court) === 4) {
                pullInsertSlots($db, $rondaId, $p, $court);
            }
        }

        $db->prepare('UPDATE competiciones SET pull_ronda_actual = 1, estado = \'en_curso\' WHERE id = :id')
            ->execute(['id' => $competicionId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $rondaId;
}

/**
 * Coloca un jugador en la siguiente ronda (pista objetivo, primer hueco libre).
 *
 * @param array<int, array<int, int>> $next
 */
function pullPlacePlayer(array &$next, int $numPistas, int $targetPista, int $usuarioId): void
{
    $targetPista = max(1, min($numPistas, $targetPista));
    if (!isset($next[$targetPista])) {
        $next[$targetPista] = [];
    }
    if (in_array($usuarioId, $next[$targetPista], true)) {
        return;
    }
    if (count($next[$targetPista]) >= 4) {
        for ($p = 1; $p <= $numPistas; $p++) {
            if (count($next[$p] ?? []) < 4 && !in_array($usuarioId, $next[$p] ?? [], true)) {
                $next[$p][] = $usuarioId;
                return;
            }
        }
        return;
    }
    $next[$targetPista][] = $usuarioId;
}

function pullRotarPistas(PDO $db, int $competicionId): int
{
    $pull = pullFetchCompeticion($db, $competicionId);
    if (!$pull || (int) $pull['pull_ronda_actual'] < 1) {
        throw new InvalidArgumentException('No hay ronda activa.');
    }
    $numPistas = max(1, (int) ($pull['pull_num_pistas'] ?? 3));
    $ronda = pullFetchRondaActual($db, $competicionId);
    if (!$ronda) {
        throw new InvalidArgumentException('Ronda actual no encontrada.');
    }
    $rondaId = (int) $ronda['id'];
    $cuadrante = pullFetchCuadranteRonda($db, $rondaId);
    if (!pullTodosResultadosCompletos($db, $rondaId, $numPistas, $cuadrante)) {
        throw new InvalidArgumentException('Indica el ganador (equipo A o B) en cada pista antes de rotar.');
    }
    $resultados = pullFetchResultadosRonda($db, $rondaId);

    /** @var array<int, list<int>> $next */
    $next = [];

    for ($p = 1; $p <= $numPistas; $p++) {
        if (empty($cuadrante[$p]) || count($cuadrante[$p]) < 4) {
            continue;
        }
        $u1 = (int) $cuadrante[$p][1]['usuario_id'];
        $u2 = (int) $cuadrante[$p][2]['usuario_id'];
        $u3 = (int) $cuadrante[$p][3]['usuario_id'];
        $u4 = (int) $cuadrante[$p][4]['usuario_id'];
        $ganador = $resultados[$p] ?? 'A';
        $win = $ganador === 'A' ? [$u1, $u2] : [$u3, $u4];
        $lose = $ganador === 'A' ? [$u3, $u4] : [$u1, $u2];

        pullPlacePlayer($next, $numPistas, $p - 1, $win[0]);
        pullPlacePlayer($next, $numPistas, $p, $win[1]);
        pullPlacePlayer($next, $numPistas, $p + 1, $lose[0]);
        pullPlacePlayer($next, $numPistas, $p, $lose[1]);
    }

    $nuevoNumero = (int) $pull['pull_ronda_actual'] + 1;

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE pull_rondas SET estado = \'cerrada\' WHERE id = :id')->execute(['id' => $rondaId]);
        $db->prepare(
            "INSERT INTO pull_rondas (competicion_id, numero, estado) VALUES (:cid, :n, 'en_juego')"
        )->execute(['cid' => $competicionId, 'n' => $nuevoNumero]);
        $newRondaId = (int) $db->lastInsertId();

        for ($p = 1; $p <= $numPistas; $p++) {
            $players = $next[$p] ?? [];
            if (count($players) >= 4) {
                pullInsertSlots($db, $newRondaId, $p, array_slice($players, 0, 4));
            }
        }

        $db->prepare('UPDATE competiciones SET pull_ronda_actual = :n WHERE id = :id')
            ->execute(['n' => $nuevoNumero, 'id' => $competicionId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $newRondaId;
}

/** Estadísticas admin dashboard Pull */
function pullAdminStats(PDO $db): array
{
    $tipoIn = competicionTipoPullSqlIn();
    $activas = (int) $db->query(
        "SELECT COUNT(*) FROM competiciones WHERE tipo IN ({$tipoIn}) AND estado = 'en_curso'"
    )->fetchColumn();
    $proximas = (int) $db->query(
        "SELECT COUNT(*) FROM competiciones WHERE tipo IN ({$tipoIn}) AND estado = 'abierta'
         AND (fecha_inicio IS NULL OR fecha_inicio >= CURDATE())"
    )->fetchColumn();
    $jugadores = 0;
    if (pullInscripcionesTableExists($db)) {
        $jugadores = (int) $db->query(
            "SELECT COUNT(DISTINCT ip.usuario_id) FROM inscripciones_pull ip
             INNER JOIN competiciones c ON c.id = ip.competicion_id AND c.tipo IN ({$tipoIn})
             WHERE ip.estado IN ('confirmada','pendiente')
             AND c.estado IN ('abierta','en_curso')"
        )->fetchColumn();
    }

    return ['activas' => $activas, 'proximas' => $proximas, 'jugadores' => $jugadores];
}

/** @return list<array<string, mixed>> */
function pullAdminList(PDO $db): array
{
    $tipoIn = competicionTipoPullSqlIn();
    $rows = $db->query(
        "SELECT c.id, c.nombre AS titulo, c.estado, c.fecha_inicio, c.fecha_fin, c.cartel_ruta AS cartel,
                c.pull_num_pistas, c.pull_capacidad_jugadores, c.pull_ronda_actual,
                (SELECT COUNT(*) FROM inscripciones_pull ip WHERE ip.competicion_id = c.id
                 AND ip.estado IN ('confirmada','pendiente')) AS inscritos
         FROM competiciones c
         WHERE c.tipo IN ({$tipoIn})
         ORDER BY COALESCE(c.fecha_inicio, '9999-12-31') DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['estado'] = competicionEstadoToForm((string) $row['estado']);
    }
    unset($row);

    return $rows;
}

function pullEtiquetaPista(int $pistaNumero): string
{
    if ($pistaNumero === 1) {
        return 'Pista 1 (Nivel más alto)';
    }

    return 'Pista ' . $pistaNumero;
}
