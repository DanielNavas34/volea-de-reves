<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/standings_service.php';

function test_standings_desempate_diferencia_sets(): bool
{
    $db = getDBConnection();
    $svc = leagueStandingsService($db);
    $a = [
        'pareja_id' => 1,
        'puntos' => 180,
        'partidos_ganados' => 1,
        'sets_favor' => 4,
        'sets_contra' => 2,
        'juegos_favor' => 24,
        'juegos_contra' => 18,
    ];
    $b = [
        'pareja_id' => 2,
        'puntos' => 180,
        'partidos_ganados' => 1,
        'sets_favor' => 3,
        'sets_contra' => 2,
        'juegos_favor' => 24,
        'juegos_contra' => 18,
    ];
    if ($svc->compareStandingsRows($a, $b) >= 0) {
        echo "[FAIL] standings_desempate_diferencia_sets\n";
        return false;
    }
    $sorted = $svc->sortStandingsRows([$b, $a]);
    if ((int) $sorted[0]['pareja_id'] !== 1) {
        echo "[FAIL] standings_sort_order\n";
        return false;
    }
    echo "[OK] standings_desempate_diferencia_sets\n";
    return true;
}

function test_standings_lee_configuracion(): bool
{
    $db = getDBConnection();
    $cfg = leagueStandingsService($db)->getPointsConfig();
    if (($cfg['victoria'] ?? 0) < 1 || ($cfg['derrota'] ?? 0) < 0) {
        echo "[FAIL] standings_lee_configuracion\n";
        return false;
    }
    echo "[OK] standings_lee_configuracion\n";
    return true;
}
