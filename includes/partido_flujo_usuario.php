<?php

require_once __DIR__ . '/liga_service.php';
require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/padel_guardrails.php';

function partidoFlujoColumnasDisponibles(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = dbColumnExists($db, 'partidos', 'disputa_confirmada');
    }

    return $ok;
}

function partidoFlujoDisputaConfirmada(PDO $db, array $partido): bool
{
    if (!partidoFlujoColumnasDisponibles($db)) {
        return true;
    }

    return (int) ($partido['disputa_confirmada'] ?? 0) === 1;
}

/** Pareja que introdujo el resultado pendiente de validación. */
function padelParejaIdReportadoraPartido(PDO $db, array $partido): ?int
{
    $repUid = (int) ($partido['reportado_por_usuario_id'] ?? 0);
    if ($repUid <= 0) {
        return null;
    }
    $pid = fetchParejaIdUsuario($db, $repUid);

    return $pid ?: null;
}

/**
 * Embudo UI: futuro | pregunta_disputado | incidencia | formulario_reporte | esperando_validacion | validar_rival
 */
function partidoFlujoEstadoUsuario(PDO $db, array $partido, int $parejaId): string
{
    $estado = (string) ($partido['estado'] ?? 'pendiente');
    if ($estado === 'incidencia') {
        return 'incidencia';
    }
    if ($estado !== 'pendiente') {
        return 'cerrado';
    }
    if (!empty($partido['ganador_pareja_id'])) {
        $repPareja = padelParejaIdReportadoraPartido($db, $partido);

        return ($repPareja !== null && $repPareja === $parejaId) ? 'esperando_validacion' : 'validar_rival';
    }
    if (!ligaPartidoPermiteReporteResultado($partido)) {
        return 'futuro';
    }
    if (!partidoFlujoDisputaConfirmada($db, $partido)) {
        return 'pregunta_disputado';
    }

    return 'formulario_reporte';
}

function padelPartidoPendienteValidacionParaUsuario(PDO $db, array $partido, int $usuarioId): bool
{
    $parejaId = fetchParejaIdUsuario($db, $usuarioId);
    if (!$parejaId) {
        return false;
    }

    return partidoFlujoEstadoUsuario($db, $partido, $parejaId) === 'validar_rival';
}

/** Partidos de liga del grupo en flujo activo (no validados/anulados). */
function ligaFetchPartidosFlujoParejaGrupo(PDO $db, int $parejaId, int $compId, int $grupoId): array
{
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $stmt = $db->prepare(
        "SELECT p.*, ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
         FROM partidos p
         INNER JOIN parejas pa ON pa.id = p.pareja_a_id
         INNER JOIN parejas pb ON pb.id = p.pareja_b_id
         WHERE p.competicion_id = :cid AND p.grupo_id = :gid AND p.fase = 'liga'
           AND p.estado IN ('pendiente', 'incidencia')
           AND (p.pareja_a_id = :pid OR p.pareja_b_id = :pid2)
         ORDER BY COALESCE(p.fecha_programada, p.fecha) ASC, p.jornada ASC, p.id ASC"
    );
    $stmt->execute(['cid' => $compId, 'gid' => $grupoId, 'pid' => $parejaId, 'pid2' => $parejaId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['resultado'] = padelFormatSetsForPartido($db, (int) $row['id']);
    }
    unset($row);

    return $rows;
}

function padelProcesarAccionFlujoPartido(PDO $db, int $usuarioId, int $partidoId, string $accion): bool
{
    if (!usuarioEnPartido($db, $partidoId, $usuarioId)) {
        return false;
    }
    $stmt = $db->prepare('SELECT * FROM partidos WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $partidoId]);
    $partido = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partido) {
        return false;
    }
    $parejaId = fetchParejaIdUsuario($db, $usuarioId);
    if (!$parejaId) {
        return false;
    }

    if ($accion === 'disputado_si') {
        if (partidoFlujoEstadoUsuario($db, $partido, $parejaId) !== 'pregunta_disputado') {
            return false;
        }
        if (!partidoFlujoColumnasDisponibles($db)) {
            return true;
        }
        $db->prepare('UPDATE partidos SET disputa_confirmada = 1 WHERE id = :id')->execute(['id' => $partidoId]);

        return true;
    }

    if ($accion === 'no_disputado') {
        if (partidoFlujoEstadoUsuario($db, $partido, $parejaId) !== 'pregunta_disputado') {
            return false;
        }
        $clearReportadoEn = dbColumnExists($db, 'partidos', 'resultado_reportado_en')
            ? ', resultado_reportado_en = NULL'
            : '';
        $db->prepare(
            "UPDATE partidos SET estado = 'incidencia', incidencia_en = NOW(), disputa_confirmada = 0,
             ganador_pareja_id = NULL, reportado_por_usuario_id = NULL, motivo_rechazo = 'No disputado (reporte usuario)'{$clearReportadoEn}
             WHERE id = :id"
        )->execute(['id' => $partidoId]);
        padelEliminarSetsDePartidos($db, [$partidoId]);

        return true;
    }

    return false;
}

/** @return list<array<string, mixed>> */
function padelFetchPartidosIncidenciaAdmin(PDO $db, ?int $grupoId = null): array
{
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $sql = "SELECT p.*, c.nombre AS liga_nombre, g.nombre AS grupo_nombre,
            ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
            FROM partidos p
            INNER JOIN competiciones c ON c.id = p.competicion_id
            LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
            INNER JOIN parejas pa ON pa.id = p.pareja_a_id
            INNER JOIN parejas pb ON pb.id = p.pareja_b_id
            WHERE p.estado = 'incidencia'";
    if ($grupoId !== null && $grupoId > 0) {
        $sql .= ' AND p.grupo_id = ' . (int) $grupoId;
    }
    $sql .= ' ORDER BY p.incidencia_en DESC, p.id DESC';

    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function padelAdminResolverIncidencia(PDO $db, int $partidoId, string $resolucion, array $opts = []): void
{
    $stmt = $db->prepare('SELECT * FROM partidos WHERE id = :id AND estado = \'incidencia\' LIMIT 1');
    $stmt->execute(['id' => $partidoId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new InvalidArgumentException('Partido en incidencia no encontrado.');
    }

    if ($resolucion === 'anular') {
        $db->prepare("UPDATE partidos SET estado = 'anulado' WHERE id = :id")->execute(['id' => $partidoId]);

        return;
    }

    if ($resolucion === 'reprogramar') {
        $fecha = ligaNormalizarFechaYmd($opts['fecha_programada'] ?? null);
        if ($fecha === null) {
            throw new InvalidArgumentException('Fecha de reprogramación inválida.');
        }
        $db->prepare(
            "UPDATE partidos SET estado = 'pendiente', fecha_programada = :fp, fecha = :fp,
             disputa_confirmada = 0, incidencia_en = NULL, motivo_rechazo = NULL
             WHERE id = :id"
        )->execute(['fp' => $fecha, 'id' => $partidoId]);

        return;
    }

    if ($resolucion === 'forzar_ganador') {
        $ganador = (int) ($opts['ganador_pareja_id'] ?? 0);
        $pa = (int) $p['pareja_a_id'];
        $pb = (int) $p['pareja_b_id'];
        if ($ganador !== $pa && $ganador !== $pb) {
            throw new InvalidArgumentException('Ganador debe ser una de las parejas del partido.');
        }
        require_once __DIR__ . '/match_service.php';
        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE partidos SET ganador_pareja_id = :g, disputa_confirmada = 1, incidencia_en = NULL WHERE id = :id"
            )->execute(['g' => $ganador, 'id' => $partidoId]);
            leagueMatchService($db)->validarPartido($partidoId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return;
    }

    throw new InvalidArgumentException('Resolución no válida.');
}
