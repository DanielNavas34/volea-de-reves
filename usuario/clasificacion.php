<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/standings_service.php';
require_once '../includes/usuario_layout.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$legacyLiga = legacyLeagueSchemaAvailable($db);
$esquemaPadel = usuarioEsquemaPadel($db);

$clasificacion = [];
$clasificacionGlobal = [];
$mostrarGlobal = false;
$nombre_grupo = '';
$miParejaId = null;

if ($esquemaPadel) {
    $pareja = fetchParejaActivaDeUsuario($db, $usuario_id);
    if ($pareja) {
        $miParejaId = (int) $pareja['id'];
        $contexto = fetchContextoLigaPareja($db, $miParejaId);
        if ($contexto) {
            $nombre_grupo = $contexto['comp_nombre'] . ' · ' . $contexto['grupo_nombre'];
            $standings = leagueStandingsService($db);
            $compId = (int) $contexto['competicion_id'];
            $clasificacion = $standings->fetchGrupoStandings(
                $compId,
                isset($contexto['grupo_id']) ? (int) $contexto['grupo_id'] : null
            );
            if (($contexto['modalidad_liga'] ?? '') === 'grupos') {
                $mostrarGlobal = true;
                $clasificacionGlobal = $standings->fetchCompeticionGlobalStandings($compId);
            }
        }
    }
} elseif ($legacyLiga) {
    $stmt_grupo = $db->prepare(
        'SELECT u.grupo_id, g.nombre AS nombre_grupo FROM usuarios u LEFT JOIN grupos g ON u.grupo_id = g.id WHERE u.id = :uid'
    );
    $stmt_grupo->execute(['uid' => $usuario_id]);
    $user_data = $stmt_grupo->fetch(PDO::FETCH_ASSOC);
    $grupo_id = $user_data['grupo_id'] ?? null;
    $nombre_grupo = $user_data['nombre_grupo'] ?? '';
    if ($grupo_id) {
        $stmt = $db->prepare(
            'SELECT u.id, u.nombre, u.apellidos, c.puntos, c.partidos_jugados, c.partidos_ganados
             FROM clasificacion c JOIN usuarios u ON c.usuario_id = u.id
             WHERE c.grupo_id = :gid ORDER BY c.puntos DESC'
        );
        $stmt->execute(['gid' => $grupo_id]);
        $clasificacion = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$total_notificaciones = 0;

usuarioLayoutStart('Clasificación', $total_notificaciones);
usuarioPageHeading('Clasificación');
?>

<?php if ($esquemaPadel && !$miParejaId): ?>
    <?php usuarioAlert('Necesitas una pareja activa. <a href="pareja.php">Formar pareja</a>', 'warning', false); ?>
<?php elseif ($nombre_grupo === ''): ?>
    <?php usuarioAlert('No hay liga/grupo asignado todavía.', 'warning'); ?>
<?php else: ?>
    <?php usuarioPanelSubtitle('Grupo: ' . $nombre_grupo); ?>
    <?php usuarioCardOpen('Tabla de clasificación'); ?>
        <div class="table-responsive">
        <table class="<?= padelLeagueTableClass() ?>">
                    <thead>
                    <tr><th>#</th><th class="player-name">Pareja</th><th>PJ</th><th>V</th><th>D</th><th>Pts</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clasificacion as $i => $row): ?>
                        <?php
                        $esYo = $esquemaPadel ? ((int) $row['id'] === $miParejaId) : ((int) $row['id'] === $usuario_id);
                        $pj = (int) $row['partidos_jugados'];
                        $pg = (int) $row['partidos_ganados'];
                        $nombre = $esquemaPadel ? ($row['nombre_display'] ?? 'Pareja') : ($row['nombre'] . ' ' . $row['apellidos']);
                        ?>
                        <tr class="<?= $esYo ? 'row-highlight' : '' ?>">
                            <td><?= $i + 1 ?></td>
                            <td class="player-name col-pareja"><?= padelHtmlEtiquetaPareja($nombre) ?><?= $esYo ? ' <span class="text-muted-inline">(Tú)</span>' : '' ?></td>
                            <td><?= $pj ?></td>
                            <td><?= $pg ?></td>
                            <td><?= $pj - $pg ?></td>
                            <td><strong><?= (int) $row['puntos'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($clasificacion === []): ?>
                        <tr><td colspan="6">Sin datos de clasificación aún.</td></tr>
                    <?php endif; ?>
                    </tbody>
        </table>
        </div>
    <?php usuarioCardClose(); ?>

    <?php if ($mostrarGlobal): ?>
        <?php usuarioCardOpen('Clasificación global (todos los grupos)'); ?>
            <p class="text-muted" style="margin-top:0;">Ranking informativo entre todos los grupos; no sustituye la clasificación de tu grupo.</p>
            <div class="table-responsive">
            <table class="<?= padelLeagueTableClass() ?>">
                <thead>
                <tr><th>#</th><th>Grupo</th><th class="player-name">Pareja</th><th>PJ</th><th>V</th><th>Pts</th></tr>
                </thead>
                <tbody>
                <?php foreach ($clasificacionGlobal as $i => $row): ?>
                    <?php
                    $esYo = (int) ($row['id'] ?? 0) === $miParejaId;
                    $pj = (int) $row['partidos_jugados'];
                    $pg = (int) $row['partidos_ganados'];
                    ?>
                    <tr class="<?= $esYo ? 'row-highlight' : '' ?>">
                        <td><?= $i + 1 ?></td>
                        <td><?= sanitizeOutput($row['grupo_nombre'] ?? '—') ?></td>
                        <td class="player-name col-pareja"><?= padelHtmlEtiquetaPareja($row['nombre_display'] ?? 'Pareja') ?><?= $esYo ? ' <span class="text-muted-inline">(Tú)</span>' : '' ?></td>
                        <td><?= $pj ?></td>
                        <td><?= $pg ?></td>
                        <td><strong><?= (int) $row['puntos'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($clasificacionGlobal === []): ?>
                    <tr><td colspan="6">Sin datos.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        <?php usuarioCardClose(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php usuarioLayoutEnd(); ?>
