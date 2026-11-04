<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/league_points.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/partido_validacion_mensajes.php';
require_once '../includes/usuario_layout.php';
require_once '../includes/perfil_helpers.php';
require_once '../includes/usuario_perfil_service.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$legacyLiga = legacyLeagueSchemaAvailable($db);
$esquemaPadel = usuarioEsquemaPadel($db);
$csrf = generateCSRFToken();

if ($esquemaPadel) {
    padelAutoValidarPartidosCaducados($db);
}

if ($legacyLiga) {
    $caducados = $db->query(
        "SELECT id, jugador1_id, jugador2_id FROM partidos
         WHERE estado = 'pendiente' AND fecha_registro <= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($caducados !== []) {
        $db->beginTransaction();
        try {
            foreach ($caducados as $p) {
                $db->prepare("UPDATE partidos SET estado = 'validado' WHERE id = :id")->execute(['id' => $p['id']]);
                leagueApplyValidatedMatchPoints($db, (int) $p['jugador1_id'], (int) $p['jugador2_id']);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
        }
    }
}

$redirectMsg = 'dashboard.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_partido'], $_POST['id_partido'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Error CSRF.');
    }
    $id = (int) $_POST['id_partido'];
    $accion = $_POST['accion_partido'];
    $motivo = isset($_POST['motivo']) ? trim((string) $_POST['motivo']) : null;
    if ($esquemaPadel && padelProcesarAccionPartidoUsuario($db, $usuario_id, $id, $accion, $motivo)) {
        header('Location: dashboard.php?msg=' . ($accion === 'aceptar' ? 'aceptado' : 'rechazado'));
        exit;
    }
    if ($legacyLiga) {
        $stmt = $db->prepare(
            "SELECT jugador1_id, jugador2_id FROM partidos WHERE id = :id AND estado = 'pendiente'
             AND (jugador1_id = :u1 OR jugador2_id = :u2) AND reportado_por != :u3 LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'u1' => $usuario_id, 'u2' => $usuario_id, 'u3' => $usuario_id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p && $accion === 'aceptar') {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE partidos SET estado = 'validado' WHERE id = :id")->execute(['id' => $id]);
                leagueApplyValidatedMatchPoints($db, (int) $p['jugador1_id'], (int) $p['jugador2_id']);
                $db->commit();
                header('Location: dashboard.php?msg=aceptado');
                exit;
            } catch (PDOException $e) {
                $db->rollBack();
            }
        } elseif ($p && $accion === 'rechazar') {
            $db->prepare("UPDATE partidos SET estado = 'rechazado', motivo_rechazo = :m WHERE id = :id")
                ->execute(['m' => $motivo ?: 'Sin motivo', 'id' => $id]);
            header('Location: dashboard.php?msg=rechazado');
            exit;
        }
    }
}

$fotoColDash = usuarioPerfilFotoColumnDisponible($db) ? ', foto_perfil' : '';
$stmt = $db->prepare("SELECT nombre, apellidos, email, telefono{$fotoColDash} FROM usuarios WHERE id = :id");
$stmt->execute(['id' => $usuario_id]);
$jugador = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$parejaInfo = null;
$contextoLiga = null;
$clasif = null;
$pendientes = [];
$proximos = [];
$ultimos_partidos = [];
$invitaciones_pareja = [];
$grupo_nombre = '—';
$jugador['puntos'] = 0;
$jugador['partidos_jugados'] = 0;
$jugador['partidos_ganados'] = 0;

if ($esquemaPadel) {
    $parejaInfo = fetchParejaActivaDeUsuario($db, $usuario_id);
    if ($parejaInfo) {
        $contextoLiga = fetchContextoLigaPareja($db, (int) $parejaInfo['id']);
        if ($contextoLiga) {
            $grupo_nombre = $contextoLiga['comp_nombre'] . ' · ' . $contextoLiga['grupo_nombre'];
        }
        $clasif = fetchClasificacionPareja($db, (int) $parejaInfo['id'], $contextoLiga);
        if ($clasif) {
            $jugador['puntos'] = (int) $clasif['puntos'];
            $jugador['partidos_jugados'] = (int) $clasif['partidos_jugados'];
            $jugador['partidos_ganados'] = (int) $clasif['partidos_ganados'];
        }
        require_once '../includes/partido_flujo_usuario.php';
        $allPending = fetchPartidosParejaUsuario($db, (int) $parejaInfo['id'], 'pendiente');
        foreach ($allPending as $p) {
            if (padelPartidoPendienteValidacionParaUsuario($db, $p, $usuario_id)) {
                $pendientes[] = $p;
            }
        }
        $proximos = fetchPartidosParejaUsuario($db, (int) $parejaInfo['id'], null, 5);
        $validados = fetchPartidosParejaUsuario($db, (int) $parejaInfo['id'], 'validado', 5);
        foreach (array_reverse($validados) as $p) {
            $miPareja = (int) $parejaInfo['id'];
            $gano = (int) ($p['ganador_pareja_id'] ?? 0) === $miPareja;
            $ultimos_partidos[] = ['gano' => $gano];
        }
    }
    $invitaciones_pareja = fetchInvitacionesParejaPendientes($db, $usuario_id);
} elseif ($legacyLiga) {
    $stmt = $db->prepare(
        "SELECT u.grupo_id, c.partidos_jugados, c.partidos_ganados, c.puntos
         FROM usuarios u LEFT JOIN clasificacion c ON u.id = c.usuario_id WHERE u.id = :id"
    );
    $stmt->execute(['id' => $usuario_id]);
    $extra = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($extra) {
        $jugador = array_merge($jugador, $extra);
    }
    if (!empty($jugador['grupo_id'])) {
        $g = $db->prepare('SELECT nombre FROM grupos WHERE id = :id');
        $g->execute(['id' => $jugador['grupo_id']]);
        $grupo_nombre = $g->fetchColumn() ?: $grupo_nombre;
    }
    $stmt_p = $db->prepare(
        "SELECT p.id, p.fecha_registro, p.resultado, p.reportado_por, p.jugador1_id, p.jugador2_id,
                u1.nombre AS g_nombre, u1.apellidos AS g_apellidos,
                u2.nombre AS p_nombre, u2.apellidos AS p_apellidos,
                TIMESTAMPDIFF(SECOND, p.fecha_registro, NOW()) AS segundos_pasados
         FROM partidos p
         JOIN usuarios u1 ON p.jugador1_id = u1.id JOIN usuarios u2 ON p.jugador2_id = u2.id
         WHERE (p.jugador1_id = :u1 OR p.jugador2_id = :u2) AND p.estado = 'pendiente'"
    );
    $stmt_p->execute(['u1' => $usuario_id, 'u2' => $usuario_id]);
    $pendientes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    $stmt_r = $db->prepare(
        "SELECT jugador1_id FROM partidos
         WHERE (jugador1_id = :u1 OR jugador2_id = :u2) AND estado = 'validado'
         ORDER BY fecha DESC LIMIT 5"
    );
    $stmt_r->execute(['u1' => $usuario_id, 'u2' => $usuario_id]);
    foreach (array_reverse($stmt_r->fetchAll(PDO::FETCH_ASSOC)) as $partido) {
        $ultimos_partidos[] = ['gano' => (int) $partido['jugador1_id'] === $usuario_id];
    }
}

$acciones_requeridas = count($pendientes);
$total_notificaciones = $acciones_requeridas + count($invitaciones_pareja);
$pj = (int) ($jugador['partidos_jugados'] ?? 0);
$pg = (int) ($jugador['partidos_ganados'] ?? 0);
$win_rate = $pj > 0 ? round(($pg / $pj) * 100) : 0;
usuarioLayoutStart('Mi Panel', $total_notificaciones);
usuarioPageHeading('Hola, ' . ($jugador['nombre'] ?? ''));

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'aceptado') {
        usuarioAlert('Partido validado. Clasificación actualizada.', 'success');
    } elseif ($_GET['msg'] === 'rechazado') {
        usuarioAlert('Resultado rechazado. El admin lo revisará.', 'error');
    }
}
?>

<?php if (!empty($parejaInfo['nota_admin'])): ?>
    <?php usuarioAlert('<strong>Mensaje del administrador (pareja)</strong><br>' . nl2br(htmlspecialchars((string) $parejaInfo['nota_admin'])), 'info', false); ?>
<?php endif; ?>

<?php if ($invitaciones_pareja !== []): ?>
    <?php usuarioCardOpen('Invitaciones de pareja', 'success'); ?>
        <?php foreach ($invitaciones_pareja as $inv): ?>
            <p><?= htmlspecialchars($inv['nombre'] . ' ' . $inv['apellidos']) ?> quiere jugar contigo.</p>
        <?php endforeach; ?>
        <a href="pareja.php" class="btn btn-primary">Gestionar invitaciones</a>
    <?php usuarioCardClose(); ?>
<?php endif; ?>

<?php if (!$parejaInfo && $esquemaPadel && ($_SESSION['tipo_perfil'] ?? 'liga') === 'liga'): ?>
    <?php usuarioCardOpen('', 'warning'); ?>
        <p>Aún no tienes pareja activa. Necesitas una para competir en liga.</p>
                <a href="pareja.php" class="btn btn-primary">Formar pareja</a>
            <?php usuarioCardClose(); ?>
        <?php endif; ?>

        <?php if ($parejaInfo && $esquemaPadel && !$contextoLiga && ($_SESSION['tipo_perfil'] ?? 'liga') === 'liga'): ?>
            <?php usuarioCardOpen('', 'info'); ?>
                <p>Tu pareja aún no está inscrita en ninguna liga confirmada.</p>
                <a href="ligas.php" class="btn btn-primary">Ver ligas e inscribirse</a>
            <?php usuarioCardClose(); ?>
        <?php endif; ?>

<?php foreach ($pendientes as $alerta): ?>
    <?php
    $miP = (int) ($parejaInfo['id'] ?? 0);
    $infoVal = $esquemaPadel && $miP > 0
        ? partidoMensajeValidacionParaPareja($db, $alerta, $miP)
        : null;
    $cardAccent = ($infoVal && $infoVal['es_victoria']) ? 'success' : 'warning';
    ?>
    <?php usuarioCardOpen($infoVal ? $infoVal['titulo'] : 'Resultado pendiente de validar', $cardAccent); ?>
        <?php if ($esquemaPadel && $infoVal): ?>
            <?php partidoRenderAlertaValidacionRival($db, $alerta, $miP, false); ?>
            <div class="card-actions">
                <form method="POST" style="flex:1;display:flex;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion_partido" value="aceptar">
                    <input type="hidden" name="id_partido" value="<?= (int) $alerta['id'] ?>">
                    <button type="submit" class="btn btn-primary" style="width:100%;" onclick="return confirm('¿Confirmar resultado?');">Aceptar</button>
                </form>
                <button type="button" class="btn btn-danger" style="flex:1;" onclick="rechazarPartido(<?= (int) $alerta['id'] ?>)">Rechazar</button>
            </div>
        <?php else: ?>
            <?php
            $op = ((int) $alerta['jugador1_id'] === $usuario_id)
                ? $alerta['p_nombre'] . ' ' . $alerta['p_apellidos']
                : $alerta['g_nombre'] . ' ' . $alerta['g_apellidos'];
            ?>
            <p>Rival: <?= htmlspecialchars($op) ?> — <?= htmlspecialchars($alerta['resultado']) ?></p>
            <div class="card-actions">
                <form method="POST" style="flex:1;display:flex;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion_partido" value="aceptar">
                    <input type="hidden" name="id_partido" value="<?= (int) $alerta['id'] ?>">
                    <button type="submit" class="btn btn-primary" style="width:100%;">Aceptar</button>
                </form>
                <button type="button" class="btn btn-danger" style="flex:1;" onclick="rechazarPartido(<?= (int) $alerta['id'] ?>)">Rechazar</button>
            </div>
        <?php endif; ?>
    <?php usuarioCardClose(); ?>
<?php endforeach; ?>

        <div class="dashboard-cards">
            <div class="card usuario-card">
                <h3 class="card-title">Mi ficha</h3>
                <p><strong><?= htmlspecialchars(($jugador['nombre'] ?? '') . ' ' . ($jugador['apellidos'] ?? '')) ?></strong></p>
                <?php if ($parejaInfo): ?>
                    <p><strong>Pareja:</strong> <?= padelHtmlEtiquetaPareja($parejaInfo['etiqueta']) ?>
                        <a href="pareja.php" style="font-size:0.85rem;">(gestionar)</a></p>
                    <p><strong>Liga / grupo:</strong> <?= htmlspecialchars($grupo_nombre) ?></p>
                    <?php if ($clasif): ?>
                        <p><strong>Posición:</strong> #<?= (int) $clasif['posicion'] ?> · <strong><?= (int) $clasif['puntos'] ?></strong> pts</p>
                    <?php else: ?>
                        <p class="text-muted-inline">Sin fila en clasificación aún (juega un partido oficial).</p>
                    <?php endif; ?>
                <?php elseif (tipoPerfilEsPull($_SESSION['tipo_perfil'] ?? null)): ?>
                    <p>Perfil Pull — inscripción individual. <a href="pulls.php">Ver Pulls abiertas</a> · <a href="mis_pulls.php">Mis Pulls</a></p>
                <?php endif; ?>
            </div>
            <?php if ($parejaInfo && ($_SESSION['tipo_perfil'] ?? 'liga') === 'liga'): ?>
                <div class="card usuario-card">
                    <h3 class="card-title">Rendimiento</h3>
                    <?php if ($pj > 0): ?>
                        <p>Victorias: <strong><?= $win_rate ?>%</strong></p>
                        <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $win_rate ?>%;"></div></div>
                        <p style="margin-top:1rem;font-weight:bold;">Racha (5):</p>
                        <?php foreach ($ultimos_partidos as $r): ?>
                            <span class="racha-badge <?= $r['gano'] ? 'racha-v' : 'racha-d' ?>"><?= $r['gano'] ? 'V' : 'D' ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted-inline">Sin partidos validados.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($parejaInfo && $proximos !== []): ?>
            <div class="card usuario-card">
                <h3 class="card-title">Actividad reciente (pareja)</h3>
                <div class="table-responsive">
                <table class="<?= padelLeagueTableClass() ?>">
                    <thead><tr><th>Fecha</th><th class="player-name">Enfrentamiento</th><th>Estado</th><th>Resultado</th></tr></thead>
                    <tbody>
                    <?php foreach ($proximos as $p): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                            <td class="col-pareja"><?= padelHtmlEnfrentamientoParejas($p['etiqueta_a'], $p['etiqueta_b']) ?></td>
                            <td><?= htmlspecialchars($p['estado']) ?></td>
                            <td><?= htmlspecialchars($p['resultado'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

<?php
$dashScript = '(function(){function tickCountdowns(){document.querySelectorAll(".partido-auto-val-countdown[data-deadline]").forEach(function(el){var d=parseInt(el.getAttribute("data-deadline"),10);if(!d)return;var rest=Math.max(0,d-Math.floor(Date.now()/1000));var h=Math.floor(rest/3600),m=Math.floor((rest%3600)/60),s=rest%60,p=function(n){return n<10?"0"+n:""+n;};el.textContent="Quedan "+p(h)+":"+p(m)+":"+p(s)+" para la validación automática";});}tickCountdowns();setInterval(tickCountdowns,1000);})();'
    . "\nconst csrfDash = " . json_encode($csrf) . ';
function rechazarPartido(id) {
    const motivo = prompt("Motivo del rechazo:");
    if (motivo === null || motivo.trim() === "") return;
    const f = document.createElement("form");
    f.method = "POST";
    f.action = "dashboard.php";
    [["csrf_token", csrfDash], ["accion_partido", "rechazar"], ["id_partido", String(id)], ["motivo", motivo.trim()]].forEach(([n,v]) => {
        const i = document.createElement("input"); i.type="hidden"; i.name=n; i.value=v; f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
}';
usuarioLayoutEnd($dashScript);
