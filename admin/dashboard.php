<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/competiciones_helpers.php';
require_once '../includes/pull_engine.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/security.php';

requireAdmin();

$db = getDBConnection();
$esquemaPadel = padelCompeticionesSchemaAvailable($db);

$total_usuarios = (int) $db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'usuario'")->fetchColumn();
$total_partidos = (int) $db->query("SELECT COUNT(*) FROM partidos WHERE estado = 'validado'")->fetchColumn();
$partidos_disputa = (int) $db->query("SELECT COUNT(*) FROM partidos WHERE estado = 'rechazado'")->fetchColumn();

$pullStats = $esquemaPadel ? pullAdminStats($db) : ['activas' => 0, 'proximas' => 0, 'jugadores' => 0];
$pullsList = $esquemaPadel ? pullAdminList($db) : [];

$success_msg = '';
$error_msg = '';
$csrf = generateCSRFToken();

if ($esquemaPadel && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    requirePostCsrf();
    if ($_POST['accion'] === 'crear_pull') {
        $titulo = htmlspecialchars(trim($_POST['titulo'] ?? ''));
        $descripcion = htmlspecialchars(trim($_POST['descripcion'] ?? ''));
        $fecha_inicio = $_POST['fecha_inicio'] ?? null;
        $fecha_fin = $_POST['fecha_fin'] ?? null;
        $estado = competicionEstadoFromForm($_POST['estado'] ?? 'abierto');
        $numPistas = max(1, min(12, (int) ($_POST['pull_num_pistas'] ?? 3)));
        $cap = (int) ($_POST['pull_capacidad'] ?? 0);
        $cap = $cap > 0 ? $cap : pullCapacidadPorPistas($numPistas, null);

        $cartel_nombre = null;
        if (isset($_FILES['cartel']) && $_FILES['cartel']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['cartel']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $_FILES['cartel']['size'] < 3145728) {
                $upload_dir = '../assets/img/torneos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $cartel_nombre = uniqid('pull_') . '.' . $ext;
                move_uploaded_file($_FILES['cartel']['tmp_name'], $upload_dir . $cartel_nombre);
            }
        }

        try {
            $sql = "INSERT INTO competiciones (tipo, nombre, descripcion, cartel_ruta, fecha_inicio, fecha_fin, estado,
                    pull_num_pistas, pull_capacidad_jugadores, formato_torneo)
                    VALUES ('pull', :titulo, :descripcion, :cartel, :inicio, :fin, :estado, :pistas, :cap, 'eliminacion')";
            $db->prepare($sql)->execute([
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'cartel' => $cartel_nombre,
                'inicio' => $fecha_inicio,
                'fin' => $fecha_fin,
                'estado' => $estado,
                'pistas' => $numPistas,
                'cap' => $cap,
            ]);
            $success_msg = 'Pull creada correctamente.';
            $pullsList = pullAdminList($db);
            $pullStats = pullAdminStats($db);
        } catch (PDOException) {
            $error_msg = 'No se pudo crear la Pull.';
        }
    }

    if ($_POST['accion'] === 'eliminar_pull' && isset($_POST['pull_id'])) {
        $pull_id = (int) $_POST['pull_id'];
        try {
            $stmt_img = $db->prepare("SELECT cartel_ruta AS cartel FROM competiciones WHERE id = :id AND tipo = 'pull'");
            $stmt_img->execute(['id' => $pull_id]);
            $img = $stmt_img->fetch(PDO::FETCH_ASSOC);
            if ($img && !empty($img['cartel'])) {
                $path = '../assets/img/torneos/' . $img['cartel'];
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            $db->prepare("DELETE FROM competiciones WHERE id = :id AND tipo = 'pull'")->execute(['id' => $pull_id]);
            $success_msg = 'Pull eliminada.';
            $pullsList = pullAdminList($db);
            $pullStats = pullAdminStats($db);
        } catch (PDOException) {
            $error_msg = 'No se pudo eliminar (¿hay inscripciones ligadas?).';
        }
    }
}

if ($esquemaPadel) {
    $total_grupos = (int) $db->query('SELECT COUNT(*) FROM competicion_grupos')->fetchColumn();
    $usuarios_pendientes = (int) $db->query(
        "SELECT COUNT(*) FROM usuarios u WHERE u.rol = 'usuario' AND u.activo = 1
         AND NOT EXISTS (SELECT 1 FROM pareja_miembros pm WHERE pm.usuario_id = u.id AND pm.activo = 1)"
    )->fetchColumn();
} else {
    $total_grupos = 0;
    $usuarios_pendientes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Admin'); ?>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
</head>
<body class="tema-padel admin-pull-area">
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('dashboard'); ?>
    <main class="main-content usuario-main">
        <h1 class="panel-title">Hola, <?= sanitizeOutput($_SESSION['nombre']) ?></h1>
        <p class="panel-subtitle">Centro de control · Volea de revés</p>

        <?php if ($success_msg): ?><div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
        <?php if ($error_msg): ?><div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div><?php endif; ?>

        <?php if ($partidos_disputa > 0): ?>
            <div class="card usuario-card card-accent-danger" style="margin-bottom:1rem;">
                <h3 class="card-title">Disputas pendientes</h3>
                <p><?= $partidos_disputa ?> partido(s) de liga requieren revisión.</p>
                <a href="partidos.php" class="btn btn-primary">Resolver</a>
            </div>
        <?php endif; ?>

        <?php if ($esquemaPadel): ?>
        <div class="dashboard-cards admin-pull-stats">
            <div class="card usuario-card">
                <h3 class="card-title">Pulls activas</h3>
                <p class="admin-stat-number"><?= (int) $pullStats['activas'] ?></p>
            </div>
            <div class="card usuario-card">
                <h3 class="card-title">Próximas Pulls</h3>
                <p class="admin-stat-number"><?= (int) $pullStats['proximas'] ?></p>
            </div>
            <div class="card usuario-card">
                <h3 class="card-title">Jugadores en Pulls</h3>
                <p class="admin-stat-number"><?= (int) $pullStats['jugadores'] ?></p>
            </div>
        </div>

        <div class="dashboard-cards" style="margin-top:1rem;">
            <div class="card usuario-card">
                <h3 class="card-title">Liga · Jugadores</h3>
                <p class="admin-stat-number admin-stat-number--sm"><?= $total_usuarios ?></p>
            </div>
            <div class="card usuario-card">
                <h3 class="card-title">Partidos validados</h3>
                <p class="admin-stat-number admin-stat-number--sm"><?= $total_partidos ?></p>
            </div>
            <div class="card usuario-card">
                <h3 class="card-title">Grupos liga</h3>
                <p class="admin-stat-number admin-stat-number--sm"><?= $total_grupos ?></p>
            </div>
        </div>

        <?php if ($usuarios_pendientes > 0): ?>
            <div class="alert alert-warning" style="margin-top:1rem;"><?= $usuarios_pendientes ?> jugador(es) sin pareja · <a href="parejas.php">Gestionar</a></div>
        <?php endif; ?>

        <div class="card usuario-card" style="margin-top:1.5rem;">
            <h2 class="card-title" id="pulls">Nueva Pull</h2>
            <form method="POST" enctype="multipart/form-data" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="crear_pull">
                <label>Título<input type="text" name="titulo" required class="input-usuario" placeholder="Pozo viernes noche"></label>
                <label>Descripción<textarea name="descripcion" rows="3" class="input-usuario"></textarea></label>
                <label>Cartel (opcional)<input type="file" name="cartel" accept="image/*"></label>
                <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <label style="flex:1;">Inicio<input type="date" name="fecha_inicio" required class="input-usuario"></label>
                    <label style="flex:1;">Fin<input type="date" name="fecha_fin" required class="input-usuario"></label>
                </div>
                <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <label style="flex:1;">Nº pistas<input type="number" name="pull_num_pistas" min="1" max="12" value="3" class="input-usuario"></label>
                    <label style="flex:1;">Capacidad jugadores<input type="number" name="pull_capacidad" min="4" max="64" placeholder="Auto (pistas×4)" class="input-usuario"></label>
                </div>
                <label>Estado
                    <select name="estado" class="input-usuario">
                        <option value="abierto">Inscripciones abiertas</option>
                        <option value="cerrado">Cerrada</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Publicar Pull</button>
            </form>
        </div>

        <div class="card usuario-card" style="margin-top:1.5rem;">
            <h2 class="card-title">Gestionar Pulls</h2>
            <div class="table-responsive">
                <table class="league-table">
                    <thead>
                    <tr><th>Nombre</th><th>Fechas</th><th>Inscritos</th><th>Pistas</th><th>Estado</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pullsList as $p): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['titulo']) ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($p['fecha_inicio'])) ?> – <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?></td>
                            <td><?= (int) $p['inscritos'] ?> / <?= (int) ($p['pull_capacidad_jugadores'] ?? 12) ?></td>
                            <td><?= (int) ($p['pull_num_pistas'] ?? 3) ?></td>
                            <td><?= htmlspecialchars(strtoupper($p['estado'])) ?></td>
                            <td style="white-space:nowrap;">
                                <a href="pull_gestion.php?id=<?= (int) $p['id'] ?>" class="btn btn-primary btn-sm">Control en vivo</a>
                                <a href="editar_torneo.php?id=<?= (int) $p['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar esta Pull?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="accion" value="eliminar_pull">
                                    <input type="hidden" name="pull_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pullsList === []): ?>
                        <tr><td colspan="6">No hay Pulls publicadas.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
            <div class="alert alert-info">Activa el esquema pádel para gestionar Pulls.</div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
