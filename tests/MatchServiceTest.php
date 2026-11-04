<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/match_service.php';
require_once __DIR__ . '/../includes/competition_service.php';
require_once __DIR__ . '/../includes/pair_service.php';
require_once __DIR__ . '/../includes/partido_snapshot.php';
require_once __DIR__ . '/CompetitionServiceTest.php';

function matchTestLimpiarParejas(PDO $db, array $parejaIds): void
{
    foreach ($parejaIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM pareja_miembros WHERE pareja_id = :p')->execute(['p' => $pid]);
        $db->prepare('DELETE FROM parejas WHERE id = :p')->execute(['p' => $pid]);
    }
}

function test_match_validar_crea_snapshot(): bool
{
    $db = getDBConnection();
    if (!padelSnapshotTableAvailable($db)) {
        echo "[OK] match_validar_snapshot_skip\n";
        return true;
    }
    $svc = leagueMatchService($db);
    $compSvc = leagueCompetitionService($db);
    $pairs = pairService($db);
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $users = [];
    $parejaIds = [];
    for ($i = 0; $i < 4; $i++) {
        $users[] = compTestCrearUsuario($db, 'm' . bin2hex(random_bytes(2)) . $i);
    }

    try {
        $compId = $compSvc->crearLiga('Test Match Snap ' . bin2hex(random_bytes(2)), 'general');
        $compSvc->publicarLiga($compId, 'en_curso');
        $grupoId = (int) $db->query(
            "SELECT id FROM competicion_grupos WHERE competicion_id = {$compId} LIMIT 1"
        )->fetchColumn();
        $parejaIds[] = $pairs->crearParejaAdmin($users[0], $users[1], null, $adminId ?: $users[0]);
        $parejaIds[] = $pairs->crearParejaAdmin($users[2], $users[3], null, $adminId ?: $users[0]);
        $compSvc->inscribirParejaAdmin($compId, $grupoId, $parejaIds[0], 'confirmada');
        $compSvc->inscribirParejaAdmin($compId, $grupoId, $parejaIds[1], 'confirmada');

        $db->prepare(
            "INSERT INTO partidos (competicion_id, grupo_id, fase, pareja_a_id, pareja_b_id, fecha, estado, ganador_pareja_id, disputa_confirmada)
             VALUES (:cid, :gid, 'liga', :pa, :pb, CURDATE(), 'pendiente', :g, 1)"
        )->execute([
            'cid' => $compId,
            'gid' => $grupoId,
            'pa' => $parejaIds[0],
            'pb' => $parejaIds[1],
            'g' => $parejaIds[0],
        ]);
        $partidoId = (int) $db->lastInsertId();
        padelReplacePartidoSets($db, $partidoId, '6-4, 6-3');

        $svc->adminValidarPartidoPendiente($partidoId);

        $stmt = $db->prepare('SELECT COUNT(*) FROM partido_integrantes_snapshot WHERE partido_id = :id');
        $stmt->execute(['id' => $partidoId]);
        $cnt = (int) $stmt->fetchColumn();
        if ($cnt !== 4) {
            compTestLimpiarLiga($db, $compId);
            matchTestLimpiarParejas($db, $parejaIds);
            compTestLimpiarUsuarios($db, $users);
            echo "[FAIL] match_validar_snapshot_count: {$cnt}\n";
            return false;
        }

        compTestLimpiarLiga($db, $compId);
        matchTestLimpiarParejas($db, $parejaIds);
        compTestLimpiarUsuarios($db, $users);
        echo "[OK] match_validar_crea_snapshot\n";
        return true;
    } catch (Throwable $e) {
        compTestLimpiarUsuarios($db, $users);
        echo '[FAIL] match_validar_crea_snapshot: ' . $e->getMessage() . "\n";
        return false;
    }
}

function test_match_reporte_y_aceptar_clasificacion(): bool
{
    $db = getDBConnection();
    $svc = leagueMatchService($db);
    $compSvc = leagueCompetitionService($db);
    $pairs = pairService($db);
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $users = [];
    for ($i = 0; $i < 4; $i++) {
        $users[] = compTestCrearUsuario($db, 'r' . bin2hex(random_bytes(2)) . $i);
    }
    $parejaIds = [];

    try {
        $compId = $compSvc->crearLiga('Test Match Flow ' . bin2hex(random_bytes(2)), 'general');
        $compSvc->publicarLiga($compId, 'en_curso');
        $grupoId = (int) $db->query(
            "SELECT id FROM competicion_grupos WHERE competicion_id = {$compId} LIMIT 1"
        )->fetchColumn();
        $parejaIds[] = $pairs->crearParejaAdmin($users[0], $users[1], null, $adminId ?: $users[0]);
        $parejaIds[] = $pairs->crearParejaAdmin($users[2], $users[3], null, $adminId ?: $users[0]);
        $compSvc->inscribirParejaAdmin($compId, $grupoId, $parejaIds[0], 'confirmada');
        $compSvc->inscribirParejaAdmin($compId, $grupoId, $parejaIds[1], 'confirmada');

        $db->prepare(
            "INSERT INTO partidos (competicion_id, grupo_id, fase, pareja_a_id, pareja_b_id, fecha, fecha_programada, estado, disputa_confirmada)
             VALUES (:cid, :gid, 'liga', :pa, :pb, CURDATE(), CURDATE(), 'pendiente', 1)"
        )->execute([
            'cid' => $compId,
            'gid' => $grupoId,
            'pa' => $parejaIds[0],
            'pb' => $parejaIds[1],
        ]);
        $partidoId = (int) $db->lastInsertId();

        $sets = [
            ['numero' => 1, 'juegos_a' => 6, 'juegos_b' => 2],
            ['numero' => 2, 'juegos_a' => 6, 'juegos_b' => 3],
        ];
        $svc->reportarResultadoUsuario(
            $partidoId,
            $users[0],
            $parejaIds[0],
            $parejaIds[0],
            date('Y-m-d'),
            $sets
        );
        $svc->aceptarResultadoRival($partidoId, $users[2]);

        $pts = $db->prepare(
            'SELECT puntos, partidos_jugados FROM clasificacion_parejas
             WHERE competicion_id = :cid AND grupo_id = :gid AND pareja_id = :pid'
        );
        $pts->execute(['cid' => $compId, 'gid' => $grupoId, 'pid' => $parejaIds[0]]);
        $row = $pts->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['partidos_jugados'] !== 1 || (int) $row['puntos'] < 1) {
            compTestLimpiarLiga($db, $compId);
            matchTestLimpiarParejas($db, $parejaIds);
            compTestLimpiarUsuarios($db, $users);
            echo "[FAIL] match_reporte_aceptar_clasificacion\n";
            return false;
        }

        compTestLimpiarLiga($db, $compId);
        matchTestLimpiarParejas($db, $parejaIds);
        compTestLimpiarUsuarios($db, $users);
        echo "[OK] match_reporte_y_aceptar_clasificacion\n";
        return true;
    } catch (Throwable $e) {
        compTestLimpiarUsuarios($db, $users);
        echo '[FAIL] match_reporte_y_aceptar_clasificacion: ' . $e->getMessage() . "\n";
        return false;
    }
}
