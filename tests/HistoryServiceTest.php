<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/history_service.php';
require_once __DIR__ . '/../includes/standings_service.php';

function test_history_service_class_and_resumen_vacio(): bool
{
    $db = getDBConnection();
    $history = leagueHistoryService($db);
    if (!($history instanceof LeagueHistoryService)) {
        echo "[FAIL] history_service_class\n";
        return false;
    }
    $res = $history->resumenJugador(PHP_INT_MAX);
    if ($res['partidos_jugados'] !== 0) {
        echo "[FAIL] history_resumen_jugador_vacio\n";
        return false;
    }
    echo "[OK] history_service_class_and_resumen\n";
    return true;
}

function test_standings_global_ordena_grupos(): bool
{
    $db = getDBConnection();
    $svc = leagueStandingsService($db);
    $rows = [
        [
            'pareja_id' => 10,
            'puntos' => 200,
            'partidos_ganados' => 2,
            'sets_favor' => 4,
            'sets_contra' => 0,
            'juegos_favor' => 24,
            'juegos_contra' => 10,
            'grupo_nombre' => 'B',
        ],
        [
            'pareja_id' => 20,
            'puntos' => 180,
            'partidos_ganados' => 1,
            'sets_favor' => 2,
            'sets_contra' => 2,
            'juegos_favor' => 20,
            'juegos_contra' => 18,
            'grupo_nombre' => 'A',
        ],
    ];
    $sorted = $svc->sortStandingsRows($rows);
    if ((int) $sorted[0]['pareja_id'] !== 10) {
        echo "[FAIL] standings_global_sort\n";
        return false;
    }
    echo "[OK] standings_global_ordena_grupos\n";
    return true;
}
