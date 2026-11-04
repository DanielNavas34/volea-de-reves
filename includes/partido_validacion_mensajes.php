<?php

require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/padel_league_points.php';
require_once __DIR__ . '/partido_flujo_usuario.php';

const PARTIDO_AUTO_VALIDACION_HORAS = 24;

function partidoTieneResultadoReportadoEn(PDO $db): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = dbColumnExists($db, 'partidos', 'resultado_reportado_en');
    }

    return $cache;
}

/** Instantánea desde la que corre el plazo de 24 h (reporte del resultado). */
function partidoResultadoReportadoEn(array $partido): ?string
{
    if (!empty($partido['resultado_reportado_en'])) {
        return (string) $partido['resultado_reportado_en'];
    }

    return null;
}

/** Segundos hasta auto-validación; null si no aplica; 0 si ya venció. */
function partidoSegundosHastaAutoValidacion(PDO $db, array $partido): ?int
{
    if (($partido['estado'] ?? '') !== 'pendiente' || empty($partido['ganador_pareja_id'])) {
        return null;
    }
    $desde = partidoResultadoReportadoEn($partido);
    if ($desde === null && partidoTieneResultadoReportadoEn($db)) {
        return null;
    }
    if ($desde === null) {
        $desde = (string) ($partido['fecha_registro'] ?? '');
    }
    $inicio = strtotime($desde);
    if ($inicio === false) {
        return null;
    }
    $limite = $inicio + PARTIDO_AUTO_VALIDACION_HORAS * 3600;
    $resto = $limite - time();

    return max(0, $resto);
}

function partidoFormatearCuentaAtras24h(int $segundosRestantes): string
{
    if ($segundosRestantes <= 0) {
        return 'Validación automática en breve';
    }
    $h = (int) floor($segundosRestantes / 3600);
    $m = (int) floor(($segundosRestantes % 3600) / 60);
    $s = $segundosRestantes % 60;

    return sprintf('Quedan %02d:%02d:%02d para la validación automática', $h, $m, $s);
}

/**
 * Mensaje para la pareja que debe aceptar/rechazar el resultado reportado por el rival.
 *
 * @return array{titulo: string, mensaje: string, es_victoria: bool, rival: string, marcador: string, segundos_auto: int|null}
 */
function partidoMensajeValidacionParaPareja(PDO $db, array $partido, int $parejaUsuarioId): array
{
    $parejaA = (int) ($partido['pareja_a_id'] ?? 0);
    $parejaB = (int) ($partido['pareja_b_id'] ?? 0);
    $rivalId = $parejaA === $parejaUsuarioId ? $parejaB : $parejaA;
    $rivalNom = $parejaA === $parejaUsuarioId
        ? (string) ($partido['etiqueta_b'] ?? fetchParejaEtiqueta($db, $parejaB))
        : (string) ($partido['etiqueta_a'] ?? fetchParejaEtiqueta($db, $parejaA));
    $marcador = (string) ($partido['resultado'] ?? padelFormatSetsForPartido($db, (int) ($partido['id'] ?? 0)));
    $ganador = (int) ($partido['ganador_pareja_id'] ?? 0);
    $esVictoria = $ganador === $parejaUsuarioId;
    $segundos = partidoSegundosHastaAutoValidacion($db, $partido);

    if ($esVictoria) {
        $titulo = '¡Os han concedido la victoria!';
        $mensaje = 'La pareja ' . $rivalNom . ' ha reportado que ganasteis este partido'
            . ($marcador !== '' ? ' (' . $marcador . ')' : '')
            . '. Confirmad el resultado o rechazadlo si no es correcto.';
    } else {
        $titulo = 'Derrota reportada';
        $mensaje = 'Has perdido contra ' . $rivalNom . ' según el marcador que han introducido'
            . ($marcador !== '' ? ': ' . $marcador : '')
            . '. Si no es correcto, rechazad el resultado.';
    }
    if ($segundos !== null) {
        $mensaje .= ' Si no respondes, el resultado se validará solo a las 24 horas.';
    }

    return [
        'titulo' => $titulo,
        'mensaje' => $mensaje,
        'es_victoria' => $esVictoria,
        'rival' => $rivalNom,
        'marcador' => $marcador,
        'segundos_auto' => $segundos,
    ];
}

/** Bloque HTML reutilizable (partidos / dashboard). */
function partidoRenderAlertaValidacionRival(PDO $db, array $partido, int $parejaUsuarioId, bool $includeTitle = true): void
{
    $info = partidoMensajeValidacionParaPareja($db, $partido, $parejaUsuarioId);
    $class = $info['es_victoria'] ? 'alert alert-success partido-validacion-msg' : 'alert alert-warning partido-validacion-msg';
    $deadline = $info['segundos_auto'] !== null
        ? (time() + $info['segundos_auto'])
        : 0;
    echo '<div class="' . htmlspecialchars($class) . '">';
    if ($includeTitle) {
        echo '<strong>' . htmlspecialchars($info['titulo']) . '</strong>';
    }
    echo '<p class="partido-validacion-text">' . htmlspecialchars($info['mensaje']) . '</p>';
    if ($deadline > 0) {
        echo '<p class="partido-auto-val-countdown" data-deadline="' . (int) $deadline . '" aria-live="polite">';
        echo htmlspecialchars(partidoFormatearCuentaAtras24h($info['segundos_auto'] ?? 0));
        echo '</p>';
    }
    echo '</div>';
}
