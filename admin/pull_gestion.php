<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/competiciones_helpers.php';
require_once '../includes/pull_service.php';
require_once '../includes/pull_engine.php';
require_once '../includes/admin_sidebar.php';

requireAdmin();
$db = getDBConnection();

if (!padelCompeticionesSchemaAvailable($db)) {
    die('Requiere esquema pádel.');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: dashboard.php');
    exit;
}

$pull = pullFetchCompeticion($db, $id);
if (!$pull) {
    die('Pull no encontrada.');
}

$mensaje = '';
$error = '';
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'config') {
            $pistas = (int) ($_POST['pull_num_pistas'] ?? 3);
            $cap = (int) ($_POST['pull_capacidad'] ?? 0);
            pullUpdateConfig($db, $id, $pistas, $cap > 0 ? $cap : null);
            $mensaje = 'Configuración de pistas actualizada.';
            $pull = pullFetchCompeticion($db, $id);
        } elseif ($accion === 'generar_ronda') {
            pullGenerarPrimeraRonda($db, $id);
            $mensaje = 'Primera ronda generada. Revisa el cuadrante.';
            $pull = pullFetchCompeticion($db, $id);
        } elseif ($accion === 'resultado') {
            $ronda = pullFetchRondaActual($db, $id);
            if (!$ronda) {
                throw new InvalidArgumentException('No hay ronda en juego.');
            }
            pullGuardarResultadoPista(
                $db,
                (int) $ronda['id'],
                (int) $_POST['pista_numero'],
                $_POST['ganador_equipo'] === 'B' ? 'B' : 'A'
            );
            $mensaje = 'Resultado guardado.';
        } elseif ($accion === 'rotar') {
            pullRotarPistas($db, $id);
            $mensaje = '¡Rotación completada! Nueva ronda en el cuadrante.';
            $pull = pullFetchCompeticion($db, $id);
        } elseif ($accion === 'inscribir_jugador') {
            $uid = (int) ($_POST['usuario_id'] ?? 0);
            if ($uid > 0) {
                inscribirUsuarioEnPull($db, $uid, $id);
                $db->prepare(
                    "UPDATE inscripciones_pull SET estado = 'confirmada' WHERE competicion_id = :cid AND usuario_id = :uid"
                )->execute(['cid' => $id, 'uid' => $uid]);
                $mensaje = 'Jugador inscrito.';
            }
        } elseif ($accion === 'cambiar_estado') {
            $idIns = (int) ($_POST['id_inscripcion'] ?? 0);
            $estado = $_POST['estado'] ?? 'pendiente';
            if (!in_array($estado, ['pendiente', 'confirmada', 'rechazada', 'baja'], true)) {
                $estado = 'pendiente';
            }
            if ($idIns > 0) {
                $db->prepare(
                    'UPDATE inscripciones_pull SET estado = :e WHERE id = :id AND competicion_id = :cid'
                )->execute(['e' => $estado, 'id' => $idIns, 'cid' => $id]);
                $mensaje = 'Estado de inscripción actualizado.';
            }
        } elseif ($accion === 'eliminar_inscripcion') {
            $idIns = (int) ($_POST['id_inscripcion'] ?? 0);
            if ($idIns > 0) {
                $db->prepare('DELETE FROM inscripciones_pull WHERE id = :id AND competicion_id = :cid')
                    ->execute(['id' => $idIns, 'cid' => $id]);
                $mensaje = 'Inscripción eliminada.';
            }
        }
    } catch (Throwable $e) {
        require_once '../includes/app_errors.php';
        $error = appSafeExceptionMessage($e, 'No se pudo completar la operación.');
    }
}

$numPistas = max(1, (int) ($pull['pull_num_pistas'] ?? 3));
$cap = pullCapacidadPorPistas($numPistas, $pull['pull_capacidad_jugadores'] ? (int) $pull['pull_capacidad_jugadores'] : null);
$inscritos = pullCountInscritos($db, $id);
$stmtPend = $db->prepare(
    "SELECT COUNT(*) FROM inscripciones_pull WHERE competicion_id = :cid AND estado = 'pendiente'"
);
$stmtPend->execute(['cid' => $id]);
$pendientesCount = (int) $stmtPend->fetchColumn();
$pct = $cap > 0 ? min(100, (int) round(($inscritos / $cap) * 100)) : 0;

$rondaActual = pullFetchRondaActual($db, $id);
$cuadrante = $rondaActual ? pullFetchCuadranteRonda($db, (int) $rondaActual['id']) : [];
$resultados = $rondaActual ? pullFetchResultadosRonda($db, (int) $rondaActual['id']) : [];

$listaInscritos = $db->prepare(
    "SELECT ip.id, ip.estado, ip.fecha_inscripcion, u.id AS usuario_id, u.nombre, u.apellidos
     FROM inscripciones_pull ip
     INNER JOIN usuarios u ON u.id = ip.usuario_id
     WHERE ip.competicion_id = :cid
     ORDER BY ip.fecha_inscripcion ASC"
);
$listaInscritos->execute(['cid' => $id]);
$inscripciones = $listaInscritos->fetchAll(PDO::FETCH_ASSOC);

$usuariosDisponibles = $db->query(
    "SELECT id, CONCAT(nombre, ' ', apellidos) AS nombre_completo FROM usuarios
     WHERE activo = 1 AND rol = 'usuario' ORDER BY nombre ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Control Pull'); ?>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
</head>
<body class="tema-padel admin-pull-area">
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('pulls'); ?>
    <main class="main-content usuario-main">
        <a href="dashboard.php" class="btn btn-secondary">← Volver al panel</a>
        <header class="usuario-page-heading" style="margin-top:1rem;">
            <span class="usuario-title-logo-wrap" aria-hidden="true">
                <img src="../assets/img/fondo-tenis.jpg" alt="" class="usuario-title-logo" width="52" height="52">
            </span>
            <h1 class="panel-title"><?= htmlspecialchars($pull['nombre']) ?></h1>
        </header>
        <p class="panel-subtitle">Centro de control · Pozo / Americano · Inscripción individual</p>

        <?php if ($mensaje): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($pendientesCount > 0): ?>
            <div class="alert alert-warning">
                <?= $pendientesCount ?> solicitud(es) pendiente(s) de confirmar. Cambia el estado a <strong>confirmada</strong> en la tabla de abajo para contar plazas y entrar en el cuadrante.
            </div>
        <?php endif; ?>

        <div class="dashboard-cards admin-pull-stats">
            <div class="card usuario-card">
                <h3 class="card-title">Plazas</h3>
                <p><strong><?= $inscritos ?></strong> confirmados / <?= $cap ?> plazas<?php if ($pendientesCount > 0): ?> · <?= $pendientesCount ?> pendiente(s)<?php endif; ?></p>
                <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $pct ?>%;"></div></div>
                <p class="text-muted-inline"><?= $numPistas ?> pistas × 4 = <?= $numPistas * 4 ?> en pista<?= $cap > $numPistas * 4 ? ' (+ rotación descanso)' : '' ?></p>
            </div>
            <div class="card usuario-card">
                <h3 class="card-title">Ronda actual</h3>
                <p style="font-size:2rem;font-weight:700;color:var(--padel-teal-dark);"><?= (int) $pull['pull_ronda_actual'] ?></p>
                <p class="text-muted-inline"><?= $rondaActual ? 'En juego' : 'Sin cuadrante generado' ?></p>
            </div>
        </div>

        <div class="card usuario-card" style="margin-top:1rem;">
            <h3 class="card-title">Configuración de pistas</h3>
            <form method="POST" class="form-stack admin-pull-config-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="config">
                <label>Número de pistas
                    <input type="number" name="pull_num_pistas" min="1" max="12" value="<?= $numPistas ?>" required class="input-usuario">
                </label>
                <label>Capacidad máxima de jugadores
                    <input type="number" name="pull_capacidad" min="4" max="64" value="<?= $cap ?>" class="input-usuario">
                </label>
                <button type="submit" class="btn btn-secondary">Guardar plazas</button>
            </form>
        </div>

        <div class="card usuario-card" style="margin-top:1rem;">
            <h3 class="card-title">Gestionar inscripciones</h3>
            <p class="text-muted-inline">Las solicitudes desde la web llegan como <strong>pendiente</strong>. Confírmalas para ocupar plazas y generar el cuadrante.</p>
            <form method="POST" class="form-stack" style="margin-bottom:1rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="inscribir_jugador">
                <select name="usuario_id" required class="input-usuario">
                    <option value="">Añadir jugador manualmente…</option>
                    <?php foreach ($usuariosDisponibles as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Inscribir (confirmada)</button>
            </form>
            <table class="league-table">
                <thead>
                <tr>
                    <th>Jugador</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($inscripciones as $ins): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ins['nombre'] . ' ' . $ins['apellidos']) ?></strong></td>
                        <td><?= date('d/m/Y H:i', strtotime($ins['fecha_inscripcion'])) ?></td>
                        <td>
                            <span class="badge badge-<?= $ins['estado'] === 'confirmada' ? 'success' : ($ins['estado'] === 'pendiente' ? 'warning' : 'secondary') ?>">
                                <?= htmlspecialchars($ins['estado']) ?>
                            </span>
                        </td>
                        <td class="admin-pull-ins-actions">
                            <form method="POST" class="admin-pull-estado-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="cambiar_estado">
                                <input type="hidden" name="id_inscripcion" value="<?= (int) $ins['id'] ?>">
                                <select name="estado" class="input-usuario input-usuario-sm" onchange="this.form.submit()">
                                    <?php foreach (['pendiente', 'confirmada', 'rechazada', 'baja'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $ins['estado'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <form method="POST" onsubmit="return confirm('¿Eliminar esta inscripción?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="eliminar_inscripcion">
                                <input type="hidden" name="id_inscripcion" value="<?= (int) $ins['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($inscripciones === []): ?>
                    <tr><td colspan="4" class="text-muted-inline">Sin inscripciones.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-pull-actions card usuario-card" style="margin-top:1rem;">
            <h3 class="card-title">Cuadrante en vivo</h3>
            <?php if (!$rondaActual): ?>
                <p>Genera la primera ronda cuando tengas jugadores confirmados (mín. 4).</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion" value="generar_ronda">
                    <button type="submit" class="btn btn-primary">Generar primera ronda</button>
                </form>
            <?php else: ?>
                <p class="text-muted-inline">Ronda <?= (int) $rondaActual['numero'] ?> — Marca ganador por pista (Equipo A: arriba · Equipo B: abajo) y pulsa <strong>Rotar pistas</strong>.</p>
                <div class="pull-courts-grid">
                    <?php for ($p = 1; $p <= $numPistas; $p++): ?>
                        <article class="pull-court-card card">
                            <h4><?= htmlspecialchars(pullEtiquetaPista($p)) ?></h4>
                            <div class="pull-court-slots">
                                <?php for ($s = 1; $s <= 4; $s++): ?>
                                    <?php $jug = $cuadrante[$p][$s] ?? null; ?>
                                    <div class="pull-slot pull-slot-<?= $s <= 2 ? 'a' : 'b' ?>">
                                        <span class="pull-slot-label"><?= $s <= 2 ? 'A' : 'B' ?><?= $s ?></span>
                                        <?php if ($jug): ?>
                                            <?= htmlspecialchars($jug['nombre'] . ' ' . $jug['apellidos']) ?>
                                        <?php else: ?>
                                            <em class="text-muted-inline">—</em>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <?php if (!empty($cuadrante[$p]) && count($cuadrante[$p]) >= 4): ?>
                                <form method="POST" class="pull-result-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="accion" value="resultado">
                                    <input type="hidden" name="pista_numero" value="<?= $p ?>">
                                    <span>Ganador:</span>
                                    <label><input type="radio" name="ganador_equipo" value="A" <?= ($resultados[$p] ?? '') === 'A' ? 'checked' : '' ?> required> Equipo A</label>
                                    <label><input type="radio" name="ganador_equipo" value="B" <?= ($resultados[$p] ?? '') === 'B' ? 'checked' : '' ?>> Equipo B</label>
                                    <button type="submit" class="btn btn-secondary btn-sm">Guardar</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endfor; ?>
                </div>
                <form method="POST" style="margin-top:1.25rem;" onsubmit="return confirm('¿Avanzar a la siguiente ronda con rotación de pistas?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion" value="rotar">
                    <button type="submit" class="btn btn-primary btn-lg">🔔 Rotar pistas (avanzar ronda)</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
