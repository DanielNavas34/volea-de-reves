<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/admin_maintenance_service.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/security.php';

requireAdmin();

$db = getDBConnection();
$esquemaPadel = padelCompeticionesSchemaAvailable($db);
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_usuario'])) {
    $usuario_id = (int) $_POST['usuario_id'];
    try {
        if ($esquemaPadel) {
            adminEliminarUsuarioCompleto($db, $usuario_id);
        } else {
            $db->prepare('DELETE FROM partidos WHERE jugador1_id = :id OR jugador2_id = :id')->execute(['id' => $usuario_id]);
            $db->prepare('DELETE FROM clasificacion WHERE usuario_id = :id')->execute(['id' => $usuario_id]);
            $db->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $usuario_id]);
        }
        header('Location: usuarios.php?status=deleted');
        exit;
    } catch (PDOException $e) {
        header('Location: usuarios.php?status=error');
        exit;
    }
}

if (!$esquemaPadel && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_grupo'])) {
    $usuario_id = $_POST['usuario_id'];
    $grupo_id = !empty($_POST['grupo_id']) ? $_POST['grupo_id'] : null;
    try {
        $stmt = $db->prepare('UPDATE usuarios SET grupo_id = :grupo_id WHERE id = :id');
        $stmt->execute(['grupo_id' => $grupo_id, 'id' => $usuario_id]);
        header('Location: usuarios.php?status=success');
        exit;
    } catch (PDOException $e) {
        header('Location: usuarios.php?status=error');
        exit;
    }
}

$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['status'])) {
    $map = [
        'success' => ['Jugador actualizado correctamente.', 'success'],
        'deleted' => ['Jugador eliminado del sistema.', 'success'],
        'error_pareja' => ['No se pudo eliminar (revisa inscripciones o partidos ligados).', 'error'],
        'error' => ['Error al realizar la acción.', 'error'],
    ];
    [$mensaje, $tipo_mensaje] = $map[$_GET['status']] ?? ['', ''];
}

if ($esquemaPadel) {
    $etiq = sqlParejaEtiqueta('p', $db);
    $sql = "SELECT u.id, u.nombre, u.apellidos, u.email, u.telefono, u.activo, u.tipo_perfil,
                   p.id AS pareja_id, p.estado AS pareja_estado, ({$etiq}) AS pareja_nombre
            FROM usuarios u
            LEFT JOIN pareja_miembros pm ON pm.usuario_id = u.id AND pm.activo = 1
            LEFT JOIN parejas p ON p.id = pm.pareja_id AND p.estado IN ('activa', 'borrador')
            WHERE u.rol = 'usuario'
            ORDER BY u.id DESC";
    $usuarios = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $grupos = [];
} else {
    $sql = "SELECT u.id, u.nombre, u.apellidos, u.email, u.telefono, u.grupo_id, u.activo, u.tipo_perfil, g.nombre AS nombre_grupo
            FROM usuarios u
            LEFT JOIN grupos g ON u.grupo_id = g.id
            WHERE u.rol = 'usuario'
            ORDER BY u.id DESC";
    $usuarios = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $grupos = $db->query('SELECT * FROM grupos ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Jugadores'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .fila-inactiva { background-color: #fff0f0 !important; opacity: 0.8; }
        .badge-inactivo { display: inline-block; background: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; margin-top: 4px; }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('usuarios'); ?>
    <main class="main-content">
        <h1>Gestión de jugadores</h1>
        <p><?= $esquemaPadel ? 'Consulta jugadores y su pareja. Crea parejas en la sección Parejas.' : 'Asigna categorías a los jugadores.' ?></p>

        <?php if ($mensaje): ?>
            <div class="alert <?= $tipo_mensaje === 'error' ? 'alert-error' : '' ?>" style="<?= $tipo_mensaje === 'success' ? 'background:#d4edda;color:#155724;' : '' ?>">
                <?= sanitizeOutput($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if ($esquemaPadel): ?>
            <p><a href="parejas.php" class="btn btn-primary">Ir a gestión de parejas</a></p>
        <?php endif; ?>

        <div class="card" style="margin-top: 2rem;">
            <div class="table-responsive">
                <table class="league-table">
                    <thead>
                    <tr>
                        <th>Jugador</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th><?= $esquemaPadel ? 'Pareja' : 'Categoría' ?></th>
                        <?php if (!$esquemaPadel): ?><th>Asignar</th><?php endif; ?>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($usuarios) > 0): ?>
                        <?php foreach ($usuarios as $user): ?>
                            <tr class="<?= !$user['activo'] ? 'fila-inactiva' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($user['nombre'] . ' ' . $user['apellidos']) ?></strong>
                                    <?php if (!$user['activo']): ?><br><span class="badge-inactivo">INACTIVO</span><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['telefono']) ?></td>
                                <td>
                                    <?php if ($esquemaPadel): ?>
                                        <?php if (in_array($user['tipo_perfil'], ['pull', 'solo_torneo'], true)): ?>
                                            <span style="background:#17a2b8;color:white;padding:4px 8px;border-radius:4px;font-size:0.85rem;">Modalidad Pull</span>
                                        <?php elseif (!empty($user['pareja_id'])): ?>
                                            <span style="background:var(--primary-blue);color:white;padding:4px 8px;border-radius:4px;font-size:0.85rem;">
                                                <?= htmlspecialchars($user['pareja_nombre']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="background:#ccc;color:#333;padding:4px 8px;border-radius:4px;font-size:0.85rem;">Sin pareja</span>
                                            <?php if ($user['activo']): ?>
                                                <br><a href="parejas.php?u1=<?= (int) $user['id'] ?>" style="font-size:0.8rem;">Crear pareja</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= $user['grupo_id'] ? htmlspecialchars($user['nombre_grupo']) : 'Sin asignar' ?>
                                    <?php endif; ?>
                                </td>
                                <?php if (!$esquemaPadel): ?>
                                    <td>
                                        <?php if ($user['activo'] && !in_array($user['tipo_perfil'], ['pull', 'solo_torneo'], true)): ?>
                                            <form method="POST" style="display:flex;gap:0.5rem;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="usuario_id" value="<?= $user['id'] ?>">
                                                <select name="grupo_id">
                                                    <option value="">—</option>
                                                    <?php foreach ($grupos as $grupo): ?>
                                                        <option value="<?= $grupo['id'] ?>" <?= ($user['grupo_id'] == $grupo['id']) ? 'selected' : '' ?>><?= htmlspecialchars($grupo['nombre']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="actualizar_grupo" class="btn btn-secondary">Guardar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td style="display:flex;gap:0.5rem;">
                                    <a href="editar_usuario.php?id=<?= (int) $user['id'] ?>" class="btn btn-primary" style="padding:6px 12px;font-size:0.85rem;">Editar</a>
                                    <?php if ($user['activo']): ?>
                                        <form method="POST" onsubmit="return confirm('¿Eliminar jugador?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="usuario_id" value="<?= (int) $user['id'] ?>">
                                            <button type="submit" name="eliminar_usuario" class="btn" style="background:#dc3545;color:white;padding:6px 12px;font-size:0.85rem;">Eliminar</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?= $esquemaPadel ? 5 : 6 ?>">No hay jugadores registrados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
