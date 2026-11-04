<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/history_service.php';
require_once '../includes/usuario_layout.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$esquemaPadel = usuarioEsquemaPadel($db);
$history = leagueHistoryService($db);

$historialPareja = [];
$historialJugador = [];
$resumenPareja = null;
$resumenJugador = ['partidos_jugados' => 0, 'partidos_ganados' => 0, 'partidos_perdidos' => 0];
$parejaId = null;
$contexto = null;
$tituloLiga = '';

if ($esquemaPadel) {
    $parejaId = fetchParejaIdUsuario($db, $usuario_id);
    if ($parejaId) {
        $contexto = fetchContextoLigaPareja($db, $parejaId);
        if ($contexto) {
            $tituloLiga = $contexto['comp_nombre'] ?? '';
        }
        $historialPareja = $history->fetchPartidosPareja($parejaId, 'validado', 50);
        $resumenPareja = $history->resumenPareja($parejaId, $contexto);
    }
    $historialJugador = $history->fetchPartidosJugador($usuario_id, 50);
    $resumenJugador = $history->resumenJugador($usuario_id);
}

$total_notificaciones = 0;

usuarioLayoutStart('Historial', $total_notificaciones);
usuarioPageHeading('Historial y estadísticas');
?>

<?php if (!$esquemaPadel): ?>
    <?php usuarioAlert('Historial de liga disponible solo en instalación pádel.', 'warning'); ?>
<?php elseif (!$parejaId): ?>
    <?php usuarioAlert('Necesitas una pareja activa. <a href="pareja.php">Formar pareja</a>', 'warning', false); ?>
<?php else: ?>
    <?php if ($tituloLiga !== ''): ?>
        <?php usuarioPanelSubtitle(htmlspecialchars($tituloLiga)); ?>
    <?php endif; ?>

    <?php usuarioCardOpen('Tu pareja'); ?>
            <?php if ($resumenPareja): ?>
                <p><strong>Puntos:</strong> <?= (int) $resumenPareja['puntos'] ?>
                    · <strong>PJ:</strong> <?= (int) $resumenPareja['partidos_jugados'] ?>
                    · <strong>V:</strong> <?= (int) $resumenPareja['partidos_ganados'] ?></p>
                <p><strong>Posición en grupo:</strong> <?= (int) $resumenPareja['posicion_grupo'] ?: '—' ?>
                    <?php if (($contexto['modalidad_liga'] ?? '') === 'grupos'): ?>
                        · <strong>Pos. global:</strong> <?= (int) $resumenPareja['posicion_global'] ?: '—' ?>
                    <?php endif; ?>
                </p>
                <p class="text-muted">Sets <?= (int) $resumenPareja['sets_favor'] ?>–<?= (int) $resumenPareja['sets_contra'] ?></p>
            <?php else: ?>
                <p>Sin datos de clasificación todavía.</p>
            <?php endif; ?>
    <?php usuarioCardClose(); ?>

    <?php usuarioCardOpen('Tú como jugador'); ?>
            <p><strong>Partidos (snapshot):</strong> <?= (int) $resumenJugador['partidos_jugados'] ?></p>
            <p><strong>Victorias:</strong> <?= (int) $resumenJugador['partidos_ganados'] ?>
                · <strong>Derrotas:</strong> <?= (int) $resumenJugador['partidos_perdidos'] ?></p>
            <p class="text-muted">Solo partidos validados en los que constas como integrante al cerrar el encuentro.</p>
    <?php usuarioCardClose(); ?>

    <?php usuarioCardOpen('Historial de la pareja'); ?>
        <div class="table-responsive tabla-historial">
            <table class="<?= padelLeagueTableClass() ?>">
                <thead><tr><th>Fecha</th><th class="player-name">Partido</th><th>Resultado</th><th>V/D</th></tr></thead>
                <tbody>
                <?php foreach ($historialPareja as $p): ?>
                    <?php $gano = (int) $p['ganador_pareja_id'] === $parejaId; ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                        <td class="col-pareja"><?= padelHtmlEnfrentamientoParejas($p['etiqueta_a'] ?? '', $p['etiqueta_b'] ?? '') ?></td>
                        <td><?= sanitizeOutput($p['resultado'] ?? '') ?></td>
                        <td><?php usuarioBadge($gano ? 'Victoria' : 'Derrota', $gano ? 'success' : 'danger'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($historialPareja === []): ?>
                    <tr><td colspan="4">Sin partidos validados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php usuarioCardClose(); ?>

    <?php usuarioCardOpen('Tus partidos (personal)'); ?>
        <div class="table-responsive tabla-historial">
            <table class="<?= padelLeagueTableClass() ?>">
                <thead><tr><th>Fecha</th><th>Competición</th><th class="player-name">Partido</th><th>Resultado</th><th>V/D</th></tr></thead>
                <tbody>
                <?php foreach ($historialJugador as $p): ?>
                    <?php
                    $miP = (int) ($p['mi_pareja_en_partido'] ?? $parejaId);
                    $gano = $miP > 0 && (int) ($p['ganador_pareja_id'] ?? 0) === $miP;
                    ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                        <td><?= sanitizeOutput(($p['comp_nombre'] ?? '') . (($p['grupo_nombre'] ?? '') !== '' ? ' · ' . $p['grupo_nombre'] : '')) ?></td>
                        <td class="col-pareja"><?= padelHtmlEnfrentamientoParejas($p['etiqueta_a'] ?? '', $p['etiqueta_b'] ?? '') ?></td>
                        <td><?= sanitizeOutput($p['resultado'] ?? '') ?></td>
                        <td><?php usuarioBadge($gano ? 'Victoria' : 'Derrota', $gano ? 'success' : 'danger'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($historialJugador === []): ?>
                    <tr><td colspan="5">Aún no hay partidos validados con tu snapshot.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php usuarioCardClose(); ?>
<?php endif; ?>

<?php usuarioLayoutEnd(); ?>
