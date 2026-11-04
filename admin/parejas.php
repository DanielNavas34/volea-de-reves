<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/padel_guardrails.php';
require_once '../includes/pair_service.php';
require_once '../includes/admin_maintenance_service.php';
require_once '../includes/notificaciones_usuario.php';
require_once '../includes/security.php';
require_once '../includes/legacy_schema.php';

requireAdmin();

$db = getDBConnection();
if (!padelCompeticionesSchemaAvailable($db)) {
    die('Esta sección requiere el esquema de pádel.');
}

$mensaje = '';
$tipo = '';
$adminId = (int) ($_SESSION['usuario_id'] ?? 0);
if ($adminId <= 0) {
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
}

$csrf = generateCSRFToken();
$pairs = pairService($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'crear') {
            $u1 = (int) ($_POST['usuario1_id'] ?? 0);
            $u2 = (int) ($_POST['usuario2_id'] ?? 0);
            $nombre = trim((string) ($_POST['nombre_display'] ?? ''));
            $pairs->crearParejaAdmin(
                $u1,
                $u2,
                $nombre !== '' ? htmlspecialchars($nombre) : null,
                $adminId
            );
            header('Location: parejas.php?status=created');
            exit;
        }
        if ($accion === 'actualizar' && isset($_POST['pareja_id'])) {
            $pid = (int) $_POST['pareja_id'];
            $estado = $_POST['estado'] ?? 'activa';
            $nombre = trim((string) ($_POST['nombre_display'] ?? ''));
            $nota = trim((string) ($_POST['nota_admin'] ?? ''));
            $pairs->actualizarParejaAdmin(
                $pid,
                $estado,
                $nombre !== '' ? htmlspecialchars($nombre) : null,
                $nota !== '' ? $nota : null
            );
            if (isset($_POST['notificar_nota']) && $nota !== '') {
                notificacionesAvisarMensajeAdminPareja($db, $pid, $nota);
            }
            header('Location: parejas.php?status=updated');
            exit;
        }
        if ($accion === 'eliminar' && isset($_POST['pareja_id'])) {
            adminEliminarParejaCompleta($db, (int) $_POST['pareja_id']);
            header('Location: parejas.php?status=deleted');
            exit;
        }
    } catch (Throwable $e) {
        require_once '../includes/app_errors.php';
        $mensaje = appSafeExceptionMessage($e, 'No se pudo completar la operación.');
        $tipo = 'error';
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'created') {
        $mensaje = 'Pareja creada correctamente.';
        $tipo = 'success';
    } elseif ($_GET['status'] === 'updated') {
        $mensaje = 'Pareja actualizada.';
        $tipo = 'success';
    } elseif ($_GET['status'] === 'deleted') {
        $mensaje = 'Pareja eliminada del sistema.';
        $tipo = 'success';
    }
}

$etiq = sqlParejaEtiqueta('p', $db);
$notaListCol = dbColumnExists($db, 'parejas', 'nota_admin') ? ', p.nota_admin' : '';
$displayCol = parejasTieneNombreDisplay($db) ? ', p.nombre_display' : '';
$parejas = $db->query(
    "SELECT p.id, p.estado, p.creado_en{$displayCol}{$notaListCol}, ({$etiq}) AS etiqueta
     FROM parejas p
     ORDER BY p.creado_en DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$jugadores = $db->query(
    "SELECT u.id, u.nombre, u.apellidos, u.activo
     FROM usuarios u
     WHERE u.rol = 'usuario' AND u.activo = 1
     ORDER BY u.nombre ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$editId = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
$editPareja = null;
$editMiembros = [];
if ($editId > 0) {
    foreach ($parejas as $p) {
        if ((int) $p['id'] === $editId) {
            $editPareja = $p;
            break;
        }
    }
    if ($editPareja) {
        $editMiembros = fetchMiembrosPareja($db, $editId);
    }
}

$preU1 = isset($_GET['u1']) ? (int) $_GET['u1'] : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Parejas'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('parejas'); ?>
    <main class="main-content">
        <h1>Gestión de parejas</h1>
        <p>Crea y administra parejas de dos jugadores para ligas y torneos.</p>

        <?php if ($mensaje): ?>
            <div class="alert <?= $tipo === 'error' ? 'alert-error' : '' ?>" style="<?= $tipo === 'success' ? 'background:#d4edda;color:#155724;' : '' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top:1.5rem;padding:20px;">
            <h3>Nueva pareja</h3>
            <form method="POST" style="margin-top:1rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="crear">
                <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <select name="usuario1_id" required style="padding:10px;min-width:200px;">
                        <option value="">Jugador 1…</option>
                        <?php foreach ($jugadores as $j): ?>
                            <option value="<?= (int) $j['id'] ?>" <?= $preU1 === (int) $j['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($j['nombre'] . ' ' . $j['apellidos']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="usuario2_id" required style="padding:10px;min-width:200px;">
                        <option value="">Jugador 2…</option>
                        <?php foreach ($jugadores as $j): ?>
                            <option value="<?= (int) $j['id'] ?>"><?= htmlspecialchars($j['nombre'] . ' ' . $j['apellidos']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="text" name="nombre_display" placeholder="Nombre opcional (ej. Los Torres)" style="margin-top:10px;padding:10px;width:100%;max-width:400px;">
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">Crear pareja</button>
            </form>
        </div>

        <?php if ($editPareja): ?>
        <div class="card" style="margin-top:1.5rem;padding:20px;border-left:4px solid var(--primary-blue);">
            <h3>Editar pareja #<?= (int) $editPareja['id'] ?></h3>
            <p><strong>Miembros:</strong>
                <?php foreach ($editMiembros as $m): ?>
                    <?= htmlspecialchars($m['nombre'] . ' ' . $m['apellidos']) ?>;
                <?php endforeach; ?>
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="actualizar">
                <input type="hidden" name="pareja_id" value="<?= (int) $editPareja['id'] ?>">
                <input type="text" name="nombre_display" value="<?= htmlspecialchars($editPareja['nombre_display'] ?? '') ?>" style="padding:10px;width:100%;max-width:400px;">
                <select name="estado" style="padding:10px;margin:10px 0;display:block;">
                    <?php foreach (['activa', 'borrador', 'inactiva', 'disuelta'] as $st): ?>
                        <option value="<?= $st ?>" <?= $editPareja['estado'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (adminParejaTieneNotaAdmin($db)): ?>
                <textarea name="nota_admin" rows="3" placeholder="Comentario interno / mensaje a la pareja" style="width:100%;max-width:400px;margin-top:10px;padding:8px;"><?= htmlspecialchars((string) ($editPareja['nota_admin'] ?? '')) ?></textarea>
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0;">
                    <input type="checkbox" name="notificar_nota" value="1">
                    Enviar comentario al panel de la pareja
                </label>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Guardar</button>
                <a href="parejas.php" class="btn btn-secondary">Cancelar</a>
            </form>
            <form method="POST" style="margin-top:16px;" onsubmit="return confirm('¿Eliminar pareja, partidos e inscripciones?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="pareja_id" value="<?= (int) $editPareja['id'] ?>">
                <button type="submit" class="btn" style="background:#dc3545;color:#fff;">Eliminar pareja</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card" style="margin-top:1.5rem;">
            <div class="table-responsive">
                <table class="<?= padelLeagueTableClass() ?>">
                    <thead>
                    <tr>
                        <th class="player-name">Pareja</th>
                        <th>Estado</th>
                        <th>Miembros</th>
                        <th>Creada</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($parejas as $p): ?>
                        <?php $miembros = fetchMiembrosPareja($db, (int) $p['id']); ?>
                        <tr>
                            <td class="col-pareja"><strong><?= padelHtmlEtiquetaPareja((string) ($p['etiqueta'] ?? ('Pareja #' . (int) $p['id']))) ?></strong></td>
                            <td><?= htmlspecialchars((string) ($p['estado'] ?? '')) ?></td>
                            <td style="font-size:0.9rem;">
                                <?php foreach ($miembros as $m): ?>
                                    <?= htmlspecialchars(trim(($m['nombre'] ?? '') . ' ' . ($m['apellidos'] ?? ''))) ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($p['creado_en'])) ?></td>
                            <td><a href="parejas.php?editar=<?= (int) $p['id'] ?>" class="btn btn-secondary" style="padding:4px 10px;font-size:0.85rem;">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($parejas === []): ?>
                        <tr><td colspan="5">No hay parejas registradas.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
