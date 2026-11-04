<?php

require_once __DIR__ . '/../includes/padel_guardrails.php';

function test_padeld_codigo_error_reporte_validado(): bool
{
    $code = padelCodigoErrorReportePartido(['estado' => 'validado', 'ganador_pareja_id' => 1]);
    if ($code !== 'error_validado') {
        echo "[FAIL] guardrails_reporte_validado\n";
        return false;
    }
    echo "[OK] guardrails_reporte_validado\n";
    return true;
}

function test_padeld_codigo_error_reporte_pendiente_con_ganador(): bool
{
    $code = padelCodigoErrorReportePartido(['estado' => 'pendiente', 'ganador_pareja_id' => 5]);
    if ($code !== 'error_ya_reportado') {
        echo "[FAIL] guardrails_reporte_ya_reportado\n";
        return false;
    }
    echo "[OK] guardrails_reporte_ya_reportado\n";
    return true;
}

function test_padeld_codigo_error_reporte_incidencia(): bool
{
    if (padelCodigoErrorReportePartido(['estado' => 'incidencia', 'ganador_pareja_id' => null]) !== 'error_incidencia') {
        echo "[FAIL] guardrails_reporte_incidencia\n";
        return false;
    }
    echo "[OK] guardrails_reporte_incidencia\n";
    return true;
}

function test_padeld_codigo_error_reporte_abierto(): bool
{
    if (padelCodigoErrorReportePartido(['estado' => 'pendiente', 'ganador_pareja_id' => null]) !== null) {
        echo "[FAIL] guardrails_reporte_abierto\n";
        return false;
    }
    if (padelCodigoErrorReportePartido(null) !== null) {
        echo "[FAIL] guardrails_reporte_sin_partido\n";
        return false;
    }
    echo "[OK] guardrails_reporte_abierto\n";
    return true;
}

function test_pareja_inscripcion_viva_query(PDO $db): bool
{
    $db->query('SELECT 1 FROM inscripciones i INNER JOIN competiciones c ON c.id = i.competicion_id LIMIT 1');
    echo "[OK] guardrails_inscripcion_query\n";
    return true;
}
