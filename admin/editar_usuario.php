<?php
// /admin/editar_usuario.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/security.php';

// Protección de administrador
requireAdmin();

$db = getDBConnection();

// 1. Validar que nos llega un ID correcto por la URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: usuarios.php?status=error");
    exit;
}

$usuario_id = $_GET['id'];
$mensaje = '';
$tipo_mensaje = '';
$csrf = generateCSRFToken();

// 2. Procesar el formulario cuando se envía (Guardar cambios)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    requirePostCsrf();
    $nombre = $_POST['nombre'] ?? '';
    $apellidos = $_POST['apellidos'] ?? '';
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $telefono = $_POST['telefono'] ?? '';
    // Si el checkbox está marcado llega un valor, si no, lo ponemos a 0 (Inactivo)
    $activo = isset($_POST['activo']) ? 1 : 0; 

    if (empty($nombre) || empty($email)) {
        $mensaje = "El nombre y el email son campos obligatorios.";
        $tipo_mensaje = "error";
    } else {
        try {
            $stmt = $db->prepare("UPDATE usuarios SET nombre = :nombre, apellidos = :apellidos, email = :email, telefono = :telefono, activo = :activo WHERE id = :id");
            $stmt->execute([
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'email' => $email,
                'telefono' => $telefono,
                'activo' => $activo,
                'id' => $usuario_id
            ]);
            $mensaje = "Perfil del jugador actualizado correctamente.";
            $tipo_mensaje = "success";
        } catch (PDOException $e) {
            // Un error común es intentar poner un email que ya tiene otro jugador
            $mensaje = "Error al guardar. Es posible que el email ya esté en uso por otro jugador.";
            $tipo_mensaje = "error";
        }
    }
}

// 3. Obtener los datos actuales del usuario para rellenar el formulario
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = :id AND rol = 'usuario'");
$stmt->execute(['id' => $usuario_id]);
$user = $stmt->fetch();

// Si el usuario no existe o intentan editar a un admin, lo devolvemos a la lista
if (!$user) {
    header("Location: usuarios.php?status=error");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Editar jugador'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Cambio: Usamos la clase dashboard-wrapper para habilitar el responsive del menú -->
    <div class="dashboard-wrapper">
        
        <?php renderAdminSidebar('usuarios'); ?>
        
        <!-- Contenido Principal -->
        <main class="main-content">
            
            <!-- Flex-wrap permite que el botón de volver baje de línea si la pantalla es muy estrecha -->
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
                <div>
                    <h1>Editar Perfil: <?= sanitizeOutput($user['nombre']) ?></h1>
                    <p>Modifica los datos personales o desactiva la cuenta del jugador.</p>
                </div>
                <a href="usuarios.php" class="btn btn-secondary">← Volver a la lista</a>
            </div>
            
            <?php if ($mensaje): ?>
                <div class="alert <?= $tipo_mensaje == 'error' ? 'alert-error' : '' ?>" style="<?= $tipo_mensaje == 'success' ? 'background-color: #D4EDDA; color: #155724;' : '' ?>">
                    <?= sanitizeOutput($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php
            if (padelCompeticionesSchemaAvailable($db)) {
                $parejaInfo = fetchParejaActivaDeUsuario($db, (int) $usuario_id);
                if ($parejaInfo): ?>
                    <div class="alert" style="background:#e7f3ff;color:#004085;margin-bottom:1rem;">
                        Pareja actual: <strong><?= htmlspecialchars($parejaInfo['etiqueta']) ?></strong>
                        (<a href="parejas.php?editar=<?= (int) $parejaInfo['id'] ?>">ver en Parejas</a>)
                    </div>
                <?php else: ?>
                    <div class="alert" style="background:#fff3cd;color:#856404;margin-bottom:1rem;">
                        Sin pareja activa. <a href="parejas.php?u1=<?= (int) $usuario_id ?>">Crear pareja</a>
                    </div>
                <?php endif;
            }
            ?>

            <div class="card" style="max-width: 600px;">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="form-group">
                        <label for="nombre">Nombre</label>
                        <input type="text" id="nombre" name="nombre" value="<?= sanitizeOutput($user['nombre']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="apellidos">Apellidos</label>
                        <input type="text" id="apellidos" name="apellidos" value="<?= sanitizeOutput($user['apellidos']) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" name="email" value="<?= sanitizeOutput($user['email']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input type="text" id="telefono" name="telefono" value="<?= sanitizeOutput($user['telefono']) ?>">
                    </div>
                    
                    <!-- Casilla para suspender/activar cuenta -->
                    <div class="form-group" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 20px; border-left: 4px solid var(--primary-blue);">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 0;">
                            <!-- Aseguramos que el input se vea bien en móviles no usando ancho fijo -->
                            <input type="checkbox" name="activo" value="1" <?= $user['activo'] ? 'checked' : '' ?> style="transform: scale(1.3); margin-left: 5px;">
                            <strong>Cuenta Activa</strong>
                        </label>
                        <p style="font-size: 0.85rem; color: #666; margin-top: 8px; margin-left: 5px;">
                            Si desmarcas esta casilla, el jugador no podrá iniciar sesión en la plataforma, pero su historial de partidos y estadísticas se mantendrán intactos.
                        </p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: 20px;">Guardar Cambios</button>
                </form>
            </div>

        </main>
    </div>
</body>
</html>