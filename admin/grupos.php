<?php
// /admin/grupos.php — grupos de liga (competicion_grupos) en esquema pádel
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/security.php';
require_once '../includes/padel_guardrails.php';
require_once '../includes/liga_service.php';
require_once '../includes/competition_service.php';

requireAdmin();

$db = getDBConnection();
$mensaje = '';
$tipo_mensaje = '';
$csrf = generateCSRFToken();
$competition = leagueCompetitionService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_liga'])) {
        $nombreLiga = htmlspecialchars(trim($_POST['nombre_liga']));
        $modalidad = $_POST['modalidad_liga'] === 'grupos' ? 'grupos' : 'general';
        if ($nombreLiga !== '') {
            try {
                $competition->crearLiga($nombreLiga, $modalidad);
                $mensaje = "Liga '$nombreLiga' creada (sin inscripciones). Publica la liga cuando esté lista.";
                $tipo_mensaje = 'success';
            } catch (Throwable $e) {
                $mensaje = 'Error al crear la liga.';
                $tipo_mensaje = 'error';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publicar_liga_id'])) {
        $estado = $_POST['estado_liga'] ?? 'abierta';
        if ($competition->publicarLiga((int) $_POST['publicar_liga_id'], $estado)) {
            $mensaje = 'Estado de la liga actualizado.';
            $tipo_mensaje = 'success';
        } else {
            $mensaje = 'No se pudo actualizar la liga.';
            $tipo_mensaje = 'error';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_grupo'])) {
        $compId = (int) $_POST['competicion_id'];
        $nombre = htmlspecialchars(trim($_POST['nombre_grupo']));
        if ($compId > 0 && $nombre !== '') {
            try {
                $stmt = $db->prepare("INSERT INTO competicion_grupos (competicion_id, nombre, orden) VALUES (:cid, :nombre, 0)");
                $stmt->execute(['cid' => $compId, 'nombre' => $nombre]);
                $mensaje = "Grupo '$nombre' añadido.";
                $tipo_mensaje = 'success';
            } catch (PDOException $e) {
                $mensaje = 'Error al crear el grupo (¿nombre duplicado?).';
                $tipo_mensaje = 'error';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_grupo_id'])) {
        try {
            $db->beginTransaction();
            competicionEliminarGrupoCompleto($db, (int) $_POST['eliminar_grupo_id']);
            $db->commit();
            $mensaje = 'Grupo eliminado con inscripciones, clasificación y partidos asociados.';
            $tipo_mensaje = 'success';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $mensaje = 'No se pudo eliminar el grupo. ' . appSafeExceptionMessage($e, 'Revisa que no queden dependencias.');
            $tipo_mensaje = 'error';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_liga_id'])) {
        try {
            $db->beginTransaction();
            competicionEliminarLigaCompleta($db, (int) $_POST['eliminar_liga_id']);
            $db->commit();
            $mensaje = 'Liga eliminada por completo (grupos, inscripciones y partidos).';
            $tipo_mensaje = 'success';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $mensaje = 'No se pudo eliminar la liga. ' . appSafeExceptionMessage($e, 'Revisa que no queden dependencias.');
            $tipo_mensaje = 'error';
        }
    }

    $ligas = $db->query(
        "SELECT id, nombre, modalidad_liga, estado FROM competiciones WHERE tipo = 'liga' ORDER BY nombre ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $grupos = $db->query(
        "SELECT g.id, g.nombre, g.competicion_id, c.nombre AS liga_nombre
         FROM competicion_grupos g
         INNER JOIN competiciones c ON c.id = g.competicion_id
         ORDER BY c.nombre, g.orden, g.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Ligas y grupos'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-wrapper">
        <?php renderAdminSidebar('grupos'); ?>
        <main class="main-content">
            <h1>Ligas y grupos</h1>

            <?php if ($mensaje): ?>
                <div class="alert" style="<?= $tipo_mensaje === 'error' ? '' : 'background:#d4edda;color:#155724;' ?>"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

                <div class="card" style="margin-top: 1rem; padding: 20px;">
                    <h3>Nueva liga</h3>
                    <form method="POST" style="margin-top: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="crear_liga" value="1">
                        <input type="text" name="nombre_liga" placeholder="Nombre de la liga" required style="padding: 10px; width: 100%; max-width: 400px;">
                        <select name="modalidad_liga" style="padding: 10px; margin: 10px 0; display: block;">
                            <option value="general">Liga general (un grupo)</option>
                            <option value="grupos">Liga por grupos</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Crear liga</button>
                    </form>
                </div>

                <div class="card" style="margin-top: 1rem; padding: 20px;">
                    <h3>Añadir grupo a una liga</h3>
                    <form method="POST" style="margin-top: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="crear_grupo" value="1">
                        <select name="competicion_id" required style="padding: 10px; display: block; margin-bottom: 10px;">
                            <option value="">Selecciona liga...</option>
                            <?php foreach ($ligas as $liga): ?>
                                <?php if ($liga['modalidad_liga'] === 'grupos'): ?>
                                    <option value="<?= (int) $liga['id'] ?>"><?= htmlspecialchars($liga['nombre']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="nombre_grupo" placeholder="Nombre del grupo" required style="padding: 10px; width: 100%; max-width: 400px;">
                        <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Crear grupo</button>
                    </form>
                </div>

                <div class="card" style="margin-top: 1rem; padding: 20px;">
                    <h3>Ligas existentes</h3>
                    <div class="table-responsive">
                    <table class="league-table">
                        <thead>
                        <tr><th>Liga</th><th>Estado</th><th>Modalidad</th><th>Inscritos</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ligas as $liga): ?>
                            <?php $numIns = ligaContarInscripcionesCompeticion($db, (int) $liga['id']); ?>
                            <tr>
                                <td><?= htmlspecialchars($liga['nombre']) ?></td>
                                <td><?= htmlspecialchars($liga['estado']) ?></td>
                                <td><?= htmlspecialchars($liga['modalidad_liga'] ?? '') ?></td>
                                <td><?= $numIns ?></td>
                                <td style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                                    <form method="POST" style="display:inline-flex;gap:4px;align-items:center;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="publicar_liga_id" value="<?= (int) $liga['id'] ?>">
                                        <select name="estado_liga" style="padding:4px;">
                                            <?php foreach (['borrador', 'abierta', 'en_curso', 'finalizada', 'cancelada'] as $st): ?>
                                                <option value="<?= $st ?>" <?= $liga['estado'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:0.85rem;">Guardar estado</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar toda la liga y sus datos?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="eliminar_liga_id" value="<?= (int) $liga['id'] ?>">
                                        <button type="submit" class="btn" style="background:#dc3545;color:#fff;padding:4px 10px;font-size:0.85rem;">Eliminar liga</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($ligas === []): ?>
                            <tr><td colspan="5">No hay ligas.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

            <div class="card" style="margin-top: 1rem; padding: 20px;">
                <div class="table-responsive">
                <table class="league-table">
                    <thead>
                        <tr>
                            <th>Liga</th>
                            <th>Grupo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grupos as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['liga_nombre']) ?></td>
                                <td><?= htmlspecialchars($g['nombre']) ?></td>
                                <td style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <a href="inscripciones_liga.php?grupo_id=<?= (int) $g['id'] ?>" class="btn btn-primary" style="padding:4px 10px;font-size:0.85rem;">Inscripciones / Calendario</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar grupo y todos sus partidos/inscripciones?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="eliminar_grupo_id" value="<?= (int) $g['id'] ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:0.85rem;">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($grupos) === 0): ?>
                            <tr><td colspan="3">No hay grupos todavía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
