<?php
// Inscripción torneos tenis legacy (tabla torneos). Solo si no hay esquema pádel.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/legacy_tenis_guard.php';

redirectLegacyTenisBlocked('../pulls.php');

$db = getDBConnection();
$error = '';
$success = '';

// 1. Verificar qué torneo es
if (!isset($_GET['torneo_id']) || empty($_GET['torneo_id'])) {
    header("Location: torneos.php");
    exit;
}

$torneo_id = (int)$_GET['torneo_id'];

// Obtener datos del torneo
$stmt_torneo = $db->prepare("SELECT * FROM torneos WHERE id = :id");
$stmt_torneo->execute(['id' => $torneo_id]);
$torneo = $stmt_torneo->fetch(PDO::FETCH_ASSOC);

if (!$torneo) {
    die("El torneo no existe.");
}

// 2. Comprobar si el usuario tiene sesión iniciada
$usuario_logueado = isset($_SESSION['usuario_id']);
$ya_inscrito = false; // Será true SOLO si está apuntado a ambas cosas
$tiene_individual = false;
$tiene_dobles = false;

if ($usuario_logueado) {
    $usuario_id = $_SESSION['usuario_id'];
    
    // =========================================================================
    // NUEVA LÓGICA DE COMPROBACIÓN BIFURCADA
    // =========================================================================
    
    // A) ¿Está apuntado al individual?
    $stmt_ind = $db->prepare("SELECT id FROM inscripciones_torneos WHERE torneo_id = :tid AND usuario_id = :uid AND modalidad IN ('individual', 'ambos')");
    $stmt_ind->execute(['tid' => $torneo_id, 'uid' => $usuario_id]);
    $tiene_individual = $stmt_ind->fetch() ? true : false;

    // B) ¿Está apuntado a dobles por su cuenta?
    $stmt_dob1 = $db->prepare("SELECT id FROM inscripciones_torneos WHERE torneo_id = :tid AND usuario_id = :uid AND modalidad IN ('dobles', 'ambos')");
    $stmt_dob1->execute(['tid' => $torneo_id, 'uid' => $usuario_id]);
    
    // C) ¿Alguien le ha invitado a dobles y está pendiente o aceptado?
    $stmt_dob2 = $db->prepare("SELECT id FROM inscripciones_torneos WHERE torneo_id = :tid AND pareja_id = :uid AND estado_dobles IN ('pendiente', 'aceptado')");
    $stmt_dob2->execute(['tid' => $torneo_id, 'uid' => $usuario_id]);

    $tiene_dobles = ($stmt_dob1->fetch() || $stmt_dob2->fetch()) ? true : false;

    // Si ya tiene las dos modalidades, cerramos el formulario
    if ($tiene_individual && $tiene_dobles) {
        $ya_inscrito = true;
    }
    // =========================================================================
    
    // PROCESAR EL FORMULARIO DE INSCRIPCIÓN
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'inscribirse' && !$ya_inscrito) {
        if (!verifyCSRFToken($_POST['csrf_token'])) {
            die("Error de seguridad CSRF.");
        }
        
        $modalidad = $_POST['modalidad'];
        $pareja_id = null;
        $estado_dobles = 'na';
        
        // Validaciones de seguridad por si intentan trucar el HTML
        if (($modalidad == 'individual' || $modalidad == 'ambos') && $tiene_individual) {
            $error = "Ya estás inscrito en la modalidad individual.";
        }
        if (($modalidad == 'dobles' || $modalidad == 'ambos') && $tiene_dobles) {
            $error = "Ya tienes un equipo de dobles asignado o una invitación pendiente.";
        }
        
        // Si elige dobles, capturamos a la pareja
        if (empty($error) && ($modalidad === 'dobles' || $modalidad === 'ambos')) {
            $pareja_id = !empty($_POST['pareja_id']) ? (int)$_POST['pareja_id'] : null;
            
            if (!$pareja_id || $pareja_id == $usuario_id) {
                $error = "Debes seleccionar un compañero de dobles válido.";
            } else {
                $estado_dobles = 'pendiente';
            }
        }
        
        if (empty($error)) {
            $sql = "INSERT INTO inscripciones_torneos (torneo_id, usuario_id, modalidad, pareja_id, estado_dobles) 
                    VALUES (:torneo_id, :usuario_id, :modalidad, :pareja_id, :estado_dobles)";
            $stmt_ins = $db->prepare($sql);
            
            try {
                $stmt_ins->execute([
                    'torneo_id' => $torneo_id,
                    'usuario_id' => $usuario_id,
                    'modalidad' => $modalidad,
                    'pareja_id' => $pareja_id,
                    'estado_dobles' => $estado_dobles
                ]);
                $success = "¡Inscripción completada con éxito!";
                
                // Actualizamos variables para la vista
                if ($modalidad == 'individual' || $modalidad == 'ambos') $tiene_individual = true;
                if ($modalidad == 'dobles' || $modalidad == 'ambos') $tiene_dobles = true;
                if ($tiene_individual && $tiene_dobles) $ya_inscrito = true;
                
            } catch (PDOException $e) {
                $error = "Hubo un error al procesar tu inscripción. Inténtalo más tarde.";
            }
        }
    }

    // Obtener lista de posibles compañeros
    if (!$ya_inscrito && !$tiene_dobles) {
        // FILTRO ARREGLADO: Variables diferentes para evitar el Error 500 de PDO
        $sql_parejas = "SELECT id, nombre, apellidos FROM usuarios 
                        WHERE id != :mi_id AND activo = 1 AND rol = 'usuario'
                        AND id NOT IN (
                            SELECT usuario_id FROM inscripciones_torneos WHERE torneo_id = :t_id1 AND modalidad IN ('dobles', 'ambos')
                        )
                        AND id NOT IN (
                            SELECT pareja_id FROM inscripciones_torneos WHERE torneo_id = :t_id2 AND estado_dobles IN ('pendiente', 'aceptado') AND pareja_id IS NOT NULL
                        )
                        ORDER BY nombre ASC";
        $stmt_parejas = $db->prepare($sql_parejas);
        
        // Pasamos t_id1 y t_id2 por separado
        $stmt_parejas->execute([
            'mi_id' => $usuario_id, 
            't_id1' => $torneo_id,
            't_id2' => $torneo_id
        ]);
        $posibles_parejas = $stmt_parejas->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    require_once __DIR__ . '/../includes/brand.php';
    renderBrandHead('root', 'Inscripción');
    ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .info-box { background: #f8f9fa; border-left: 4px solid var(--primary-blue); padding: 15px; margin-bottom: 25px; }
        .hidden-div { display: none; margin-top: 15px; padding: 15px; background: #e9ecef; border-radius: 5px; }
    </style>
</head>
<body class="bg-gray">
    
    <main class="container" style="max-width: 800px; margin-top: 40px; margin-bottom: 40px;">
        <a href="../index.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Volver al inicio</a>
        
        <div class="card" style="padding: 30px;">
            <h1 style="color: var(--primary-blue); margin-bottom: 10px;">Inscripción: <?= htmlspecialchars($torneo['titulo']) ?></h1>
            <p style="color: #666; margin-bottom: 20px;">📅 Del <?= date('d/m/Y', strtotime($torneo['fecha_inicio'])) ?> al <?= date('d/m/Y', strtotime($torneo['fecha_fin'])) ?></p>
            
            <?php if ($torneo['estado'] !== 'abierto'): ?>
                <!-- BLOQUEO: Si el torneo ya no acepta inscripciones -->
                <div class="alert" style="background-color: #f8d7da; color: #721c24; border-left: 4px solid #dc3545;">
                    ⚠️ Lo sentimos, las inscripciones para este torneo actualmente están <strong><?= strtoupper($torneo['estado']) ?>S</strong>.
                </div>
                
            <?php elseif (!$usuario_logueado): ?>
                
                <!-- REDIRECCIÓN INTELIGENTE: Guardamos dónde quería ir -->
                <?php $_SESSION['redirect_after_login'] = 'inscripcion.php?torneo_id=' . $torneo_id; ?>
                
                <!-- ESCENARIO 2: Usuario externo (No logueado) -->
                <div class="info-box">
                    <h3>¡Hola, jugador de pádel!</h3>
                    <p style="margin-top: 10px; line-height: 1.5;">Para poder inscribirte en este torneo, necesitamos saber quién eres. Tienes dos opciones:</p>
                    <ul style="margin: 15px 0 15px 20px; line-height: 1.6;">
                        <li>Si ya eres jugador de la liga o has participado antes, simplemente <strong>Inicia Sesión</strong>.</li>
                        <li>Si eres nuevo y solo quieres apuntarte a este torneo, haz clic en <strong>Registrarse</strong> (podrás marcar la casilla "Solo Torneos").</li>
                    </ul>
                    
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <a href="login.php" class="btn btn-primary">Iniciar Sesión</a>
                        <a href="registro.php" class="btn btn-secondary">Registrarse</a>
                    </div>
                </div>

            <?php elseif ($ya_inscrito): ?>
                <!-- YA INSCRITO EN TODO -->
                <div class="alert" style="background-color: #d4edda; color: #155724; border-left: 4px solid #28a745; text-align: center; padding: 30px;">
                    <span style="font-size: 2rem;">✅</span>
                    <h2 style="margin: 10px 0;">¡Inscripción Completa!</h2>
                    <p>Estás apuntado a las máximas categorías permitidas (Individual y Dobles). ¡Mucha suerte en la pista!</p>
                    <?php if (isset($success)) echo "<p style='margin-top: 10px; font-weight: bold;'>$success</p>"; ?>
                </div>

            <?php else: ?>
                <!-- ESCENARIO 1: Usuario Logueado (Formulario de Inscripción Dinámico) -->
                
                <?php if (isset($success) && !empty($success)): ?>
                    <div class="alert" style="background-color: #d4edda; color: #155724; border-left: 4px solid #28a745; margin-bottom: 20px;">
                        <?= $success ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error" style="margin-bottom: 20px;"><?= $error ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="accion" value="inscribirse">
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="modalidad" style="font-size: 1.1rem; display: block; margin-bottom: 10px;">¿En qué modalidad deseas participar?</label>
                        <select id="modalidad" name="modalidad" required style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem;">
                            <option value="" disabled selected>Elige una opción...</option>
                            
                            <!-- DESPLEGABLE DINÁMICO -->
                            <?php if (!$tiene_individual && !$tiene_dobles): ?>
                                <option value="individual">🙋‍♂️ Solo Individual</option>
                                <option value="dobles">🤝 Solo Dobles</option>
                                <option value="ambos">🔥 Ambos (Individual y Dobles)</option>
                            <?php elseif (!$tiene_individual && $tiene_dobles): ?>
                                <option value="individual">🙋‍♂️ Solo Individual (Tu plaza de Dobles ya está gestionada)</option>
                            <?php elseif ($tiene_individual && !$tiene_dobles): ?>
                                <option value="dobles">🤝 Solo Dobles (Ya estás apuntado al Individual)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <!-- DIV OCULTO: Solo aparece si elige Dobles o Ambos -->
                    <div id="pareja_div" class="hidden-div">
                        <label for="pareja_id" style="font-size: 1.1rem; display: block; margin-bottom: 10px;">Selecciona a tu pareja para el dobles:</label>
                        <select id="pareja_id" name="pareja_id" style="width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Selecciona un compañero...</option>
                            <?php if (isset($posibles_parejas)): ?>
                                <?php foreach ($posibles_parejas as $pareja): ?>
                                    <option value="<?= $pareja['id'] ?>"><?= htmlspecialchars($pareja['nombre'] . ' ' . $pareja['apellidos']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p style="font-size: 0.85rem; color: #666; margin-top: 10px;">
                            <em>Aviso: Al enviar la inscripción, tu compañero recibirá una notificación en su panel para confirmar que acepta jugar contigo.</em>
                        </p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: 30px; font-size: 1.1rem; padding: 15px;">Confirmar Inscripción</button>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <!-- SCRIPT PARA MOSTRAR/OCULTAR EL SELECTOR DE PAREJA -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const modalidadSelect = document.getElementById('modalidad');
            const parejaDiv = document.getElementById('pareja_div');
            const parejaSelect = document.getElementById('pareja_id');
            
            if (modalidadSelect) {
                modalidadSelect.addEventListener('change', function() {
                    if (this.value === 'dobles' || this.value === 'ambos') {
                        parejaDiv.style.display = 'block';
                        parejaSelect.setAttribute('required', 'required'); // Hacemos el campo obligatorio
                    } else {
                        parejaDiv.style.display = 'none';
                        parejaSelect.removeAttribute('required'); // Quitamos la obligatoriedad
                        parejaSelect.value = ''; // Reseteamos el valor por si acaso
                    }
                });
            }
        });
    </script>
</body>
</html>