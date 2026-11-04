<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/competiciones_helpers.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/security.php';

requireAdmin();

$db = getDBConnection();
$esquemaPadel = !legacyTorneosSchemaAvailable($db);
$error = '';
$success = '';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}
$torneo_id = (int) $_GET['id'];
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar') {
    requirePostCsrf();
    $titulo = htmlspecialchars(trim($_POST['titulo']));
    $descripcion = htmlspecialchars(trim($_POST['descripcion']));
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];
    $puntuable = $_POST['puntuable'] ?? '0';
    $estado = $_POST['estado'];

    try {
        if ($esquemaPadel) {
            $descripcionFinal = $descripcion;
            if ($puntuable === '1' && !str_contains($descripcionFinal, '[Torneo puntuable')) {
                $descripcionFinal .= "\n\n[Torneo puntuable para liga]";
            }
            $sql = "UPDATE competiciones SET nombre = :titulo, descripcion = :descripcion, fecha_inicio = :inicio, fecha_fin = :fin, estado = :estado
                    WHERE id = :id AND tipo IN ('pull','torneo')";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'titulo' => $titulo,
                'descripcion' => $descripcionFinal,
                'inicio' => $fecha_inicio,
                'fin' => $fecha_fin,
                'estado' => competicionEstadoFromForm($estado),
                'id' => $torneo_id,
            ]);
        } else {
            $sql = "UPDATE torneos SET titulo = :titulo, descripcion = :descripcion, fecha_inicio = :inicio, fecha_fin = :fin, puntuable = :puntuable, estado = :estado WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'inicio' => $fecha_inicio,
                'fin' => $fecha_fin,
                'puntuable' => $puntuable,
                'estado' => $estado,
                'id' => $torneo_id,
            ]);
        }
        $success = '¡Torneo actualizado correctamente!';
    } catch (PDOException $e) {
        $error = 'Error al actualizar el torneo.';
    }
}

if ($esquemaPadel) {
    $stmt_get = $db->prepare(
        "SELECT id, nombre AS titulo, descripcion, fecha_inicio, fecha_fin, estado, 0 AS puntuable
         FROM competiciones WHERE id = :id AND tipo IN ('pull','torneo')"
    );
} else {
    $stmt_get = $db->prepare('SELECT * FROM torneos WHERE id = :id');
}
$stmt_get->execute(['id' => $torneo_id]);
$torneo = $stmt_get->fetch(PDO::FETCH_ASSOC);

if (!$torneo) {
    header('Location: dashboard.php');
    exit;
}

if ($esquemaPadel) {
    $torneo['estado'] = competicionEstadoToForm((string) $torneo['estado']);
    $torneo['puntuable'] = str_contains($torneo['descripcion'], '[Torneo puntuable') ? 1 : 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php renderAdminPageHead('Editar Pull'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('dashboard'); ?>
    <main class="main-content">
        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Volver al Dashboard</a>
        <div class="card" style="padding: 20px; max-width: 800px;">
            <h2 style="color: var(--primary-blue); margin-bottom: 20px;">Editar Torneo</h2>
            
            <?php if (!empty($success)): ?>
                <div class="alert" style="background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-left: 4px solid #28a745;"><?= $success ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" style="padding: 10px; margin-bottom: 15px;"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="editar">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Título</label>
                    <input type="text" name="titulo" value="<?= htmlspecialchars($torneo['titulo']) ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Descripción</label>
                    <textarea name="descripcion" rows="5" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"><?= htmlspecialchars(preg_replace('/\n\n\[Torneo puntuable para liga\]\s*$/', '', $torneo['descripcion'])) ?></textarea>
                </div>
                
                <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div class="form-col" style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px;">Fecha Inicio</label>
                        <input type="date" name="fecha_inicio" value="<?= $torneo['fecha_inicio'] ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                    <div class="form-col" style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px;">Fecha Fin</label>
                        <input type="date" name="fecha_fin" value="<?= $torneo['fecha_fin'] ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                    </div>
                </div>
                
                <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 25px;">
                    <div class="form-col" style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px;">Puntuable</label>
                        <select name="puntuable" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="1" <?= $torneo['puntuable'] == 1 ? 'selected' : '' ?>>Sí, oficial</option>
                            <option value="0" <?= $torneo['puntuable'] == 0 ? 'selected' : '' ?>>No, exhibición</option>
                        </select>
                    </div>
                    <div class="form-col" style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px;">Estado del Torneo</label>
                        <select name="estado" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="abierto" <?= $torneo['estado'] == 'abierto' ? 'selected' : '' ?>>Inscripciones Abiertas</option>
                            <option value="cerrado" <?= $torneo['estado'] == 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                            <option value="aplazado" <?= $torneo['estado'] == 'aplazado' ? 'selected' : '' ?>>Aplazado</option>
                            <option value="cancelado" <?= $torneo['estado'] == 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
