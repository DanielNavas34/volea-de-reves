<?php

/**
 * Presentación UI pádel — etiquetas de pareja (2 jugadores) y tablas (Fase 9).
 */

/** Texto plano de pareja (ya escapado o para atributos). */
function padelEtiquetaParejaPlain(string $etiqueta): string
{
    $etiqueta = trim($etiqueta);

    return $etiqueta !== '' ? $etiqueta : 'Pareja';
}

/**
 * HTML seguro: "Nombre1 / Nombre2" con estilos `.pareja-etiqueta`.
 */
function padelHtmlEtiquetaPareja(string $etiqueta): string
{
    $plain = padelEtiquetaParejaPlain($etiqueta);
    $parts = preg_split('/\s*\/\s*/', $plain, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false || count($parts) < 2) {
        return '<span class="pareja-etiqueta pareja-etiqueta--simple">'
            . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html = '<span class="pareja-etiqueta">';
    foreach ($parts as $i => $part) {
        if ($i > 0) {
            $html .= '<span class="pareja-sep" aria-hidden="true">/</span>';
        }
        $html .= '<span class="pareja-miembro">' . htmlspecialchars(trim($part), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    return $html . '</span>';
}

function padelHtmlEnfrentamientoParejas(string $etiquetaA, string $etiquetaB): string
{
    return '<span class="enfrentamiento-parejas">'
        . padelHtmlEtiquetaPareja($etiquetaA)
        . '<span class="vs-badge vs-badge--inline">vs</span>'
        . padelHtmlEtiquetaPareja($etiquetaB)
        . '</span>';
}

/** Clases recomendadas para tablas de liga/parejas. */
function padelLeagueTableClass(): string
{
    return 'league-table league-table--padel';
}
