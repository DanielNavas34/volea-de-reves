<?php

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/legacy_schema.php';

/** Crea competición de liga vacía (sin inscripciones). Devuelve id de competición. */
function ligaCrearVacia(PDO $db, string $nombre, string $modalidad = 'general'): int
{
    $modalidad = $modalidad === 'grupos' ? 'grupos' : 'general';
    $nombre = trim($nombre);
    if ($nombre === '') {
        throw new InvalidArgumentException('Nombre de liga obligatorio.');
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO competiciones (tipo, nombre, descripcion, estado, modalidad_liga)
             VALUES ('liga', :nombre, '', 'borrador', :modalidad)"
        );
        $stmt->execute(['nombre' => $nombre, 'modalidad' => $modalidad]);
        $compId = (int) $db->lastInsertId();
        if ($modalidad === 'general') {
            $db->prepare(
                "INSERT INTO competicion_grupos (competicion_id, nombre, orden) VALUES (:cid, 'General', 0)"
            )->execute(['cid' => $compId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $compId;
}

function ligaContarInscripcionesCompeticion(PDO $db, int $competicionId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM inscripciones WHERE competicion_id = :cid');
    $stmt->execute(['cid' => $competicionId]);

    return (int) $stmt->fetchColumn();
}

function ligaPublicarCompeticion(PDO $db, int $competicionId, string $estado = 'abierta'): bool
{
    if (!in_array($estado, ['abierta', 'en_curso', 'borrador', 'finalizada', 'cancelada'], true)) {
        $estado = 'abierta';
    }
    $stmt = $db->prepare(
        "UPDATE competiciones SET estado = :e WHERE id = :id AND tipo = 'liga'"
    );
    $stmt->execute(['e' => $estado, 'id' => $competicionId]);

    return $stmt->rowCount() > 0;
}

/** Grupos de ligas abiertas a inscripción de parejas. */
function ligaFetchOpcionesInscripcionUsuario(PDO $db): array
{
    return $db->query(
        "SELECT c.id AS competicion_id, c.nombre AS liga_nombre, c.descripcion, c.modalidad_liga,
                g.id AS grupo_id, g.nombre AS grupo_nombre
         FROM competiciones c
         INNER JOIN competicion_grupos g ON g.competicion_id = c.id
         WHERE c.tipo = 'liga' AND c.estado IN ('abierta', 'en_curso')
         ORDER BY c.nombre ASC, g.orden ASC, g.nombre ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function ligaFetchInscripcionParejaGrupo(PDO $db, int $parejaId, int $grupoId): ?array
{
    $stmt = $db->prepare(
        "SELECT id, estado FROM inscripciones
         WHERE pareja_id = :pid AND grupo_id = :gid LIMIT 1"
    );
    $stmt->execute(['pid' => $parejaId, 'gid' => $grupoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Solicitud de inscripción desde el panel usuario (estado pendiente hasta confirmación admin). */
function ligaSolicitarInscripcionUsuario(PDO $db, int $parejaId, int $grupoId): void
{
    $grupo = ligaFetchGrupo($db, $grupoId);
    if (!$grupo) {
        throw new InvalidArgumentException('Grupo no encontrado.');
    }
    if (!in_array($grupo['estado'], ['abierta', 'en_curso'], true)) {
        throw new InvalidArgumentException('Esta liga no admite inscripciones ahora.');
    }
    $compId = (int) $grupo['competicion_id'];
    $existente = ligaFetchInscripcionParejaGrupo($db, $parejaId, $grupoId);
    if ($existente) {
        throw new InvalidArgumentException('Ya tienes una solicitud o inscripción en este grupo.');
    }
    ligaInscribirPareja($db, $compId, $grupoId, $parejaId, 'pendiente');
}

function ligaFetchGrupo(PDO $db, int $grupoId): ?array
{
    $stmt = $db->prepare(
        "SELECT g.id, g.nombre AS grupo_nombre, g.competicion_id, c.nombre AS liga_nombre, c.estado, c.fecha_inicio
         FROM competicion_grupos g
         INNER JOIN competiciones c ON c.id = g.competicion_id AND c.tipo = 'liga'
         WHERE g.id = :id"
    );
    $stmt->execute(['id' => $grupoId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ligaInscripcionesGrupo(PDO $db, int $grupoId): array
{
    $etiq = sqlParejaEtiqueta('p', $db);
    $stmt = $db->prepare(
        "SELECT i.id, i.estado, i.fecha_inscripcion, i.pareja_id, ({$etiq}) AS pareja_nombre
         FROM inscripciones i
         INNER JOIN parejas p ON p.id = i.pareja_id
         WHERE i.grupo_id = :gid
         ORDER BY i.fecha_inscripcion DESC"
    );
    $stmt->execute(['gid' => $grupoId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ligaInscribirPareja(PDO $db, int $competicionId, int $grupoId, int $parejaId, string $estado = 'confirmada'): void
{
    if (!in_array($estado, ['pendiente', 'confirmada', 'rechazada', 'baja'], true)) {
        $estado = 'confirmada';
    }
    $db->prepare(
        "INSERT INTO inscripciones (competicion_id, pareja_id, grupo_id, estado)
         VALUES (:cid, :pid, :gid, :estado)"
    )->execute(['cid' => $competicionId, 'pid' => $parejaId, 'gid' => $grupoId, 'estado' => $estado]);

    if ($estado === 'confirmada') {
        padelEnsureClasificacionRow($db, $competicionId, $grupoId, $parejaId);
    }
}

/** Partido de liga pendiente sin resultado entre dos parejas (cualquier orden). */
function ligaFindOpenFixture(PDO $db, int $competicionId, int $grupoId, int $parejaA, int $parejaB): ?int
{
    $stmt = $db->prepare(
        "SELECT id FROM partidos
         WHERE competicion_id = :cid AND grupo_id = :gid AND fase = 'liga' AND estado = 'pendiente'
           AND ganador_pareja_id IS NULL
           AND ((pareja_a_id = :a AND pareja_b_id = :b) OR (pareja_a_id = :b2 AND pareja_b_id = :a2))
         LIMIT 1"
    );
    $stmt->execute([
        'cid' => $competicionId,
        'gid' => $grupoId,
        'a' => $parejaA,
        'b' => $parejaB,
        'a2' => $parejaA,
        'b2' => $parejaB,
    ]);
    $id = $stmt->fetchColumn();

    return $id ? (int) $id : null;
}

function ligaPartidoFixtureExists(PDO $db, int $competicionId, int $grupoId, int $parejaA, int $parejaB): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM partidos
         WHERE competicion_id = :cid AND grupo_id = :gid AND fase = 'liga'
           AND ((pareja_a_id = :a AND pareja_b_id = :b) OR (pareja_a_id = :b2 AND pareja_b_id = :a2))
         LIMIT 1"
    );
    $stmt->execute([
        'cid' => $competicionId,
        'gid' => $grupoId,
        'a' => $parejaA,
        'b' => $parejaB,
        'a2' => $parejaA,
        'b2' => $parejaB,
    ]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Emparejamientos round-robin por jornada (método del círculo).
 *
 * @param list<int> $parejaIds
 * @return list<array{jornada:int, pairs:list<array{0:int,1:int}>}>
 */
function ligaRoundRobinJornadas(array $parejaIds): array
{
    $teams = array_values(array_map('intval', $parejaIds));
    $n = count($teams);
    if ($n < 2) {
        return [];
    }
    if ($n % 2 === 1) {
        $teams[] = 0;
        $n++;
    }
    $rounds = $n - 1;
    $half = (int) ($n / 2);
    $fixed = $teams[0];
    $rotating = array_slice($teams, 1);
    $out = [];
    for ($r = 0; $r < $rounds; $r++) {
        $roundTeams = array_merge([$fixed], $rotating);
        $pairs = [];
        for ($i = 0; $i < $half; $i++) {
            $home = (int) $roundTeams[$i];
            $away = (int) $roundTeams[$n - 1 - $i];
            if ($home === 0 || $away === 0) {
                continue;
            }
            $pairs[] = [$home, $away];
        }
        $out[] = ['jornada' => $r + 1, 'pairs' => $pairs];
        $last = array_pop($rotating);
        if ($last !== null) {
            array_unshift($rotating, $last);
        }
    }

    return $out;
}

function ligaNormalizarFechaYmd(?string $fecha): ?string
{
    if ($fecha === null) {
        return null;
    }
    $fecha = trim($fecha);
    if ($fecha === '' || $fecha === '0000-00-00') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        return null;
    }

    return $fecha;
}

function ligaPartidoFechaProgramadaEfectiva(array $partido): string
{
    $fp = $partido['fecha_programada'] ?? null;
    if ($fp !== null && $fp !== '') {
        return (string) $fp;
    }

    return (string) ($partido['fecha'] ?? date('Y-m-d'));
}

/** ¿Puede la pareja reportar resultado hoy (fecha programada hoy o pasada)? */
function ligaPartidoPermiteReporteResultado(?array $partido): bool
{
    if ($partido === null) {
        return true;
    }
    $fp = $partido['fecha_programada'] ?? null;
    if ($fp === null || $fp === '') {
        return true;
    }

    return $fp <= date('Y-m-d');
}

/** @return list<int> */
/** Partidos de liga sin resultado reportado para una pareja en su grupo. */
function ligaFetchFixturesAbiertosParejaGrupo(PDO $db, int $parejaId, int $compId, int $grupoId): array
{
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $stmt = $db->prepare(
        "SELECT p.*, ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
         FROM partidos p
         INNER JOIN parejas pa ON pa.id = p.pareja_a_id
         INNER JOIN parejas pb ON pb.id = p.pareja_b_id
         WHERE p.competicion_id = :cid AND p.grupo_id = :gid AND p.fase = 'liga'
           AND p.estado = 'pendiente' AND p.ganador_pareja_id IS NULL
           AND (p.pareja_a_id = :pid OR p.pareja_b_id = :pid2)
         ORDER BY COALESCE(p.fecha_programada, p.fecha) ASC, p.jornada ASC, p.id ASC"
    );
    $stmt->execute(['cid' => $compId, 'gid' => $grupoId, 'pid' => $parejaId, 'pid2' => $parejaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ligaUsuariosActivosPareja(PDO $db, int $parejaId): array
{
    $stmt = $db->prepare(
        'SELECT usuario_id FROM pareja_miembros WHERE pareja_id = :pid AND activo = 1'
    );
    $stmt->execute(['pid' => $parejaId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Round robin: crea partidos pendientes agrupados por jornada con fecha_programada.
 *
 * @return array{created:int, skipped:int, jornadas:int, message:string}
 */
function ligaGenerarCalendarioRoundRobin(
    PDO $db,
    int $grupoId,
    ?string $fechaInicio = null,
    int $diasEntreJornadas = 7
): array {
    $grupo = ligaFetchGrupo($db, $grupoId);
    if (!$grupo) {
        return ['created' => 0, 'skipped' => 0, 'jornadas' => 0, 'message' => 'Grupo no encontrado.'];
    }

    $compId = (int) $grupo['competicion_id'];
    $stmt = $db->prepare(
        "SELECT pareja_id FROM inscripciones
         WHERE competicion_id = :cid AND grupo_id = :gid AND estado = 'confirmada'
         ORDER BY pareja_id ASC"
    );
    $stmt->execute(['cid' => $compId, 'gid' => $grupoId]);
    $parejas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (count($parejas) < 2) {
        return ['created' => 0, 'skipped' => 0, 'jornadas' => 0, 'message' => 'Se necesitan al menos 2 parejas confirmadas.'];
    }

    $fechaInicio = ligaNormalizarFechaYmd($fechaInicio)
        ?? ligaNormalizarFechaYmd(isset($grupo['fecha_inicio']) ? (string) $grupo['fecha_inicio'] : null)
        ?? date('Y-m-d');
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fechaInicio);
    if (!$dt || $dt->format('Y-m-d') !== $fechaInicio) {
        throw new InvalidArgumentException('Fecha de inicio inválida (use AAAA-MM-DD).');
    }
    $diasEntreJornadas = max(1, min(365, $diasEntreJornadas));

    $jornadasPlan = ligaRoundRobinJornadas($parejas);
    $created = 0;
    $skipped = 0;
    $tieneJornada = dbColumnExists($db, 'partidos', 'jornada');
    $tieneFechaProg = dbColumnExists($db, 'partidos', 'fecha_programada');

    $db->beginTransaction();
    try {
        foreach ($jornadasPlan as $bloque) {
            $numJornada = (int) $bloque['jornada'];
            $fechaJornada = $dt->modify('+' . ($numJornada - 1) * $diasEntreJornadas . ' days')->format('Y-m-d');
            foreach ($bloque['pairs'] as [$pa, $pb]) {
                if ($pa > $pb) {
                    [$pa, $pb] = [$pb, $pa];
                }
                if (ligaPartidoFixtureExists($db, $compId, $grupoId, $pa, $pb)) {
                    $skipped++;
                    continue;
                }
                $cols = ['competicion_id', 'grupo_id', 'fase', 'pareja_a_id', 'pareja_b_id', 'fecha', 'hora', 'estado'];
                $vals = [':cid', ':gid', "'liga'", ':pa', ':pb', ':fecha', "'12:00:00'", "'pendiente'"];
                $params = [
                    'cid' => $compId,
                    'gid' => $grupoId,
                    'pa' => $pa,
                    'pb' => $pb,
                    'fecha' => $fechaJornada,
                ];
                if ($tieneJornada) {
                    $cols[] = 'jornada';
                    $cols[] = 'ronda';
                    $vals[] = ':jornada';
                    $vals[] = ':ronda';
                    $params['jornada'] = $numJornada;
                    $params['ronda'] = $numJornada;
                }
                if ($tieneFechaProg) {
                    $cols[] = 'fecha_programada';
                    $vals[] = ':fprog';
                    $params['fprog'] = $fechaJornada;
                }
                $sql = 'INSERT INTO partidos (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
                $db->prepare($sql)->execute($params);
                $created++;
            }
        }
        $db->prepare(
            "UPDATE competiciones SET estado = 'en_curso', fecha_inicio = :fi
             WHERE id = :id AND tipo = 'liga' AND estado IN ('borrador', 'abierta')"
        )->execute(['id' => $compId, 'fi' => $fechaInicio]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $nj = count($jornadasPlan);

    return [
        'created' => $created,
        'skipped' => $skipped,
        'jornadas' => $nj,
        'message' => "Calendario generado: {$created} partido(s) en {$nj} jornada(s), {$skipped} ya existían. Inicio {$fechaInicio}, cada {$diasEntreJornadas} día(s).",
    ];
}
