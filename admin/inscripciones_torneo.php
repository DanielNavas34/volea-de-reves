<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/competiciones_helpers.php';
require_once '../includes/pull_service.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/security.php';

requireAdmin();
$db = getDBConnection();
$esquemaPadel = padelCompeticionesSchemaAvailable($db);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}
$torneo_id = (int) $_GET['id'];
$error = '';
$success = '';

$esPull = false;
$csrf = generateCSRFToken();

if ($esquemaPadel && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    requirePostCsrf();
    $stmtTipoPost = $db->prepare('SELECT tipo FROM competiciones WHERE id = :id');
    $stmtTipoPost->execute(['id' => $torneo_id]);
    $esPullPost = in_array($stmtTipoPost->fetchColumn(), ['pull', 'torneo'], true);

    if ($_POST['accion'] === 'eliminar_inscripcion') {
        $idIns = (int) $_POST['id_inscripcion'];
        try {
            if ($esPullPost && pullInscripcionesTableExists($db)) {
                $db->prepare('DELETE FROM inscripciones_pull WHERE id = :id AND competicion_id = :cid')
                    ->execute(['id' => $idIns, 'cid' => $torneo_id]);
            } else {
                $db->prepare('DELETE FROM inscripciones WHERE id = :id AND competicion_id = :cid')
                    ->execute(['id' => $idIns, 'cid' => $torneo_id]);
            }
            $success = 'Inscripción eliminada.';
        } catch (PDOException $e) {
            $error = 'No se pudo eliminar la inscripción.';
        }
    }
    if ($_POST['accion'] === 'cambiar_estado') {
        $idIns = (int) $_POST['id_inscripcion'];
        $estado = $_POST['estado'] ?? 'pendiente';
        if (!in_array($estado, ['pendiente', 'confirmada', 'rechazada', 'baja'], true)) {
            $estado = 'pendiente';
        }
        try {
            if ($esPullPost && pullInscripcionesTableExists($db)) {
                $db->prepare('UPDATE inscripciones_pull SET estado = :e WHERE id = :id AND competicion_id = :cid')
                    ->execute(['e' => $estado, 'id' => $idIns, 'cid' => $torneo_id]);
            } else {
                $db->prepare('UPDATE inscripciones SET estado = :e WHERE id = :id AND competicion_id = :cid')
                    ->execute(['e' => $estado, 'id' => $idIns, 'cid' => $torneo_id]);
            }
            $success = 'Estado actualizado.';
        } catch (PDOException $e) {
            $error = 'Error al actualizar.';
        }
    }
    if ($_POST['accion'] === 'inscribir_pareja') {
        $parejaId = (int) ($_POST['pareja_id'] ?? 0);
        if ($parejaId > 0) {
            try {
                $db->prepare(
                    "INSERT INTO inscripciones (competicion_id, pareja_id, estado) VALUES (:cid, :pid, 'confirmada')"
                )->execute(['cid' => $torneo_id, 'pid' => $parejaId]);
                $success = 'Pareja inscrita.';
            } catch (PDOException $e) {
                $error = 'La pareja ya está inscrita o no es válida.';
            }
        }
    }
    if ($_POST['accion'] === 'inscribir_jugador') {
        $uid = (int) ($_POST['usuario_id'] ?? 0);
        if ($uid > 0 && pullInscripcionesTableExists($db)) {
            try {
                inscribirUsuarioEnPull($db, $uid, $torneo_id);
                $db->prepare('UPDATE inscripciones_pull SET estado = \'confirmada\' WHERE competicion_id = :cid AND usuario_id = :uid')
                    ->execute(['cid' => $torneo_id, 'uid' => $uid]);
                $success = 'Jugador inscrito en la Pull.';
            } catch (Throwable $e) {
                $error = 'No se pudo inscribir al jugador.';
            }
        }
    }
}

if ($esquemaPadel) {
    $stmt = $db->prepare("SELECT nombre AS titulo, estado, tipo FROM competiciones WHERE id = :id AND tipo IN ('pull','torneo')");
    $stmt->execute(['id' => $torneo_id]);
    $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$torneo) {
        die('Pull no encontrada.');
    }
    $torneo['estado'] = competicionEstadoToForm((string) $torneo['estado']);
    $esPull = in_array($torneo['tipo'], ['pull', 'torneo'], true) && pullInscripcionesTableExists($db);

    if ($esPull) {
        $sql = "SELECT ip.id AS inscripcion_id, ip.estado, ip.fecha_inscripcion, ip.usuario_id,
                       CONCAT(u.nombre, ' ', u.apellidos) AS jugador_nombre
                FROM inscripciones_pull ip
                INNER JOIN usuarios u ON u.id = ip.usuario_id
                WHERE ip.competicion_id = :tid
                ORDER BY ip.fecha_inscripcion DESC";
        $stmtIns = $db->prepare($sql);
        $stmtIns->execute(['tid' => $torneo_id]);
        $inscripciones = $stmtIns->fetchAll(PDO::FETCH_ASSOC);
        $usuariosDisponibles = $db->query(
            "SELECT id, CONCAT(nombre, ' ', apellidos) AS nombre_completo FROM usuarios WHERE activo = 1 AND rol = 'usuario' ORDER BY nombre ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $parejasDisponibles = [];
    } else {
        $etiq = sqlParejaEtiqueta('p', $db);
        $sql = "SELECT i.id AS inscripcion_id, i.estado, i.fecha_inscripcion, i.pareja_id, ({$etiq}) AS pareja_nombre
                FROM inscripciones i
                INNER JOIN parejas p ON p.id = i.pareja_id
                WHERE i.competicion_id = :tid
                ORDER BY i.fecha_inscripcion DESC";
        $stmtIns = $db->prepare($sql);
        $stmtIns->execute(['tid' => $torneo_id]);
        $inscripciones = $stmtIns->fetchAll(PDO::FETCH_ASSOC);
        $parejasDisponibles = fetchParejasActivasAdmin($db);
        $usuariosDisponibles = [];
    }
} else {
    header('Location: dashboard.php');
    exit;
}

$total = count($inscripciones);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php renderAdminPageHead('Inscripciones Pull'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('dashboard'); ?>
    <main class="main-content">
        <a href="dashboard.php" class="btn btn-secondary">← Volver</a>
        <?php if ($esPull): ?>
            <a href="pull_gestion.php?id=<?= (int) $torneo_id ?>" class="btn btn-primary" style="margin-left:0.5rem;">Control en vivo</a>
        <?php endif; ?>
        <h1>Inscripciones Pull: <?= htmlspecialchars($torneo['titulo']) ?></h1>
        <p>Estado: <strong><?= htmlspecialchars($torneo['estado']) ?></strong> · <?= $total ?> <?= $esPull ? 'jugador(es)' : 'pareja(s)' ?></p>

        <?php if ($success): ?><div class="alert" style="background:#d4edda;color:#155724;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($esPull): ?>
        <div class="card" style="margin-top:1rem;padding:20px;">
            <h3>Inscribir jugador manualmente (individual)</h3>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="inscribir_jugador">
                <select name="usuario_id" required style="padding:10px;min-width:280px;">
                    <option value="">Seleccionar jugador…</option>
                    <?php foreach ($usuariosDisponibles as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Inscribir</button>
            </form>
        </div>
        <?php else: ?>
        <div class="card" style="margin-top:1rem;padding:20px;">
            <h3>Inscribir pareja manualmente</h3>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="inscribir_pareja">
                <select name="pareja_id" required style="padding:10px;min-width:280px;">
                    <option value="">Seleccionar pareja…</option>
                    <?php foreach ($parejasDisponibles as $pr): ?>
                        <option value="<?= (int) $pr['id'] ?>"><?= htmlspecialchars($pr['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Inscribir</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card" style="margin-top:1.5rem;">
            <table class="league-table">
                <thead>
                <tr>
                    <th><?= $esPull ? 'Jugador' : 'Pareja' ?></th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($inscripciones as $ins): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ins['jugador_nombre'] ?? $ins['pareja_nombre'] ?? '') ?></strong></td>
                        <td><?= date('d/m/Y H:i', strtotime($ins['fecha_inscripcion'])) ?></td>
                        <td><?= htmlspecialchars($ins['estado']) ?></td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="cambiar_estado">
                                <input type="hidden" name="id_inscripcion" value="<?= (int) $ins['inscripcion_id'] ?>">
                                <select name="estado" onchange="this.form.submit()" style="padding:4px;">
                                    <?php foreach (['pendiente', 'confirmada', 'rechazada', 'baja'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $ins['estado'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <form method="POST" onsubmit="return confirm('¿Eliminar inscripción?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="accion" value="eliminar_inscripcion">
                                <input type="hidden" name="id_inscripcion" value="<?= (int) $ins['inscripcion_id'] ?>">
                                <button type="submit" class="btn" style="background:#dc3545;color:#fff;padding:4px 10px;">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($inscripciones === []): ?>
                    <tr><td colspan="4">No hay inscripciones todavía.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
