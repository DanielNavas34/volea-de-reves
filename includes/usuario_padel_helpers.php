<?php

require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/padel_guardrails.php';

function usuarioEsquemaPadel(PDO $db): bool
{
    return padelCompeticionesSchemaAvailable($db) && !legacyLeagueSchemaAvailable($db);
}

function fetchParejaIdUsuario(PDO $db, int $usuarioId): ?int
{
    $p = fetchParejaActivaDeUsuario($db, $usuarioId);

    return $p ? (int) $p['id'] : null;
}

/** Contexto de liga/grupo de la pareja (inscripción confirmada o primera liga activa). */
function fetchContextoLigaPareja(PDO $db, int $parejaId): ?array
{
    $stmt = $db->prepare(
        "SELECT i.competicion_id, i.grupo_id, c.nombre AS comp_nombre, c.modalidad_liga, g.nombre AS grupo_nombre
         FROM inscripciones i
         INNER JOIN competiciones c ON c.id = i.competicion_id AND c.tipo = 'liga'
         INNER JOIN competicion_grupos g ON g.id = i.grupo_id
         WHERE i.pareja_id = :pid AND i.estado = 'confirmada'
         ORDER BY i.fecha_inscripcion DESC
         LIMIT 1"
    );
    $stmt->execute(['pid' => $parejaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fetchClasificacionPareja(PDO $db, int $parejaId, ?array $contexto): ?array
{
    if (!$contexto) {
        return null;
    }
    require_once __DIR__ . '/standings_service.php';
    $compId = (int) $contexto['competicion_id'];
    $grupoId = isset($contexto['grupo_id']) ? (int) $contexto['grupo_id'] : null;
    $stmt = $db->prepare(
        'SELECT cp.* FROM clasificacion_parejas cp
         WHERE cp.competicion_id = :cid AND cp.pareja_id = :pid AND cp.grupo_id <=> :gid
         LIMIT 1'
    );
    $stmt->execute(['cid' => $compId, 'pid' => $parejaId, 'gid' => $grupoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $pos = leagueStandingsService($db)->posicionParejaEnGrupo($compId, $grupoId, $parejaId);
    $row['posicion'] = $pos ?? 0;

    return $row;
}

function fetchParejasRivalesEnGrupo(PDO $db, int $miParejaId, array $contexto): array
{
    $etiq = sqlParejaEtiqueta('p', $db);
    $stmt = $db->prepare(
        "SELECT DISTINCT p.id, ({$etiq}) AS etiqueta
         FROM inscripciones i
         INNER JOIN parejas p ON p.id = i.pareja_id
         WHERE i.competicion_id = :cid AND i.grupo_id <=> :gid
           AND i.estado = 'confirmada'
           AND p.id != :mid AND p.estado = 'activa'
         ORDER BY etiqueta ASC"
    );
    $stmt->execute([
        'cid' => (int) $contexto['competicion_id'],
        'gid' => $contexto['grupo_id'] ?? null,
        'mid' => $miParejaId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function usuarioEnPartido(PDO $db, int $partidoId, int $usuarioId): bool
{
    $parejaId = fetchParejaIdUsuario($db, $usuarioId);
    if (!$parejaId) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1 FROM partidos WHERE id = :id AND (pareja_a_id = :p OR pareja_b_id = :p2) LIMIT 1'
    );
    $stmt->execute(['id' => $partidoId, 'p' => $parejaId, 'p2' => $parejaId]);

    return (bool) $stmt->fetchColumn();
}

function padelAutoValidarPartidosCaducados(PDO $db): void
{
    require_once __DIR__ . '/match_service.php';
    require_once __DIR__ . '/notificaciones_usuario.php';
    try {
        $ids = leagueMatchService($db)->autoValidarPartidosCaducados();
        foreach ($ids as $partidoId) {
            notificacionesAvisarResultadoAutoValidado($db, $partidoId);
        }
    } catch (Throwable) {
        // cron silencioso
    }
}

function padelProcesarAccionPartidoUsuario(PDO $db, int $usuarioId, int $partidoId, string $accion, ?string $motivo = null): bool
{
    if (!usuarioEnPartido($db, $partidoId, $usuarioId)) {
        return false;
    }
    $parejaId = fetchParejaIdUsuario($db, $usuarioId);
    $stmt = $db->prepare(
        'SELECT id, estado, reportado_por_usuario_id, ganador_pareja_id FROM partidos WHERE id = :id AND estado = \'pendiente\' LIMIT 1'
    );
    $stmt->execute(['id' => $partidoId]);
    $partido = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$partido || empty($partido['ganador_pareja_id'])) {
        return false;
    }
    require_once __DIR__ . '/partido_flujo_usuario.php';
    if (!padelPartidoPendienteValidacionParaUsuario($db, $partido, $usuarioId)) {
        return false;
    }

    require_once __DIR__ . '/match_service.php';
    $matches = leagueMatchService($db);
    if ($accion === 'aceptar') {
        try {
            $matches->aceptarResultadoRival($partidoId, $usuarioId);
            require_once __DIR__ . '/notificaciones_usuario.php';
            if ($parejaId) {
                notificacionesAvisarResultadoValidado($db, $partidoId, $parejaId);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
    if ($accion === 'rechazar') {
        try {
            $matches->rechazarResultadoRival($partidoId, $usuarioId, $motivo);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    return false;
}

function fetchPartidosParejaUsuario(PDO $db, int $parejaId, ?string $estado = null, ?int $limit = null): array
{
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $sql = "SELECT p.*, c.nombre AS comp_nombre, g.nombre AS grupo_nombre,
            ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
            FROM partidos p
            INNER JOIN competiciones c ON c.id = p.competicion_id
            LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
            INNER JOIN parejas pa ON pa.id = p.pareja_a_id
            INNER JOIN parejas pb ON pb.id = p.pareja_b_id
            WHERE (p.pareja_a_id = :pid OR p.pareja_b_id = :pid2)";
    if ($estado !== null) {
        $sql .= ' AND p.estado = ' . $db->quote($estado);
    }
    $sql .= ' ORDER BY p.fecha DESC, p.id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute(['pid' => $parejaId, 'pid2' => $parejaId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['resultado'] = padelFormatSetsForPartido($db, (int) $row['id']);
    }
    unset($row);

    return $rows;
}

function fetchInvitacionesParejaPendientes(PDO $db, int $usuarioId): array
{
    $stmt = $db->prepare(
        "SELECT pi.id, pi.pareja_id, pi.expira_en, u.nombre, u.apellidos
         FROM pareja_invitaciones pi
         INNER JOIN usuarios u ON u.id = pi.invitador_usuario_id
         WHERE pi.invitado_usuario_id = :uid AND pi.estado = 'pendiente' AND pi.expira_en > NOW()
         ORDER BY pi.expira_en ASC"
    );
    $stmt->execute(['uid' => $usuarioId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
