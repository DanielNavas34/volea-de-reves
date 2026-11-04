<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/liga_service.php';

function test_liga_round_robin_math(): bool
{
    $parejas = [1, 2, 3, 4];
    $expected = 6;
    $pairs = 0;
    $n = count($parejas);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $pairs++;
        }
    }
    if ($pairs !== $expected) {
        echo "[FAIL] liga_round_robin_math\n";
        return false;
    }
    echo "[OK] liga_round_robin_math\n";
    return true;
}

function test_liga_round_robin_jornadas(): bool
{
    $plan = ligaRoundRobinJornadas([10, 20, 30, 40]);
    if (count($plan) !== 3) {
        echo "[FAIL] liga_round_robin_jornadas_count\n";
        return false;
    }
    $all = [];
    foreach ($plan as $bloque) {
        foreach ($bloque['pairs'] as [$a, $b]) {
            $key = min($a, $b) . '-' . max($a, $b);
            $all[$key] = true;
        }
    }
    if (count($all) !== 6) {
        echo "[FAIL] liga_round_robin_jornadas_pairs\n";
        return false;
    }
    echo "[OK] liga_round_robin_jornadas\n";
    return true;
}

function test_liga_partido_fecha_futura_bloqueo(): bool
{
    $manana = date('Y-m-d', strtotime('+1 day'));
    if (ligaPartidoPermiteReporteResultado(['fecha_programada' => $manana])) {
        echo "[FAIL] liga_partido_fecha_futura\n";
        return false;
    }
    $ayer = date('Y-m-d', strtotime('-1 day'));
    if (!ligaPartidoPermiteReporteResultado(['fecha_programada' => $ayer])) {
        echo "[FAIL] liga_partido_fecha_pasada\n";
        return false;
    }
    echo "[OK] liga_partido_fecha_reporte\n";
    return true;
}
