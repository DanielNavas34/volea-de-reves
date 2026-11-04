<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/liga_service.php';
require_once '../includes/competition_service.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/legacy_schema.php';

requireAdmin();

$db = getDBConnection();
if (!padelCompeticionesSchemaAvailable($db)) {
    die('Requiere esquema pádel.');
}

$grupoId = isset($_GET['grupo_id']) ? (int) $_GET['grupo_id'] : 0;
$grupo = $grupoId > 0 ? ligaFetchGrupo($db, $grupoId) : null;
if (!$grupo) {
    header('Location: grupos.php');
    exit;
}

$mensaje = '';
$error = '';
$csrf = generateCSRFToken();
$competition = leagueCompetitionService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'inscribir') {
        $parejaId = (int) ($_POST['pareja_id'] ?? 0);
        if ($parejaId > 0) {
            try {
                $competition->inscribirParejaAdmin(
                    (int) $grupo['competicion_id'],
                    $grupoId,
                    $parejaId,
                    'confirmada'
                );
                $mensaje = 'Pareja inscrita y fila de clasificación creada.';
            } catch (PDOException $e) {
                $error = 'No se pudo inscribir (¿ya estaba en el grupo?).';
            }
        }
    }

    if ($accion === 'eliminar') {
        $insId = (int) ($_POST['inscripcion_id'] ?? 0);
        try {
            $competition->eliminarInscripcion($insId, $grupoId);
            $mensaje = 'Inscripción eliminada.';
        } catch (Throwable $e) {
            $error = appSafeExceptionMessage($e, 'No se pudo eliminar la inscripción.');
        }
    }

    if ($accion === 'estado') {
        $insId = (int) ($_POST['inscripcion_id'] ?? 0);
        $estado = $_POST['estado'] ?? 'pendiente';
        try {
            $competition->actualizarEstadoInscripcion($insId, $grupoId, $estado);
            $mensaje = 'Estado actualizado.';
        } catch (Throwable $e) {
            $error = appSafeExceptionMessage($e, 'No se pudo actualizar el estado.');
        }
    }

    if ($accion === 'generar_calendario') {
        try {
            $fechaInicio = trim((string) ($_POST['fecha_inicio'] ?? ''));
            $frecuenciaDias = (int) ($_POST['frecuencia_dias'] ?? 7);
            $result = $competition->generarCalendarioRoundRobin($grupoId, $fechaInicio, $frecuenciaDias);
            $mensaje = $result['message'];
        } catch (Throwable $e) {
            $error = appSafeExceptionMessage($e, 'Error al generar calendario. Revisa inscripciones confirmadas y fechas.');
        }
    }
}

$inscripciones = ligaInscripcionesGrupo($db, $grupoId);
$parejasDisponibles = fetchParejasActivasAdmin($db);
$inscritasIds = array_column($inscripciones, 'pareja_id');
$stmtCnt = $db->prepare(
    "SELECT COUNT(*) FROM partidos WHERE competicion_id = :cid AND grupo_id = :gid AND fase = 'liga'"
);
$stmtCnt->execute(['cid' => (int) $grupo['competicion_id'], 'gid' => $grupoId]);
$totalPartidosGrupo = (int) $stmtCnt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php renderAdminPageHead('Inscripciones liga'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('grupos'); ?>
    <main class="main-content">
        <a href="grupos.php" class="btn btn-secondary">← Ligas y grupos</a>
        <h1>Inscripciones: <?= htmlspecialchars($grupo['liga_nombre']) ?> · <?= htmlspecialchars($grupo['grupo_nombre']) ?></h1>

        <?php if ($mensaje): ?><div class="alert" style="background:#d4edda;color:#155724;"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card" style="padding:20px;margin-top:1rem;">
            <p><strong>Parejas confirmadas:</strong> <?= count(array_filter($inscripciones, fn ($i) => $i['estado'] === 'confirmada')) ?></p>
            <p><strong>Partidos en calendario:</strong> <?= $totalPartidosGrupo ?> (round robin todos contra todos)</p>
            <p style="margin-top:8px;">
                <a href="calendario_pendientes.php?grupo_id=<?= (int) $grupoId ?>" class="btn btn-secondary" style="padding:8px 14px;font-size:0.9rem;">Ver partidos sin jugar de este grupo</a>
            </p>
            <form method="POST" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;" onsubmit="return confirm('¿Generar calendario con jornadas y fechas?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="generar_calendario">
                <label style="display:flex;flex-direction:column;font-size:0.9rem;">
                    Fecha de inicio (jornada 1)
                    <input type="date" name="fecha_inicio" required value="<?= htmlspecialchars(date('Y-m-d')) ?>" style="padding:8px;margin-top:4px;">
                </label>
                <label style="display:flex;flex-direction:column;font-size:0.9rem;">
                    Frecuencia (días entre jornadas)
                    <select name="frecuencia_dias" style="padding:8px;margin-top:4px;min-width:220px;">
                        <option value="7" selected>1 jornada por semana (7 días)</option>
                        <option value="14">1 jornada cada 2 semanas</option>
                        <option value="3">1 jornada cada 3 días</option>
                        <option value="1">1 jornada por día</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Generar calendario (round robin)</button>
            </form>
            <p style="font-size:0.85rem;color:#666;margin-top:8px;">Agrupa cruces por jornada y asigna <strong>fecha_programada</strong> a cada partido. Los jugadores solo podrán reportar el resultado desde ese día.</p>
        </div>

        <div class="card" style="padding:20px;margin-top:1rem;">
            <h3>Inscribir pareja (admin)</h3>
            <p style="font-size:0.9rem;color:#666;">Las solicitudes de usuarios llegan como <strong>pendiente</strong>: cámbialas a <strong>confirmada</strong> para incluirlas en el calendario.</p>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="inscribir">
                <select name="pareja_id" required style="padding:10px;min-width:280px;">
                    <option value="">Seleccionar pareja activa…</option>
                    <?php foreach ($parejasDisponibles as $pr): ?>
                        <?php if (!in_array((string) $pr['id'], $inscritasIds, true)): ?>
                            <option value="<?= (int) $pr['id'] ?>"><?= htmlspecialchars($pr['etiqueta']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Inscribir en grupo</button>
            </form>
        </div>

        <div class="card" style="margin-top:1rem;">
            <div class="table-responsive">
            <table class="<?= padelLeagueTableClass() ?>">
                <thead>
                <tr><th class="player-name">Pareja</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                <?php foreach ($inscripciones as $ins): ?>
                    <tr>
                        <td class="col-pareja"><?= padelHtmlEtiquetaPareja($ins['pareja_nombre']) ?></td>
                        <td><?= htmlspecialchars($ins['estado']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($ins['fecha_inscripcion'])) ?></td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="estado">
                                <input type="hidden" name="inscripcion_id" value="<?= (int) $ins['id'] ?>">
                                <select name="estado" onchange="this.form.submit()">
                                    <?php foreach (['pendiente', 'confirmada', 'rechazada', 'baja'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $ins['estado'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <form method="POST" onsubmit="return confirm('¿Eliminar inscripción?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="inscripcion_id" value="<?= (int) $ins['id'] ?>">
                                <button type="submit" class="btn" style="background:#dc3545;color:#fff;padding:4px 10px;">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($inscripciones === []): ?>
                    <tr><td colspan="4">Sin inscripciones en este grupo.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
