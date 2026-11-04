<?php

require_once __DIR__ . '/../includes/padel_ui_helpers.php';

function test_padel_html_etiqueta_dos_miembros(): bool
{
    $html = padelHtmlEtiquetaPareja('Ana López / Bruno Ruiz');
    if (strpos($html, 'pareja-miembro') === false || strpos($html, 'Ana López') === false || strpos($html, 'Bruno Ruiz') === false) {
        echo "[FAIL] padel_html_etiqueta_dos_miembros\n";
        return false;
    }
    echo "[OK] padel_html_etiqueta_dos_miembros\n";
    return true;
}

function test_padel_html_enfrentamiento(): bool
{
    $html = padelHtmlEnfrentamientoParejas('A / B', 'C / D');
    if (strpos($html, 'enfrentamiento-parejas') === false || substr_count($html, 'pareja-miembro') < 4) {
        echo "[FAIL] padel_html_enfrentamiento\n";
        return false;
    }
    echo "[OK] padel_html_enfrentamiento\n";
    return true;
}

function test_padel_league_table_class(): bool
{
    $c = padelLeagueTableClass();
    if (strpos($c, 'league-table--padel') === false) {
        echo "[FAIL] padel_league_table_class\n";
        return false;
    }
    echo "[OK] padel_league_table_class\n";
    return true;
}
