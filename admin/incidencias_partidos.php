<?php

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/legacy_schema.php';
require_once '../includes/partido_flujo_usuario.php';

requireAdmin();

$db = getDBConnection();
if (!padelCompeticionesSchemaAvailable($db)) {
    die('Requiere esquema pádel.');
}

$mensaje = '';
$error = '';
$csrf = generateCSRFToken();
$grupoId = isset($_GET['grupo_id']) ? (int) $_GET['grupo_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePostCsrf();
    $partidoId = (int) ($_POST['partido_id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'anular') {
            padelAdminResolverIncidencia($db, $partidoId, 'anular');
            $mensaje = 'Partido dado por nulo (anulado).';
        } elseif ($accion === 'reprogramar') {
            padelAdminResolverIncidencia($db, $partidoId, 'reprogramar', [
                'fecha_programada' => $_POST['fecha_programada'] ?? '',
            ]);
            $mensaje = 'Partido reprogramado; las parejas verán la nueva fecha.';
        } elseif ($accion === 'forzar_ganador') {
            padelAdminResolverIncidencia($db, $partidoId, 'forzar_ganador', [
                'ganador_pareja_id' => (int) ($_POST['ganador_pareja_id'] ?? 0),
            ]);
            $mensaje = 'Ganador registrado y partido validado.';
        }
    } catch (Throwable $e) {
        require_once '../includes/app_errors.php';
        $error = appSafeExceptionMessage($e, 'No se pudo procesar la incidencia.');
    }
}

$incidencias = padelFetchPartidosIncidenciaAdmin($db, $grupoId > 0 ? $grupoId : null);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php renderAdminPageHead('Incidencias'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('incidencias'); ?>
    <main class="main-content">
        <h1>Incidencias de partidos</h1>
        <p style="color:#666;">Partidos marcados como no disputados por las parejas. Resuelve, reprograma o anula.</p>

        <?php if ($mensaje): ?><div class="alert" style="background:#d4edda;color:#155724;"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($incidencias === []): ?>
            <div class="card" style="padding:20px;margin-top:1rem;">No hay incidencias abiertas.</div>
        <?php else: ?>
            <?php foreach ($incidencias as $p): ?>
                <div class="card" style="padding:20px;margin-top:1rem;">
                    <h3><?= htmlspecialchars($p['etiqueta_a']) ?> vs <?= htmlspecialchars($p['etiqueta_b']) ?></h3>
                    <p><strong>Liga:</strong> <?= htmlspecialchars($p['liga_nombre']) ?>
                        <?php if (!empty($p['grupo_nombre'])): ?> · <?= htmlspecialchars($p['grupo_nombre']) ?><?php endif; ?></p>
                    <p><strong>Fecha programada:</strong>
                        <?= htmlspecialchars(date('d/m/Y', strtotime(ligaPartidoFechaProgramadaEfectiva($p)))) ?></p>
                    <?php if (!empty($p['incidencia_en'])): ?>
                        <p><strong>Incidencia desde:</strong> <?= date('d/m/Y H:i', strtotime($p['incidencia_en'])) ?></p>
                    <?php endif; ?>

                    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px;">
                        <form method="POST" onsubmit="return confirm('¿Anular partido?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="partido_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="accion" value="anular">
                            <button type="submit" class="btn" style="background:#6c757d;color:#fff;">Dar por nulo</button>
                        </form>

                        <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="partido_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="accion" value="reprogramar">
                            <label>Nueva fecha
                                <input type="date" name="fecha_programada" required value="<?= htmlspecialchars(date('Y-m-d', strtotime('+7 days'))) ?>">
                            </label>
                            <button type="submit" class="btn btn-primary">Reprogramar</button>
                        </form>

                        <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;" onsubmit="return confirm('¿Validar con ganador forzado?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="partido_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="accion" value="forzar_ganador">
                            <label>Ganador
                                <select name="ganador_pareja_id" required>
                                    <option value="<?= (int) $p['pareja_a_id'] ?>"><?= htmlspecialchars($p['etiqueta_a']) ?></option>
                                    <option value="<?= (int) $p['pareja_b_id'] ?>"><?= htmlspecialchars($p['etiqueta_b']) ?></option>
                                </select>
                            </label>
                            <button type="submit" class="btn btn-primary">Forzar ganador</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
