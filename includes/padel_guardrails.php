<?php

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/liga_service.php';

/** Inscripción activa en competición abierta o en curso (liga o torneo). */
function parejaInscritaEnCompeticionViva(PDO $db, int $parejaId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM inscripciones i
         INNER JOIN competiciones c ON c.id = i.competicion_id
         WHERE i.pareja_id = :pid
           AND i.estado IN ('pendiente', 'confirmada')
           AND c.estado IN ('abierta', 'en_curso')
         LIMIT 1"
    );
    $stmt->execute(['pid' => $parejaId]);

    return (bool) $stmt->fetchColumn();
}

/** Motivo si no se puede disolver; null si está permitido. */
function parejaMotivoBloqueoDisolucion(PDO $db, int $parejaId): ?string
{
    if ($parejaId <= 0) {
        return 'Pareja no válida.';
    }
    if (parejaInscritaEnCompeticionViva($db, $parejaId)) {
        return 'No se puede disolver: la pareja está inscrita en una liga o torneo abierto o en curso.';
    }

    return null;
}

function usuarioTieneInvitacionPendienteComoInvitado(PDO $db, int $usuarioId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM pareja_invitaciones
         WHERE invitado_usuario_id = :uid AND estado = 'pendiente' AND expira_en > NOW()
         LIMIT 1"
    );
    $stmt->execute(['uid' => $usuarioId]);

    return (bool) $stmt->fetchColumn();
}

/** Motivo si no puede enviar invitación; null si OK. */
function parejaMotivoBloqueoEnviarInvitacion(PDO $db, int $invitadorId, int $invitadoId): ?string
{
    if ($invitadorId === $invitadoId) {
        return 'No puedes invitarte a ti mismo.';
    }
    if (usuarioTieneParejaActiva($db, $invitadorId)) {
        return 'Ya tienes una pareja activa o una invitación en curso.';
    }
    if (usuarioTieneParejaActiva($db, $invitadoId)) {
        return 'Ese jugador ya tiene pareja activa.';
    }
    if (usuarioTieneInvitacionPendienteComoInvitado($db, $invitadoId)) {
        return 'Ese jugador ya tiene una invitación de pareja pendiente.';
    }

    return null;
}

/** Motivo si no puede aceptar invitación; null si OK. */
function parejaMotivoBloqueoAceptarInvitacion(PDO $db, int $invitadoId, int $parejaIdInvitacion, int $invitadorId): ?string
{
    if (usuarioTieneParejaActiva($db, $invitadoId, $parejaIdInvitacion)) {
        return 'Ya perteneces a otra pareja.';
    }
    if (usuarioTieneParejaActiva($db, $invitadorId, $parejaIdInvitacion)) {
        return 'El jugador que te invitó ya no está disponible (tiene otra pareja).';
    }

    return null;
}

/**
 * Código de error de reporte (query string) o null si puede continuar.
 *
 * @param array<string, mixed>|null $partido
 */
function padelCodigoErrorReportePartido(?array $partido): ?string
{
    if ($partido === null) {
        return null;
    }
    $estado = (string) ($partido['estado'] ?? '');
    if ($estado === 'validado') {
        return 'error_validado';
    }
    if ($estado === 'incidencia') {
        return 'error_incidencia';
    }
    if ($estado === 'pendiente' && !empty($partido['ganador_pareja_id'])) {
        return 'error_ya_reportado';
    }

    return null;
}

function padelFindPartidoCruceLiga(
    PDO $db,
    int $competicionId,
    int $grupoId,
    int $parejaA,
    int $parejaB
): ?array {
    $stmt = $db->prepare(
        "SELECT id, estado, ganador_pareja_id, reportado_por_usuario_id, fecha_programada, fecha
         FROM partidos
         WHERE competicion_id = :cid AND grupo_id = :gid AND fase = 'liga'
           AND ((pareja_a_id = :a AND pareja_b_id = :b) OR (pareja_a_id = :b2 AND pareja_b_id = :a2))
         ORDER BY id DESC
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** Valida reporte antes de abrir transacción (fixture abierto o cruce sin bloqueo). */
function padelValidarReporteResultadoUsuario(
    PDO $db,
    int $competicionId,
    int $grupoId,
    int $parejaId,
    int $rivalId,
    ?int $fixtureId
): ?string {
    if ($fixtureId !== null) {
        $stmt = $db->prepare(
            'SELECT id, estado, ganador_pareja_id, fecha_programada, fecha, disputa_confirmada FROM partidos WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $fixtureId]);
        $fix = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fix) {
            return 'error';
        }
        $err = padelCodigoErrorReportePartido($fix);
        if ($err !== null) {
            return $err;
        }
        if (!ligaPartidoPermiteReporteResultado($fix)) {
            return 'error_fecha_futura';
        }
        if (dbColumnExists($db, 'partidos', 'disputa_confirmada') && (int) ($fix['disputa_confirmada'] ?? 0) !== 1) {
            return 'error_disputa_pendiente';
        }

        return null;
    }

    $cruce = padelFindPartidoCruceLiga($db, $competicionId, $grupoId, $parejaId, $rivalId);
    $err = padelCodigoErrorReportePartido($cruce);
    if ($err !== null) {
        return $err;
    }
    if ($cruce !== null && !ligaPartidoPermiteReporteResultado($cruce)) {
        return 'error_fecha_futura';
    }

    return null;
}

function padelEliminarSetsDePartidos(PDO $db, array $partidoIds): void
{
    if ($partidoIds === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($partidoIds), '?'));
    $db->prepare("DELETE FROM partido_sets WHERE partido_id IN ({$placeholders})")->execute($partidoIds);
}

function padelEliminarSnapshotsDePartidos(PDO $db, array $partidoIds): void
{
    if ($partidoIds === []) {
        return;
    }
    static $tablaExiste = null;
    if ($tablaExiste === null) {
        $tablaExiste = (bool) $db->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'partido_integrantes_snapshot' LIMIT 1"
        )->fetchColumn();
    }
    if (!$tablaExiste) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($partidoIds), '?'));
    $db->prepare("DELETE FROM partido_integrantes_snapshot WHERE partido_id IN ({$placeholders})")
        ->execute($partidoIds);
}

/** Elimina un grupo de liga y datos dependientes (partidos, inscripciones, clasificación). */
function competicionEliminarGrupoCompleto(PDO $db, int $grupoId): void
{
    $stmt = $db->prepare('SELECT id FROM partidos WHERE grupo_id = :gid');
    $stmt->execute(['gid' => $grupoId]);
    $partidoIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $db->prepare('UPDATE partidos SET partido_siguiente_id = NULL WHERE grupo_id = :gid')->execute(['gid' => $grupoId]);
    padelEliminarSetsDePartidos($db, $partidoIds);
    padelEliminarSnapshotsDePartidos($db, $partidoIds);
    $db->prepare('DELETE FROM partidos WHERE grupo_id = :gid')->execute(['gid' => $grupoId]);
    $db->prepare('DELETE FROM clasificacion_parejas WHERE grupo_id = :gid')->execute(['gid' => $grupoId]);
    $db->prepare('DELETE FROM inscripciones WHERE grupo_id = :gid')->execute(['gid' => $grupoId]);
    $db->prepare('DELETE FROM competicion_grupos WHERE id = :gid')->execute(['gid' => $grupoId]);
}

/** Elimina una liga (competición) y todos los datos vinculados. */
function competicionEliminarLigaCompleta(PDO $db, int $competicionId): void
{
    $stmt = $db->prepare('SELECT id, tipo FROM competiciones WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $competicionId]);
    $comp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comp || $comp['tipo'] !== 'liga') {
        throw new InvalidArgumentException('Solo se pueden eliminar competiciones de tipo liga.');
    }

    $stmtP = $db->prepare('SELECT id FROM partidos WHERE competicion_id = :cid');
    $stmtP->execute(['cid' => $competicionId]);
    $partidoIds = array_map('intval', $stmtP->fetchAll(PDO::FETCH_COLUMN));

    $db->prepare('UPDATE partidos SET partido_siguiente_id = NULL WHERE competicion_id = :cid')
        ->execute(['cid' => $competicionId]);
    padelEliminarSetsDePartidos($db, $partidoIds);
    padelEliminarSnapshotsDePartidos($db, $partidoIds);
    $db->prepare('DELETE FROM partidos WHERE competicion_id = :cid')->execute(['cid' => $competicionId]);
    $db->prepare('DELETE FROM clasificacion_parejas WHERE competicion_id = :cid')->execute(['cid' => $competicionId]);
    $db->prepare('DELETE FROM inscripciones WHERE competicion_id = :cid')->execute(['cid' => $competicionId]);
    $db->prepare('DELETE FROM competicion_grupos WHERE competicion_id = :cid')->execute(['cid' => $competicionId]);
    $db->prepare('DELETE FROM competiciones WHERE id = :cid')->execute(['cid' => $competicionId]);
}
