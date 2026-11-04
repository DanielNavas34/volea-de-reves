<?php

/**
 * Borrado en cascada y utilidades de control total admin (pádel).
 */

require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/padel_guardrails.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/parejas_helpers.php';

function adminEliminarNotificacionesPartido(PDO $db, int $partidoId): void
{
    if (!dbColumnExists($db, 'notificaciones_usuarios', 'partido_id')) {
        return;
    }
    $db->prepare('DELETE FROM notificaciones_usuarios WHERE partido_id = :id')->execute(['id' => $partidoId]);
}

/** @return list<int> */
function adminPartidoIdsDePareja(PDO $db, int $parejaId): array
{
    $stmt = $db->prepare(
        'SELECT id FROM partidos WHERE pareja_a_id = :p OR pareja_b_id = :p2'
    );
    $stmt->execute(['p' => $parejaId, 'p2' => $parejaId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function adminEliminarPartidosPorIds(PDO $db, array $partidoIds): void
{
    require_once __DIR__ . '/match_service.php';
    $partidoIds = array_values(array_filter(array_map('intval', $partidoIds)));
    if ($partidoIds === []) {
        return;
    }
    $matches = leagueMatchService($db);
    foreach ($partidoIds as $pid) {
        try {
            $matches->eliminarPartido($pid);
        } catch (Throwable) {
            adminEliminarNotificacionesPartido($db, $pid);
            padelEliminarSetsDePartidos($db, [$pid]);
            padelEliminarSnapshotsDePartidos($db, [$pid]);
            $db->prepare('DELETE FROM partidos WHERE id = :id')->execute(['id' => $pid]);
        }
    }
}

function adminEliminarParejaCompletaCore(PDO $db, int $parejaId): void
{
    if ($parejaId <= 0) {
        throw new InvalidArgumentException('Pareja no válida.');
    }
    $stEx = $db->prepare('SELECT COUNT(*) FROM parejas WHERE id = :id');
    $stEx->execute(['id' => $parejaId]);
    if ((int) $stEx->fetchColumn() === 0) {
        throw new InvalidArgumentException('Pareja no encontrada.');
    }

    $partidoIds = adminPartidoIdsDePareja($db, $parejaId);
    if ($partidoIds !== []) {
        $ph = implode(',', array_fill(0, count($partidoIds), '?'));
        $db->prepare("UPDATE partidos SET partido_siguiente_id = NULL WHERE id IN ({$ph}) OR partido_siguiente_id IN ({$ph})")
            ->execute(array_merge($partidoIds, $partidoIds));
    }
    adminEliminarPartidosPorIds($db, $partidoIds);

    $db->prepare('DELETE FROM inscripciones WHERE pareja_id = :id')->execute(['id' => $parejaId]);
    $db->prepare('DELETE FROM clasificacion_parejas WHERE pareja_id = :id')->execute(['id' => $parejaId]);
    $db->prepare('DELETE FROM pareja_invitaciones WHERE pareja_id = :id')->execute(['id' => $parejaId]);
    $db->prepare('DELETE FROM pareja_miembros WHERE pareja_id = :id')->execute(['id' => $parejaId]);
    $db->prepare('DELETE FROM parejas WHERE id = :id')->execute(['id' => $parejaId]);
}

/** Elimina pareja y datos ligados (partidos, inscripciones, clasificación). Admin: sin bloqueos de liga. */
function adminEliminarParejaCompleta(PDO $db, int $parejaId): void
{
    $db->beginTransaction();
    try {
        adminEliminarParejaCompletaCore($db, $parejaId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Elimina jugador (no admin) y desvincula BD. */
function adminEliminarUsuarioCompleto(PDO $db, int $usuarioId): void
{
    if ($usuarioId <= 0) {
        throw new InvalidArgumentException('Usuario no válido.');
    }
    $row = $db->prepare('SELECT id, rol, email FROM usuarios WHERE id = :id LIMIT 1');
    $row->execute(['id' => $usuarioId]);
    $user = $row->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new InvalidArgumentException('Usuario no encontrado.');
    }
    if (($user['rol'] ?? '') === 'admin') {
        throw new InvalidArgumentException('No se puede eliminar un administrador.');
    }

    $db->beginTransaction();
    try {
        $parejaIds = $db->prepare('SELECT DISTINCT pareja_id FROM pareja_miembros WHERE usuario_id = :uid');
        $parejaIds->execute(['uid' => $usuarioId]);
        foreach ($parejaIds->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            adminEliminarParejaCompletaCore($db, (int) $pid);
        }

        $db->prepare('DELETE FROM pull_pista_slots WHERE usuario_id = :uid')->execute(['uid' => $usuarioId]);
        $db->prepare('DELETE FROM inscripciones_pull WHERE usuario_id = :uid')->execute(['uid' => $usuarioId]);
        $db->prepare(
            'DELETE FROM pareja_invitaciones WHERE invitador_usuario_id = :uid OR invitado_usuario_id = :uid2'
        )->execute(['uid' => $usuarioId, 'uid2' => $usuarioId]);
        $db->prepare('DELETE FROM partido_integrantes_snapshot WHERE usuario_id = :uid')->execute(['uid' => $usuarioId]);
        if (dbColumnExists($db, 'notificaciones_usuarios', 'usuario_id')) {
            $db->prepare('DELETE FROM notificaciones_usuarios WHERE usuario_id = :uid')->execute(['uid' => $usuarioId]);
        }
        $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId > 0) {
            $db->prepare('UPDATE parejas SET creado_por_usuario_id = :a WHERE creado_por_usuario_id = :uid')
                ->execute(['a' => $adminId, 'uid' => $usuarioId]);
        }
        $email = (string) ($user['email'] ?? '');
        if ($email !== '') {
            $db->prepare('DELETE FROM login_attempts WHERE email = :e')->execute(['e' => $email]);
        }
        $db->prepare('DELETE FROM usuarios WHERE id = :id AND rol = \'usuario\'')->execute(['id' => $usuarioId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function adminParejaTieneNotaAdmin(PDO $db): bool
{
    static $c = null;
    if ($c === null) {
        $c = dbColumnExists($db, 'parejas', 'nota_admin');
    }

    return $c;
}

function adminPartidoTieneNotaAdmin(PDO $db): bool
{
    static $c = null;
    if ($c === null) {
        $c = dbColumnExists($db, 'partidos', 'nota_admin');
    }

    return $c;
}
