<?php
/**
 * Actualización de clasificación legacy (tabla clasificacion) con consultas preparadas.
 * Sustituir por LeagueStandingsService en fases posteriores (clasificacion_parejas).
 */

function leagueApplyValidatedMatchPoints(PDO $db, int $ganadorUsuarioId, int $perdedorUsuarioId, int $puntosVictoria = 180, int $puntosDerrota = 50): void
{
    $stmtWin = $db->prepare(
        'UPDATE clasificacion SET puntos = puntos + :pts, partidos_jugados = partidos_jugados + 1, partidos_ganados = partidos_ganados + 1 WHERE usuario_id = :uid'
    );
    $stmtWin->execute(['pts' => $puntosVictoria, 'uid' => $ganadorUsuarioId]);

    $stmtLoss = $db->prepare(
        'UPDATE clasificacion SET puntos = puntos + :pts, partidos_jugados = partidos_jugados + 1 WHERE usuario_id = :uid'
    );
    $stmtLoss->execute(['pts' => $puntosDerrota, 'uid' => $perdedorUsuarioId]);
}

function leagueRevertValidatedMatchPoints(PDO $db, int $ganadorUsuarioId, int $perdedorUsuarioId, int $puntosVictoria = 180, int $puntosDerrota = 50): void
{
    $stmtWin = $db->prepare(
        'UPDATE clasificacion SET puntos = puntos - :pts, partidos_jugados = partidos_jugados - 1, partidos_ganados = partidos_ganados - 1 WHERE usuario_id = :uid'
    );
    $stmtWin->execute(['pts' => $puntosVictoria, 'uid' => $ganadorUsuarioId]);

    $stmtLoss = $db->prepare(
        'UPDATE clasificacion SET puntos = puntos - :pts, partidos_jugados = partidos_jugados - 1 WHERE usuario_id = :uid'
    );
    $stmtLoss->execute(['pts' => $puntosDerrota, 'uid' => $perdedorUsuarioId]);
}
