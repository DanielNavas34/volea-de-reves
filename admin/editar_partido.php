<?php

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/match_service.php';
require_once '../includes/padel_league_points.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/admin_maintenance_service.php';
require_once '../includes/notificaciones_usuario.php';

requireAdmin();

$db = getDBConnection();
if (!padelCompeticionesSchemaAvailable($db)) {
    die('Requiere esquema pádel.');
}

$partidoId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($partidoId <= 0) {
    header('Location: partidos.php');
    exit;
}

$etiqA = sqlParejaEtiqueta('pa', $db);
$etiqB = sqlParejaEtiqueta('pb', $db);
$stmt = $db->prepare(
    "SELECT p.*, c.nombre AS comp_nombre, g.nombre AS grupo_nombre,
            ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
     FROM partidos p
     INNER JOIN competiciones c ON c.id = p.competicion_id
     LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
     INNER JOIN parejas pa ON pa.id = p.pareja_a_id
     INNER JOIN parejas pb ON pb.id = p.pareja_b_id
     WHERE p.id = :id LIMIT 1"
);
$stmt->execute(['id' => $partidoId]);
$partido = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$partido) {
    header('Location: partidos.php?status=error');
    exit;
}

$mensaje = '';
$error = '';
$csrf = generateCSRFToken();
$matches = leagueMatchService($db);
$resultadoTexto = padelFormatSetsForPartido($db, $partidoId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $accion = (string) ($_POST['accion'] ?? 'guardar');
    try {
        if ($accion === 'eliminar') {
            $matches->eliminarPartido($partidoId);
            header('Location: partidos.php?status=success_delete');
            exit;
        }
        $datos = [
            'fecha' => $_POST['fecha'] ?? '',
            'fecha_programada' => $_POST['fecha_programada'] ?? null,
            'hora' => $_POST['hora'] ?? '12:00',
            'jornada' => $_POST['jornada'] !== '' ? (int) $_POST['jornada'] : null,
            'ganador_pareja_id' => $_POST['ganador_pareja_id'] ?? null,
            'resultado_texto' => trim((string) ($_POST['resultado_texto'] ?? '')),
            'estado' => $_POST['estado'] ?? 'pendiente',
            'nota_admin' => $_POST['nota_admin'] ?? '',
        ];
        if ($datos['ganador_pareja_id'] === '') {
            $datos['ganador_pareja_id'] = null;
        }
        $matches->adminActualizarPartido($partidoId, $datos);

        $avisar = isset($_POST['notificar_parejas']) && trim((string) ($datos['nota_admin'] ?? '')) !== '';
        if ($avisar) {
            $nota = trim((string) $datos['nota_admin']);
            notificacionesAvisarMensajeAdminPareja($db, (int) $partido['pareja_a_id'], $nota, $partidoId);
            notificacionesAvisarMensajeAdminPareja($db, (int) $partido['pareja_b_id'], $nota, $partidoId);
        }

        header('Location: editar_partido.php?id=' . $partidoId . '&status=ok');
        exit;
    } catch (Throwable $e) {
        require_once '../includes/app_errors.php';
        $error = appSafeExceptionMessage($e, 'No se pudo guardar el partido.');
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'ok') {
    $mensaje = 'Partido actualizado.';
    $stmt->execute(['id' => $partidoId]);
    $partido = $stmt->fetch(PDO::FETCH_ASSOC);
    $resultadoTexto = padelFormatSetsForPartido($db, $partidoId);
}

$fechaProg = $partido['fecha_programada'] ?? $partido['fecha'] ?? date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php renderAdminPageHead('Editar partido'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('partidos'); ?>
    <main class="main-content">
        <p><a href="partidos.php" class="btn btn-secondary">← Volver a partidos</a></p>
        <h1>Editar partido #<?= (int) $partidoId ?></h1>
        <p><?= padelHtmlEnfrentamientoParejas($partido['etiqueta_a'], $partido['etiqueta_b']) ?><br>
            <span style="color:#666;"><?= htmlspecialchars($partido['comp_nombre'] . ($partido['grupo_nombre'] ? ' · ' . $partido['grupo_nombre'] : '')) ?></span></p>

        <?php if ($mensaje): ?><div class="alert" style="background:#d4edda;color:#155724;"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card" style="padding:20px;margin-top:1rem;max-width:640px;">
            <form method="POST" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="guardar">

                <label>Fecha del partido (resultado)
                    <input type="date" name="fecha" required value="<?= htmlspecialchars(substr((string) $partido['fecha'], 0, 10)) ?>" style="width:100%;padding:8px;">
                </label>
                <label>Fecha programada (calendario / sorteo)
                    <input type="date" name="fecha_programada" value="<?= htmlspecialchars(substr((string) $fechaProg, 0, 10)) ?>" style="width:100%;padding:8px;">
                </label>
                <label>Hora
                    <input type="time" name="hora" value="<?= htmlspecialchars(substr((string) ($partido['hora'] ?? '12:00:00'), 0, 5)) ?>" style="width:100%;padding:8px;">
                </label>
                <label>Jornada
                    <input type="number" name="jornada" min="0" value="<?= htmlspecialchars((string) ($partido['jornada'] ?? '')) ?>" style="width:100%;padding:8px;">
                </label>
                <label>Ganador
                    <select name="ganador_pareja_id" style="width:100%;padding:8px;">
                        <option value="">— Sin ganador —</option>
                        <option value="<?= (int) $partido['pareja_a_id'] ?>" <?= (int) ($partido['ganador_pareja_id'] ?? 0) === (int) $partido['pareja_a_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partido['etiqueta_a']) ?>
                        </option>
                        <option value="<?= (int) $partido['pareja_b_id'] ?>" <?= (int) ($partido['ganador_pareja_id'] ?? 0) === (int) $partido['pareja_b_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($partido['etiqueta_b']) ?>
                        </option>
                    </select>
                </label>
                <label>Marcador (sets)
                    <input type="text" name="resultado_texto" value="<?= htmlspecialchars($resultadoTexto) ?>" placeholder="6-4, 6-3" style="width:100%;padding:8px;">
                </label>
                <label>Estado
                    <select name="estado" style="width:100%;padding:8px;">
                        <?php foreach (['pendiente', 'validado', 'rechazado', 'incidencia', 'anulado'] as $st): ?>
                            <option value="<?= $st ?>" <?= ($partido['estado'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (adminPartidoTieneNotaAdmin($db)): ?>
                <label>Nota / comentario para las parejas
                    <textarea name="nota_admin" rows="3" style="width:100%;padding:8px;" placeholder="Visible en su panel si activas la notificación"><?= htmlspecialchars((string) ($partido['nota_admin'] ?? '')) ?></textarea>
                </label>
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0;">
                    <input type="checkbox" name="notificar_parejas" value="1">
                    Enviar nota como aviso en el panel de ambas parejas
                </label>
                <?php endif; ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
            <form method="POST" style="margin-top:20px;" onsubmit="return confirm('¿Eliminar este partido de forma permanente?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="eliminar">
                <button type="submit" class="btn" style="background:#dc3545;color:#fff;">Eliminar partido</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
