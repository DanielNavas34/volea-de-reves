<?php

require_once __DIR__ . '/parejas_helpers.php';

/** Tabla `partido_integrantes_snapshot` disponible en esta BD. */
function padelSnapshotTableAvailable(PDO $db): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) $db->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'partido_integrantes_snapshot' LIMIT 1"
        )->fetchColumn();
    }

    return $ok;
}

/**
 * Fija los 4 titulares activos (2 por pareja) al validar un partido.
 * Idempotente: no duplica si ya hay filas para el partido.
 */
function padelEnsurePartidoIntegrantesSnapshot(PDO $db, int $partidoId): void
{
    if ($partidoId <= 0 || !padelSnapshotTableAvailable($db)) {
        return;
    }
    $chk = $db->prepare('SELECT COUNT(*) FROM partido_integrantes_snapshot WHERE partido_id = :id');
    $chk->execute(['id' => $partidoId]);
    if ((int) $chk->fetchColumn() > 0) {
        return;
    }

    $stmt = $db->prepare(
        'SELECT pareja_a_id, pareja_b_id FROM partidos WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $partidoId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return;
    }

    $parejas = [
        'A' => (int) $p['pareja_a_id'],
        'B' => (int) $p['pareja_b_id'],
    ];
    $ins = $db->prepare(
        'INSERT INTO partido_integrantes_snapshot (partido_id, pareja_id, lado, usuario_id, orden_en_pareja)
         VALUES (:pid, :pareja, :lado, :uid, :orden)'
    );
    foreach ($parejas as $lado => $parejaId) {
        if ($parejaId <= 0) {
            continue;
        }
        $miembros = fetchMiembrosPareja($db, $parejaId);
        $orden = 1;
        foreach ($miembros as $m) {
            if (($m['rol'] ?? '') !== 'titular') {
                continue;
            }
            $ins->execute([
                'pid' => $partidoId,
                'pareja' => $parejaId,
                'lado' => $lado,
                'uid' => (int) $m['id'],
                'orden' => $orden,
            ]);
            $orden++;
            if ($orden > 2) {
                break;
            }
        }
    }
}
