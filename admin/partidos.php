<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/league_points.php';
require_once '../includes/padel_league_points.php';
require_once '../includes/match_service.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/liga_service.php';

requireAdmin();
$db = getDBConnection();
$legacyTenis = legacyLeagueSchemaAvailable($db);
$esquemaPadel = padelCompeticionesSchemaAvailable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
}

if ($esquemaPadel && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_partido_padel'])) {
    $ligaGrupo = explode('|', (string) ($_POST['liga_grupo'] ?? ''), 2);
    $compId = (int) ($ligaGrupo[0] ?? 0);
    $grupoId = isset($ligaGrupo[1]) && $ligaGrupo[1] !== '' ? (int) $ligaGrupo[1] : null;
    $parejaA = (int) ($_POST['pareja_a_id'] ?? 0);
    $parejaB = (int) ($_POST['pareja_b_id'] ?? 0);
    $ganadorId = (int) ($_POST['ganador_pareja_id'] ?? 0);
    $fecha = $_POST['fecha'] ?? '';
    $hora = $_POST['hora'] ?? '12:00';
    $resultado = trim((string) ($_POST['resultado'] ?? ''));

    if ($parejaA === $parejaB || $ganadorId <= 0 || $compId <= 0) {
        header('Location: partidos.php?status=error');
        exit;
    }
    if ($ganadorId !== $parejaA && $ganadorId !== $parejaB) {
        header('Location: partidos.php?status=error');
        exit;
    }

    try {
        leagueMatchService($db)->registrarPartidoAdminValidado(
            $compId,
            $grupoId,
            $parejaA,
            $parejaB,
            $ganadorId,
            $fecha,
            $hora,
            $resultado
        );
        header('Location: partidos.php?status=success');
        exit;
    } catch (Throwable $e) {
        header('Location: partidos.php?status=error&action=db_error');
        exit;
    }
}

if ($esquemaPadel && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_partido_action'])) {
    $action = $_POST['admin_partido_action'];
    $idPartido = (int) ($_POST['id_partido'] ?? 0);

    $matches = leagueMatchService($db);
    if ($action === 'validar' && $idPartido > 0) {
        try {
            $matches->adminValidarPartidoPendiente($idPartido);
            header('Location: partidos.php?status=success_validar');
            exit;
        } catch (Throwable $e) {
            header('Location: partidos.php?status=error');
            exit;
        }
    }

    if ($action === 'eliminar' && $idPartido > 0) {
        try {
            $matches->eliminarPartido($idPartido);
            header('Location: partidos.php?status=success_delete');
            exit;
        } catch (Throwable $e) {
            header('Location: partidos.php?status=error_delete');
            exit;
        }
    }

    if ($action === 'editar' && $idPartido > 0) {
        $nuevo = trim((string) ($_POST['resultado'] ?? ''));
        if ($nuevo !== '') {
            try {
                $matches->reemplazarSetsTexto($idPartido, $nuevo);
                header('Location: partidos.php?status=success_edit');
                exit;
            } catch (Throwable $e) {
                header('Location: partidos.php?status=error_edit');
                exit;
            }
        }
    }
}

if ($legacyTenis && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_partido'])) {
    $grupo_id = $_POST['grupo_id'];
    $ganador_id = $_POST['ganador_id'];
    $perdedor_id = $_POST['perdedor_id'];
    $fecha = $_POST['fecha'];
    $hora = $_POST['hora'];
    $resultado = htmlspecialchars(trim($_POST['resultado']));
    if ($ganador_id === $perdedor_id) {
        header('Location: partidos.php?status=error&action=mismo_jugador');
        exit;
    }
    try {
        $db->beginTransaction();
        $sql_partido = "INSERT INTO partidos (jugador1_id, jugador2_id, grupo_id, fecha, hora, resultado, estado) 
                        VALUES (:j1, :j2, :grupo, :fecha, :hora, :resultado, 'validado')";
        $db->prepare($sql_partido)->execute([
            'j1' => $ganador_id, 'j2' => $perdedor_id, 'grupo' => $grupo_id,
            'fecha' => $fecha, 'hora' => $hora, 'resultado' => $resultado,
        ]);
        leagueApplyValidatedMatchPoints($db, (int) $ganador_id, (int) $perdedor_id);
        $db->commit();
        header('Location: partidos.php?status=success');
        exit;
    } catch (PDOException $e) {
        $db->rollBack();
        header('Location: partidos.php?status=error&action=db_error');
        exit;
    }
}

if ($legacyTenis && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_partido_action'])) {
    $action = $_POST['admin_partido_action'];
    $id_partido = (int) ($_POST['id_partido'] ?? 0);
    if ($action === 'validar' && $id_partido > 0) {
        $stmt_info = $db->prepare('SELECT jugador1_id, jugador2_id, estado FROM partidos WHERE id = :id');
        $stmt_info->execute(['id' => $id_partido]);
        $partido_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        if ($partido_info && in_array($partido_info['estado'], ['pendiente', 'rechazado'], true)) {
            $db->beginTransaction();
            $db->prepare("UPDATE partidos SET estado = 'validado' WHERE id = :id")->execute(['id' => $id_partido]);
            leagueApplyValidatedMatchPoints($db, (int) $partido_info['jugador1_id'], (int) $partido_info['jugador2_id']);
            $db->commit();
            header('Location: partidos.php?status=success_validar');
            exit;
        }
    }
    if ($action === 'eliminar' && $id_partido > 0) {
        $stmt_info = $db->prepare('SELECT jugador1_id, jugador2_id, estado FROM partidos WHERE id = :id');
        $stmt_info->execute(['id' => $id_partido]);
        $partido_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        if ($partido_info) {
            $db->beginTransaction();
            if ($partido_info['estado'] === 'validado') {
                leagueRevertValidatedMatchPoints($db, (int) $partido_info['jugador1_id'], (int) $partido_info['jugador2_id']);
            }
            $db->prepare('DELETE FROM partidos WHERE id = :id')->execute(['id' => $id_partido]);
            $db->commit();
            header('Location: partidos.php?status=success_delete');
            exit;
        }
    }
    if ($action === 'editar' && $id_partido > 0) {
        $nuevo_resultado = htmlspecialchars(trim((string) ($_POST['resultado'] ?? '')));
        if ($nuevo_resultado !== '') {
            $db->prepare('UPDATE partidos SET resultado = :resultado WHERE id = :id')->execute(['resultado' => $nuevo_resultado, 'id' => $id_partido]);
            header('Location: partidos.php?status=success_edit');
            exit;
        }
    }
}

$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') {
        $mensaje = 'Partido registrado con éxito.';
    } elseif ($_GET['status'] === 'success_validar') {
        $mensaje = 'Partido validado. Clasificación actualizada.';
    } elseif ($_GET['status'] === 'success_delete') {
        $mensaje = 'Partido eliminado correctamente.';
    } elseif ($_GET['status'] === 'success_edit') {
        $mensaje = 'Marcador actualizado.';
    } else {
        $mensaje = 'Error al procesar la solicitud.';
    }
    $tipo_mensaje = str_starts_with((string) $_GET['status'], 'success') ? 'success' : 'error';
}

$rechazados = [];
$pendientes_admin = [];
$historial_partidos = [];
$grupos = [];
$jugadores = [];
$ligasGrupos = [];
$parejasSelect = [];

if ($esquemaPadel) {
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $sqlBase = "SELECT p.id, p.fecha, p.fecha_programada, p.estado, p.motivo_rechazo, p.ganador_pareja_id, p.pareja_a_id, p.pareja_b_id,
                c.nombre AS comp_nombre, g.nombre AS grupo_nombre,
                ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
                FROM partidos p
                INNER JOIN competiciones c ON c.id = p.competicion_id
                LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
                INNER JOIN parejas pa ON pa.id = p.pareja_a_id
                INNER JOIN parejas pb ON pb.id = p.pareja_b_id";

    $rechazados = $db->query($sqlBase . " WHERE p.estado = 'rechazado' ORDER BY p.fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
    $pendientes_admin = $db->query(
        $sqlBase . " WHERE p.estado IN ('pendiente','incidencia') ORDER BY COALESCE(p.fecha_programada, p.fecha) ASC, p.id ASC LIMIT 80"
    )->fetchAll(PDO::FETCH_ASSOC);
    $historial_partidos = $db->query($sqlBase . " WHERE p.estado = 'validado' ORDER BY p.fecha DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendientes_admin as &$row) {
        $row['resultado'] = padelFormatSetsForPartido($db, (int) $row['id']);
    }
    unset($row);
    foreach ($rechazados as &$row) {
        $row['resultado'] = padelFormatSetsForPartido($db, (int) $row['id']);
    }
    unset($row);
    foreach ($historial_partidos as &$row) {
        $row['resultado'] = padelFormatSetsForPartido($db, (int) $row['id']);
        $row['nombre_grupo'] = ($row['comp_nombre'] ?? '') . ($row['grupo_nombre'] ? ' · ' . $row['grupo_nombre'] : '');
    }
    unset($row);

    $ligasGrupos = $db->query(
        "SELECT c.id AS comp_id, c.nombre AS comp_nombre, g.id AS grupo_id, g.nombre AS grupo_nombre
         FROM competiciones c
         INNER JOIN competicion_grupos g ON g.competicion_id = c.id
         WHERE c.tipo = 'liga'
         ORDER BY c.nombre, g.orden, g.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
    $parejasSelect = fetchParejasActivasAdmin($db);
} elseif ($legacyTenis) {
    $grupos = $db->query('SELECT * FROM grupos ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $jugadores = $db->query("SELECT id, nombre, apellidos, grupo_id FROM usuarios WHERE rol = 'usuario' AND grupo_id IS NOT NULL ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $sql_rechazados = "SELECT p.id, p.fecha, p.resultado, p.motivo_rechazo, g.nombre AS nombre_grupo, 
                       u1.nombre AS ganador_nombre, u1.apellidos AS ganador_apellidos,
                       u2.nombre AS perdedor_nombre, u2.apellidos AS perdedor_apellidos
                       FROM partidos p JOIN grupos g ON p.grupo_id = g.id
                       JOIN usuarios u1 ON p.jugador1_id = u1.id JOIN usuarios u2 ON p.jugador2_id = u2.id
                       WHERE p.estado = 'rechazado' ORDER BY p.fecha DESC";
    $rechazados = $db->query($sql_rechazados)->fetchAll(PDO::FETCH_ASSOC);
    $sql_validados = "SELECT p.id, p.fecha, p.resultado, g.nombre AS nombre_grupo, 
                      u1.nombre AS ganador_nombre, u1.apellidos AS ganador_apellidos,
                      u2.nombre AS perdedor_nombre, u2.apellidos AS perdedor_apellidos
                      FROM partidos p JOIN grupos g ON p.grupo_id = g.id
                      JOIN usuarios u1 ON p.jugador1_id = u1.id JOIN usuarios u2 ON p.jugador2_id = u2.id
                      WHERE p.estado = 'validado' ORDER BY p.fecha DESC LIMIT 20";
    $historial_partidos = $db->query($sql_validados)->fetchAll(PDO::FETCH_ASSOC);
}

$adminCsrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php renderAdminPageHead('Partidos'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
        const adminCsrf = <?= json_encode($adminCsrf) ?>;
        function adminPartidoPost(action, idPartido, extraFields = {}) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'partidos.php';
            const fields = { csrf_token: adminCsrf, admin_partido_action: action, id_partido: String(idPartido), ...extraFields };
            for (const [name, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }
        function editarResultado(idPartido, resultadoActual) {
            const nuevo = prompt('Marcador (sets, ej: 6-4, 6-3):', resultadoActual);
            if (nuevo !== null && nuevo.trim() !== '' && nuevo !== resultadoActual) {
                adminPartidoPost('editar', idPartido, { resultado: nuevo.trim() });
            }
        }
        <?php if ($legacyTenis): ?>
        function filtrarJugadores() {
            const grupoSeleccionado = document.getElementById('grupo_id').value;
            ['ganador_id', 'perdedor_id'].forEach(id => {
                const select = document.getElementById(id);
                for (let i = 1; i < select.options.length; i++) {
                    const g = select.options[i].getAttribute('data-grupo');
                    select.options[i].style.display = (grupoSeleccionado === '' || grupoSeleccionado === g) ? '' : 'none';
                }
                select.value = '';
            });
        }
        <?php endif; ?>
        function syncGanadorPadel() {
            const a = document.getElementById('pareja_a_id');
            const b = document.getElementById('pareja_b_id');
            const g = document.getElementById('ganador_pareja_id');
            if (!a || !b || !g) return;
            g.innerHTML = '';
            if (a.value) g.add(new Option(a.options[a.selectedIndex].text, a.value));
            if (b.value) g.add(new Option(b.options[b.selectedIndex].text, b.value));
        }
    </script>
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('partidos'); ?>
    <main class="main-content">
        <h1>Gestión de partidos</h1>

        <?php if (!$esquemaPadel && !$legacyTenis): ?>
            <div class="alert alert-error">No hay esquema de partidos configurado.</div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
            <div class="alert <?= $tipo_mensaje === 'error' ? 'alert-error' : '' ?>" style="<?= $tipo_mensaje === 'success' ? 'background:#d4edda;color:#155724;' : '' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pendientes_admin)): ?>
            <div class="card" style="margin-top:1rem;border-left:5px solid #0d6efd;">
                <h3>Calendario y pendientes (control total)</h3>
                <p style="color:#666;font-size:0.9rem;">Edita fechas del sorteo, marcadores, ganador y estado. Enlace «Gestionar» abre el editor completo.</p>
                <div class="table-responsive">
                <table class="<?= padelLeagueTableClass() ?>">
                    <thead><tr><th>Prog.</th><th>Estado</th><th>Enfrentamiento</th><th>Resultado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pendientes_admin as $partido): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime(ligaPartidoFechaProgramadaEfectiva($partido))) ?></td>
                            <td><?= htmlspecialchars($partido['estado']) ?></td>
                            <td class="col-pareja"><?= padelHtmlEnfrentamientoParejas($partido['etiqueta_a'], $partido['etiqueta_b']) ?></td>
                            <td><?= htmlspecialchars($partido['resultado'] ?: '—') ?></td>
                            <td><a href="editar_partido.php?id=<?= (int) $partido['id'] ?>" class="btn btn-primary" style="padding:4px 10px;font-size:0.85rem;">Gestionar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($rechazados) > 0): ?>
            <div class="card" style="margin-top:1rem;border-left:5px solid #dc3545;">
                <h3>Partidos en disputa</h3>
                <div class="table-responsive">
                <table class="<?= padelLeagueTableClass() ?>">
                    <thead><tr><th>Fecha</th><th>Competición</th><th class="player-name">Pareja A</th><th>Resultado</th><th class="player-name">Pareja B</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($rechazados as $partido): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($partido['fecha'])) ?></td>
                            <td><?= htmlspecialchars($esquemaPadel ? (($partido['comp_nombre'] ?? '') . ($partido['grupo_nombre'] ? ' · ' . $partido['grupo_nombre'] : '')) : ($partido['nombre_grupo'] ?? '')) ?></td>
                            <?php if ($esquemaPadel): ?>
                                <td class="col-pareja"><?= padelHtmlEtiquetaPareja($partido['etiqueta_a']) ?></td>
                                <td><?= htmlspecialchars($partido['resultado']) ?><br><small><?= htmlspecialchars($partido['motivo_rechazo'] ?: '') ?></small></td>
                                <td class="col-pareja"><?= padelHtmlEtiquetaPareja($partido['etiqueta_b']) ?></td>
                            <?php else: ?>
                                <td><?= htmlspecialchars($partido['ganador_nombre']) ?></td>
                                <td><?= htmlspecialchars($partido['resultado']) ?></td>
                                <td><?= htmlspecialchars($partido['perdedor_nombre']) ?></td>
                            <?php endif; ?>
                            <td>
                                <button type="button" class="btn" onclick="if(confirm('¿Validar?')) adminPartidoPost('validar', <?= (int) $partido['id'] ?>);">Validar</button>
                                <a href="editar_partido.php?id=<?= (int) $partido['id'] ?>" class="btn btn-secondary" style="padding:4px 8px;">Gestionar</a>
                                <button type="button" class="btn" onclick="if(confirm('¿Eliminar?')) adminPartidoPost('eliminar', <?= (int) $partido['id'] ?>);">Borrar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:2rem;margin-top:2rem;flex-wrap:wrap;">
            <div class="card" style="flex:1;min-width:300px;">
                <h3>Registrar partido (admin)</h3>
                <?php if ($esquemaPadel): ?>
                    <?php if ($ligasGrupos === []): ?>
                        <p>Crea una <a href="grupos.php">liga y grupos</a> antes de registrar partidos.</p>
                    <?php elseif ($parejasSelect === []): ?>
                        <p>Crea <a href="parejas.php">parejas activas</a> primero.</p>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrf) ?>">
                            <input type="hidden" name="registrar_partido_padel" value="1">
                            <select name="liga_grupo" required style="width:100%;padding:10px;margin-bottom:10px;">
                                <option value="">Liga / grupo…</option>
                                <?php foreach ($ligasGrupos as $lg): ?>
                                    <option value="<?= (int) $lg['comp_id'] ?>|<?= (int) $lg['grupo_id'] ?>">
                                        <?= htmlspecialchars($lg['comp_nombre'] . ' — ' . $lg['grupo_nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="pareja_a_id" name="pareja_a_id" required style="width:100%;padding:10px;" onchange="syncGanadorPadel()">
                                <option value="">Pareja A…</option>
                                <?php foreach ($parejasSelect as $pr): ?>
                                    <option value="<?= (int) $pr['id'] ?>"><?= htmlspecialchars($pr['etiqueta']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="pareja_b_id" name="pareja_b_id" required style="width:100%;padding:10px;margin:10px 0;" onchange="syncGanadorPadel()">
                                <option value="">Pareja B…</option>
                                <?php foreach ($parejasSelect as $pr): ?>
                                    <option value="<?= (int) $pr['id'] ?>"><?= htmlspecialchars($pr['etiqueta']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="ganador_pareja_id" name="ganador_pareja_id" required style="width:100%;padding:10px;margin-bottom:10px;">
                                <option value="">Ganador…</option>
                            </select>
                            <input type="date" name="fecha" required style="width:100%;margin-bottom:10px;">
                            <input type="time" name="hora" value="12:00" required style="width:100%;margin-bottom:10px;">
                            <input type="text" name="resultado" placeholder="6-4, 6-3" required style="width:100%;margin-bottom:10px;">
                            <button type="submit" class="btn btn-primary">Guardar oficial</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($legacyTenis): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCsrf) ?>">
                        <select id="grupo_id" name="grupo_id" required style="width:100%;padding:10px;" onchange="filtrarJugadores()">
                            <option value="">Categoría…</option>
                            <?php foreach ($grupos as $grupo): ?>
                                <option value="<?= $grupo['id'] ?>"><?= htmlspecialchars($grupo['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="ganador_id" name="ganador_id" required style="width:100%;padding:10px;margin:10px 0;">
                            <option value="">Ganador…</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?= $j['id'] ?>" data-grupo="<?= $j['grupo_id'] ?>"><?= htmlspecialchars($j['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="perdedor_id" name="perdedor_id" required style="width:100%;padding:10px;margin-bottom:10px;">
                            <option value="">Perdedor…</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?= $j['id'] ?>" data-grupo="<?= $j['grupo_id'] ?>"><?= htmlspecialchars($j['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="fecha" required>
                        <input type="time" name="hora" required>
                        <input type="text" name="resultado" required placeholder="6-4, 7-5">
                        <button type="submit" name="registrar_partido" class="btn btn-primary">Guardar</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card" style="flex:1.5;min-width:400px;">
                <h3>Historial validados</h3>
                <div class="table-responsive">
                <table class="<?= padelLeagueTableClass() ?>">
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Grupo</th>
                        <th><?= $esquemaPadel ? 'Enfrentamiento' : 'Ganador / Perdedor' ?></th>
                        <th>Resultado</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historial_partidos as $partido): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($partido['fecha'])) ?></td>
                            <td><?= htmlspecialchars($esquemaPadel ? ($partido['nombre_grupo'] ?? '') : ($partido['nombre_grupo'] ?? '')) ?></td>
                            <td>
                                <?php if ($esquemaPadel): ?>
                                    <?= padelHtmlEnfrentamientoParejas($partido['etiqueta_a'], $partido['etiqueta_b']) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($partido['ganador_nombre']) ?> vs <?= htmlspecialchars($partido['perdedor_nombre']) ?>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($partido['resultado']) ?></strong></td>
                            <td>
                                <a href="editar_partido.php?id=<?= (int) $partido['id'] ?>" class="btn btn-secondary" style="padding:4px 8px;font-size:0.85rem;">Gestionar</a>
                                <button type="button" onclick="if(confirm('¿Anular?')) adminPartidoPost('eliminar', <?= (int) $partido['id'] ?>);">Anular</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($historial_partidos === []): ?>
                        <tr><td colspan="5">Sin partidos validados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
