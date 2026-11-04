<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/padel_league_points.php';
require_once '../includes/partido_sets_validate.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/usuario_padel_helpers.php';
require_once '../includes/liga_service.php';
require_once '../includes/padel_guardrails.php';
require_once '../includes/partido_flujo_usuario.php';
require_once '../includes/match_service.php';
require_once '../includes/notificaciones_usuario.php';
require_once '../includes/partido_validacion_mensajes.php';
require_once '../includes/usuario_layout.php';

requireLogin();

$db = getDBConnection();
$usuario_id = (int) $_SESSION['usuario_id'];
$esquemaPadel = usuarioEsquemaPadel($db);
$csrf = generateCSRFToken();

if ($esquemaPadel) {
    padelAutoValidarPartidosCaducados($db);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_partido'], $_POST['id_partido'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Error CSRF.');
    }
    $id = (int) $_POST['id_partido'];
    $accion = (string) $_POST['accion_partido'];
    if ($esquemaPadel) {
        if (in_array($accion, ['disputado_si', 'no_disputado'], true)) {
            if (padelProcesarAccionFlujoPartido($db, $usuario_id, $id, $accion)) {
                $q = $accion === 'disputado_si' ? 'disputado_si' : 'incidencia';
                header('Location: partidos.php?msg=' . $q . '#partido-' . $id);
                exit;
            }
        } elseif (in_array($accion, ['aceptar', 'rechazar'], true)) {
            $motivo = isset($_POST['motivo']) ? trim((string) $_POST['motivo']) : null;
            if (padelProcesarAccionPartidoUsuario($db, $usuario_id, $id, $accion, $motivo)) {
                header('Location: partidos.php?msg=' . ($accion === 'aceptar' ? 'aceptado' : 'rechazado'));
                exit;
            }
        }
    }
    header('Location: partidos.php?status=error');
    exit;
}

if ($esquemaPadel && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_resultado_padel'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Error CSRF.');
    }
    $partidoId = (int) ($_POST['partido_id'] ?? 0);
    $parejaId = fetchParejaIdUsuario($db, $usuario_id);
    $quienGano = $_POST['quien_gano'] ?? '';
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    if (!$parejaId || $partidoId <= 0) {
        header('Location: partidos.php?status=error');
        exit;
    }
    $stP = $db->prepare('SELECT * FROM partidos WHERE id = :id LIMIT 1');
    $stP->execute(['id' => $partidoId]);
    $fixRow = $stP->fetch(PDO::FETCH_ASSOC);
    if (!$fixRow || !usuarioEnPartido($db, $partidoId, $usuario_id)) {
        header('Location: partidos.php?status=error');
        exit;
    }
    $rivalId = (int) $fixRow['pareja_a_id'] === $parejaId ? (int) $fixRow['pareja_b_id'] : (int) $fixRow['pareja_a_id'];
    if (partidoFlujoEstadoUsuario($db, $fixRow, $parejaId) !== 'formulario_reporte') {
        header('Location: partidos.php?status=error_disputa');
        exit;
    }
    $contexto = fetchContextoLigaPareja($db, $parejaId);
    if (!$contexto) {
        header('Location: partidos.php?status=error_grupo');
        exit;
    }
    try {
        $sets = partidoSetsFromForm($_POST, 'a');
        if ($sets === [] && !isset($_POST['retirada'])) {
            throw new InvalidArgumentException('Indica sets.');
        }
        if ($sets !== []) {
            $winsA = 0;
            $winsB = 0;
            foreach ($sets as $s) {
                if ($s['juegos_a'] > $s['juegos_b']) {
                    $winsA++;
                } else {
                    $winsB++;
                }
            }
            $calc = $winsA > $winsB ? 'yo' : 'oponente';
            if (!isset($_POST['retirada']) && $calc !== $quienGano) {
                header('Location: partidos.php?status=error_coherencia');
                exit;
            }
        }
        $ganadorPareja = ($quienGano === 'yo') ? $parejaId : $rivalId;
        $compId = (int) $contexto['competicion_id'];
        $grupoId = (int) $contexto['grupo_id'];
        leagueMatchService($db)->reportarResultadoUsuario(
            $partidoId,
            $usuario_id,
            $parejaId,
            $ganadorPareja,
            $fecha,
            $sets
        );
        notificacionesAvisarRivalResultadoPendiente($db, $partidoId, $parejaId);
        header('Location: partidos.php?status=success');
        exit;
    } catch (InvalidArgumentException $e) {
        $code = $e->getMessage();
        if (str_starts_with($code, 'error_')) {
            header('Location: partidos.php?status=' . $code);
            exit;
        }
        header('Location: partidos.php?status=error_formato');
        exit;
    } catch (Throwable $e) {
        header('Location: partidos.php?status=error_formato');
        exit;
    }
}

$partidosFlujo = [];
$historial = [];
$parejaId = null;
$contexto = null;
$acciones_requeridas = 0;
$total_notificaciones = 0;

if ($esquemaPadel) {
    $parejaId = fetchParejaIdUsuario($db, $usuario_id);
    if ($parejaId) {
        $contexto = fetchContextoLigaPareja($db, $parejaId);
        if ($contexto) {
            $partidosFlujo = ligaFetchPartidosFlujoParejaGrupo(
                $db,
                $parejaId,
                (int) $contexto['competicion_id'],
                (int) $contexto['grupo_id']
            );
            foreach ($partidosFlujo as $p) {
                if (padelPartidoPendienteValidacionParaUsuario($db, $p, $usuario_id)) {
                    $acciones_requeridas++;
                }
            }
        }
        $total_notificaciones = $acciones_requeridas;
        $historial = fetchPartidosParejaUsuario($db, $parejaId, 'validado', 30);
    }
}

$alertMsg = '';
$alertVariant = 'info';
if (isset($_GET['msg'])) {
    [$alertVariant, $alertMsg] = match ($_GET['msg']) {
        'aceptado' => ['success', 'Partido validado. Clasificación actualizada.'],
        'rechazado' => ['warning', 'Has rechazado el resultado. La otra pareja puede volver a reportarlo.'],
        'disputado_si' => ['info', 'Indica el marcador del partido en el formulario.'],
        'incidencia' => ['warning', 'Incidencia registrada. El administrador revisará el caso.'],
        default => ['info', ''],
    };
}
if (isset($_GET['status'])) {
    [$alertVariant, $alertMsg] = match ($_GET['status']) {
        'success' => ['success', 'Resultado enviado. Esperando validación del rival.'],
        'error_grupo' => ['warning', 'No hay liga/grupo configurado.'],
        'error_coherencia' => ['error', 'El ganador no coincide con los sets.'],
        'error_formato' => ['error', 'Revisa el formato de los sets (ej. 6-4).'],
        'error_validado' => ['error', 'Este partido ya está validado; no se puede modificar.'],
        'error_ya_reportado' => ['warning', 'Ya hay un resultado pendiente de validación para este cruce.'],
        'error_fecha_futura' => ['warning', 'Aún no puedes interactuar: el partido tiene fecha programada futura.'],
        'error_incidencia' => ['warning', 'Partido en incidencia: no se pueden introducir resultados.'],
        'error_disputa' => ['warning', 'Confirma primero que el partido se ha disputado.'],
        default => ['error', 'No se pudo completar la acción.'],
    };
}

usuarioLayoutStart('Mis partidos', $total_notificaciones);
usuarioPageHeading('Mis partidos');
if ($alertMsg !== '') {
    usuarioAlert($alertMsg, $alertVariant);
}
?>

<?php if (!$esquemaPadel): ?>
    <?php usuarioAlert('Esquema no disponible.', 'warning'); ?>
<?php elseif (!$parejaId): ?>
    <?php usuarioAlert('Forma tu pareja para reportar partidos. <a href="pareja.php">Ir a Mi pareja</a>', 'warning', false); ?>
<?php elseif (!$contexto): ?>
    <?php usuarioAlert('Tu pareja no está inscrita en una liga confirmada. <a href="ligas.php">Ver ligas</a>', 'warning', false); ?>
<?php else: ?>

    <div class="partidos-embudo">
        <?php if ($partidosFlujo === []): ?>
            <?php usuarioCardOpen('Partidos de liga'); ?>
            <p>No tienes partidos de calendario en este grupo.</p>
            <?php usuarioCardClose(); ?>
        <?php endif; ?>

        <?php foreach ($partidosFlujo as $p):
            $pid = (int) $p['id'];
            $rivalNom = (int) $p['pareja_a_id'] === $parejaId ? $p['etiqueta_b'] : $p['etiqueta_a'];
            $fechaProg = ligaPartidoFechaProgramadaEfectiva($p);
            $fmt = date('d/m/Y', strtotime($fechaProg));
            $flujo = partidoFlujoEstadoUsuario($db, $p, $parejaId);
            $titulo = 'vs ' . $rivalNom;
            if (!empty($p['jornada'])) {
                $titulo .= ' · Jornada ' . (int) $p['jornada'];
            }
            usuarioCardOpen($titulo);
            ?>
            <div id="partido-<?= $pid ?>" class="partido-embudo-card" data-estado="<?= htmlspecialchars($flujo) ?>">
                <?php if (!empty($p['nota_admin'])): ?>
                    <div class="alert alert-info" style="margin-bottom:12px;">
                        <strong>Mensaje del administrador</strong><br><?= nl2br(htmlspecialchars((string) $p['nota_admin'])) ?>
                    </div>
                <?php endif; ?>

                <?php if ($flujo === 'futuro'): ?>
                    <p class="usuario-fixture-lock">El partido se juega el <?= htmlspecialchars($fmt) ?>.</p>

                <?php elseif ($flujo === 'pregunta_disputado'): ?>
                    <p class="partido-embudo-pregunta">¿Se ha disputado el partido? <span class="text-muted-inline">(programado el <?= htmlspecialchars($fmt) ?>)</span></p>
                    <div class="card-actions">
                        <form method="POST" class="partido-embudo-form-si" style="flex:1;display:flex;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="accion_partido" value="disputado_si">
                            <input type="hidden" name="id_partido" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-primary" style="width:100%;">Sí</button>
                        </form>
                        <form method="POST" style="flex:1;display:flex;" onsubmit="return confirm('¿Confirmas que NO se ha jugado? Se abrirá una incidencia.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="accion_partido" value="no_disputado">
                            <input type="hidden" name="id_partido" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-secondary" style="width:100%;">No</button>
                        </form>
                    </div>

                <?php elseif ($flujo === 'incidencia'): ?>
                    <p class="usuario-fixture-lock">El administrador ha sido notificado y revisará el caso.</p>

                <?php elseif ($flujo === 'formulario_reporte'): ?>
                    <p class="partido-embudo-pregunta">Introduce el resultado del partido.</p>
                    <form method="POST" class="form-stack partido-form-reporte">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="enviar_resultado_padel" value="1">
                        <input type="hidden" name="partido_id" value="<?= $pid ?>">
                        <select name="quien_gano" required class="input-usuario touch-select">
                            <option value="yo">Ganó nuestra pareja</option>
                            <option value="oponente">Ganó <?= htmlspecialchars($rivalNom) ?></option>
                        </select>
                        <input type="date" name="fecha" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required class="input-usuario">
                        <?php for ($s = 1; $s <= 3; $s++): ?>
                            <div class="set-input-row">
                                <span class="set-label">Set <?= $s ?> (nosotros — rivales)</span>
                                <div class="set-scores">
                                    <select name="set<?= $s ?>_yo" class="touch-select">
                                        <option value="">—</option>
                                        <?php for ($i = 0; $i <= 7; $i++): ?>
                                            <option value="<?= $i ?>"><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span aria-hidden="true">—</span>
                                    <select name="set<?= $s ?>_op" class="touch-select">
                                        <option value="">—</option>
                                        <?php for ($i = 0; $i <= 7; $i++): ?>
                                            <option value="<?= $i ?>"><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endfor; ?>
                        <label><input type="checkbox" name="retirada" value="1"> Retirada</label>
                        <button type="submit" class="btn btn-primary btn-block">Enviar a validación</button>
                    </form>

                <?php elseif ($flujo === 'esperando_validacion'): ?>
                    <p class="usuario-fixture-ok">Esperando validación del rival.</p>
                    <p>Marcador enviado: <strong><?= htmlspecialchars($p['resultado']) ?></strong></p>
                    <?php
                    $segEsp = partidoSegundosHastaAutoValidacion($db, $p);
                    if ($segEsp !== null): ?>
                        <p class="partido-auto-val-countdown text-muted-inline" data-deadline="<?= (int) (time() + $segEsp) ?>" aria-live="polite">
                            <?= htmlspecialchars(partidoFormatearCuentaAtras24h($segEsp)) ?> (validación automática si el rival no responde)
                        </p>
                    <?php endif; ?>

                <?php elseif ($flujo === 'validar_rival'): ?>
                    <?php partidoRenderAlertaValidacionRival($db, $p, $parejaId); ?>
                    <div class="card-actions">
                        <form method="POST" style="flex:1;display:flex;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="accion_partido" value="aceptar">
                            <input type="hidden" name="id_partido" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-primary" style="width:100%;">Aceptar resultado</button>
                        </form>
                        <button type="button" class="btn btn-danger" style="flex:1;" onclick="rechazar(<?= $pid ?>)">Rechazar</button>
                    </div>
                <?php endif; ?>

            </div>
            <?php usuarioCardClose(); ?>
        <?php endforeach; ?>

        <div class="card usuario-card tabla-historial">
            <h3 class="card-title">Historial validados <a href="historial.php" class="link-muted" style="font-size:0.85rem;font-weight:normal;">Ver todo</a></h3>
            <div class="table-responsive">
                <table class="<?= padelLeagueTableClass() ?>">
                    <thead><tr><th>Fecha</th><th class="player-name">Partido</th><th>Resultado</th><th>V/D</th></tr></thead>
                    <tbody>
                    <?php foreach ($historial as $p): ?>
                        <?php $gano = (int) $p['ganador_pareja_id'] === $parejaId; ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                            <td class="col-pareja"><?= padelHtmlEnfrentamientoParejas($p['etiqueta_a'], $p['etiqueta_b']) ?></td>
                            <td><?= htmlspecialchars($p['resultado']) ?></td>
                            <td><?php usuarioBadge($gano ? 'Victoria' : 'Derrota', $gano ? 'success' : 'danger'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($historial === []): ?><tr><td colspan="4">Sin partidos validados.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$partidosScript = '(function(){function tickCountdowns(){document.querySelectorAll(".partido-auto-val-countdown[data-deadline]").forEach(function(el){var d=parseInt(el.getAttribute("data-deadline"),10);if(!d)return;var rest=Math.max(0,d-Math.floor(Date.now()/1000));var h=Math.floor(rest/3600),m=Math.floor((rest%3600)/60),s=rest%60,p=function(n){return n<10?"0"+n:""+n;};el.textContent="Quedan "+p(h)+":"+p(m)+":"+p(s)+" para la validación automática";});}tickCountdowns();setInterval(tickCountdowns,1000);})();'
    . "\nconst csrfP = " . json_encode($csrf) . ';
function rechazar(id) {
    const m = prompt("Motivo del rechazo:");
    if (!m || !m.trim()) return;
    const f = document.createElement("form");
    f.method = "POST";
    f.action = "partidos.php";
    [["csrf_token", csrfP], ["accion_partido", "rechazar"], ["id_partido", String(id)], ["motivo", m.trim()]].forEach(([n,v]) => {
        const i = document.createElement("input"); i.type="hidden"; i.name=n; i.value=v; f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
}
';
usuarioLayoutEnd($partidosScript);
