<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/competition_service.php';
require_once __DIR__ . '/../includes/pair_service.php';
require_once __DIR__ . '/../includes/padel_guardrails.php';

function compTestCrearUsuario(PDO $db, string $suffix): int
{
    $email = 'comp_test_' . $suffix . '@test.local';
    $db->prepare(
        "INSERT INTO usuarios (nombre, apellidos, email, telefono, password, rol, email_verificado, tipo_perfil)
         VALUES ('T', :s, :email, '', 'hash', 'usuario', 1, 'liga')"
    )->execute(['s' => $suffix, 'email' => $email]);

    return (int) $db->lastInsertId();
}

function compTestLimpiarLiga(PDO $db, int $compId): void
{
    if ($compId <= 0) {
        return;
    }
    $stPart = $db->prepare('SELECT id FROM partidos WHERE competicion_id = :cid');
    $stPart->execute(['cid' => $compId]);
    $partidoIds = array_map('intval', $stPart->fetchAll(PDO::FETCH_COLUMN));
    require_once __DIR__ . '/../includes/padel_guardrails.php';
    padelEliminarSetsDePartidos($db, $partidoIds);
    padelEliminarSnapshotsDePartidos($db, $partidoIds);
    $db->prepare('DELETE FROM partidos WHERE competicion_id = :cid')->execute(['cid' => $compId]);
    $db->prepare('DELETE FROM clasificacion_parejas WHERE competicion_id = :cid')->execute(['cid' => $compId]);
    $db->prepare('DELETE FROM inscripciones WHERE competicion_id = :cid')->execute(['cid' => $compId]);
    $db->prepare('DELETE FROM competicion_grupos WHERE competicion_id = :cid')->execute(['cid' => $compId]);
    $db->prepare("DELETE FROM competiciones WHERE id = :cid AND tipo = 'liga'")->execute(['cid' => $compId]);
}

function compTestLimpiarUsuarios(PDO $db, array $userIds): void
{
    $parejaIds = [];
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        $stP = $db->prepare('SELECT pareja_id FROM pareja_miembros WHERE usuario_id = :u');
        $stP->execute(['u' => $uid]);
        foreach ($stP->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $parejaIds[(int) $pid] = true;
        }
    }
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM pareja_invitaciones WHERE invitador_usuario_id = :u OR invitado_usuario_id = :u2')
            ->execute(['u' => $uid, 'u2' => $uid]);
        $db->prepare('DELETE FROM pareja_miembros WHERE usuario_id = :u')->execute(['u' => $uid]);
        $db->prepare('DELETE FROM parejas WHERE creado_por_usuario_id = :u')->execute(['u' => $uid]);
    }
    foreach (array_keys($parejaIds) as $pid) {
        $db->prepare('DELETE FROM pareja_miembros WHERE pareja_id = :p')->execute(['p' => $pid]);
        $db->prepare('DELETE FROM parejas WHERE id = :p')->execute(['p' => $pid]);
    }
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM usuarios WHERE id = :id AND email LIKE \'comp_test_%\'')->execute(['id' => $uid]);
    }
}

function test_competition_solicitud_duplicada(): bool
{
    $db = getDBConnection();
    $svc = leagueCompetitionService($db);
    $pairs = pairService($db);
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $sfx = bin2hex(random_bytes(3));
    $u1 = compTestCrearUsuario($db, $sfx . '_a1');
    $u2 = compTestCrearUsuario($db, $sfx . '_a2');
    $u3 = compTestCrearUsuario($db, $sfx . '_a3');
    $u4 = compTestCrearUsuario($db, $sfx . '_a4');

    try {
        $compId = $svc->crearLiga('Test Comp Dup ' . bin2hex(random_bytes(2)), 'general');
        $svc->publicarLiga($compId, 'abierta');
        $grupoId = (int) $db->query(
            "SELECT id FROM competicion_grupos WHERE competicion_id = {$compId} LIMIT 1"
        )->fetchColumn();
        $p1 = $pairs->crearParejaAdmin($u1, $u2, null, $adminId ?: $u1);
        $p2 = $pairs->crearParejaAdmin($u3, $u4, null, $adminId ?: $u1);

        $svc->solicitarInscripcion($p1, $grupoId);
        try {
            $svc->solicitarInscripcion($p1, $grupoId);
            compTestLimpiarLiga($db, $compId);
            compTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
            echo "[FAIL] competition_solicitud_duplicada\n";
            return false;
        } catch (InvalidArgumentException) {
            // ok
        }

        compTestLimpiarLiga($db, $compId);
        compTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
        echo "[OK] competition_solicitud_duplicada\n";
        return true;
    } catch (Throwable $e) {
        compTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
        echo "[FAIL] competition_solicitud_duplicada: " . $e->getMessage() . "\n";
        return false;
    }
}

function test_competition_confirmada_clasificacion(): bool
{
    $db = getDBConnection();
    $svc = leagueCompetitionService($db);
    $pairs = pairService($db);
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $sfx = bin2hex(random_bytes(3));
    $u1 = compTestCrearUsuario($db, $sfx . '_b1');
    $u2 = compTestCrearUsuario($db, $sfx . '_b2');
    $u3 = compTestCrearUsuario($db, $sfx . '_b3');
    $u4 = compTestCrearUsuario($db, $sfx . '_b4');

    try {
        $compId = $svc->crearLiga('Test Comp Clasif ' . bin2hex(random_bytes(2)), 'general');
        $svc->publicarLiga($compId, 'abierta');
        $grupoId = (int) $db->query(
            "SELECT id FROM competicion_grupos WHERE competicion_id = {$compId} LIMIT 1"
        )->fetchColumn();
        $p1 = $pairs->crearParejaAdmin($u1, $u2, null, $adminId ?: $u1);
        $p2 = $pairs->crearParejaAdmin($u3, $u4, null, $adminId ?: $u1);

        $svc->solicitarInscripcion($p1, $grupoId);
        $ins = ligaFetchInscripcionParejaGrupo($db, $p1, $grupoId);
        if (!$ins || $ins['estado'] !== 'pendiente') {
            throw new RuntimeException('inscripcion no pendiente');
        }
        $svc->actualizarEstadoInscripcion((int) $ins['id'], $grupoId, 'confirmada');

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM clasificacion_parejas WHERE competicion_id = :cid AND grupo_id = :gid AND pareja_id = :pid'
        );
        $stmt->execute(['cid' => $compId, 'gid' => $grupoId, 'pid' => $p1]);
        if ((int) $stmt->fetchColumn() !== 1) {
            compTestLimpiarLiga($db, $compId);
            compTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
            echo "[FAIL] competition_confirmada_clasificacion\n";
            return false;
        }

        compTestLimpiarLiga($db, $compId);
        compTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
        echo "[OK] competition_confirmada_clasificacion\n";
        return true;
    } catch (Throwable $e) {
        compTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
        echo "[FAIL] competition_confirmada_clasificacion: " . $e->getMessage() . "\n";
        return false;
    }
}

function test_competition_calendario_solo_confirmadas(): bool
{
    $db = getDBConnection();
    $svc = leagueCompetitionService($db);
    $pairs = pairService($db);
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $users = [];
    $parejaIds = [];
    for ($i = 0; $i < 4; $i++) {
        $users[] = compTestCrearUsuario($db, 'c' . $i);
    }

    try {
        $compId = $svc->crearLiga('Test Comp Cal ' . bin2hex(random_bytes(2)), 'general');
        $svc->publicarLiga($compId, 'abierta');
        $grupoId = (int) $db->query(
            "SELECT id FROM competicion_grupos WHERE competicion_id = {$compId} LIMIT 1"
        )->fetchColumn();

        $parejaIds[] = $pairs->crearParejaAdmin($users[0], $users[1], null, $adminId ?: $users[0]);
        $parejaIds[] = $pairs->crearParejaAdmin($users[2], $users[3], null, $adminId ?: $users[0]);

        $svc->inscribirParejaAdmin($compId, $grupoId, $parejaIds[0], 'confirmada');
        $svc->solicitarInscripcion($parejaIds[1], $grupoId);

        if ($svc->contarInscripcionesConfirmadasGrupo($grupoId) !== 1) {
            throw new RuntimeException('confirmadas count');
        }

        $r1 = $svc->generarCalendarioRoundRobin($grupoId, date('Y-m-d'), 7);
        if ($r1['created'] > 0) {
            compTestLimpiarLiga($db, $compId);
            compTestLimpiarUsuarios($db, $users);
            echo "[FAIL] competition_calendario_sin_dos_confirmadas\n";
            return false;
        }

        $svc->actualizarEstadoInscripcion(
            (int) ligaFetchInscripcionParejaGrupo($db, $parejaIds[1], $grupoId)['id'],
            $grupoId,
            'confirmada'
        );
        $r2 = $svc->generarCalendarioRoundRobin($grupoId, date('Y-m-d'), 7);
        if ($r2['created'] < 1) {
            compTestLimpiarLiga($db, $compId);
            compTestLimpiarUsuarios($db, $users);
            echo "[FAIL] competition_calendario_con_dos_confirmadas\n";
            return false;
        }

        compTestLimpiarLiga($db, $compId);
        compTestLimpiarUsuarios($db, $users);
        echo "[OK] competition_calendario_solo_confirmadas\n";
        return true;
    } catch (Throwable $e) {
        compTestLimpiarUsuarios($db, $users);
        echo "[FAIL] competition_calendario_solo_confirmadas: " . $e->getMessage() . "\n";
        return false;
    }
}
