<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/parejas_helpers.php';
require_once '../includes/liga_service.php';
require_once '../includes/admin_sidebar.php';
require_once '../includes/legacy_schema.php';

requireAdmin();

$db = getDBConnection();
if (!padelCompeticionesSchemaAvailable($db)) {
    die('Requiere esquema pádel.');
}

$filtroGrupo = isset($_GET['grupo_id']) ? (int) $_GET['grupo_id'] : 0;

$gruposOpciones = $db->query(
    "SELECT g.id, g.nombre, c.nombre AS liga_nombre
     FROM competicion_grupos g
     INNER JOIN competiciones c ON c.id = g.competicion_id AND c.tipo = 'liga'
     ORDER BY c.nombre, g.nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$etiqA = sqlParejaEtiqueta('pa', $db);
$etiqB = sqlParejaEtiqueta('pb', $db);
$sql = "SELECT p.id, p.fecha, p.competicion_id, p.grupo_id,
               c.nombre AS liga_nombre, g.nombre AS grupo_nombre,
               ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
        FROM partidos p
        INNER JOIN competiciones c ON c.id = p.competicion_id
        LEFT JOIN competicion_grupos g ON g.id = p.grupo_id
        INNER JOIN parejas pa ON pa.id = p.pareja_a_id
        INNER JOIN parejas pb ON pb.id = p.pareja_b_id
        WHERE p.fase = 'liga' AND p.estado = 'pendiente' AND p.ganador_pareja_id IS NULL";
$params = [];
if ($filtroGrupo > 0) {
    $sql .= ' AND p.grupo_id = :gid';
    $params['gid'] = $filtroGrupo;
}
$sql .= ' ORDER BY c.nombre, g.nombre, p.fecha ASC, p.id ASC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resumenGrupos = $db->query(
    "SELECT g.id, g.nombre, c.nombre AS liga_nombre,
            (SELECT COUNT(*) FROM partidos p
             WHERE p.grupo_id = g.id AND p.fase = 'liga' AND p.estado = 'pendiente' AND p.ganador_pareja_id IS NULL) AS pendientes,
            (SELECT COUNT(*) FROM partidos p WHERE p.grupo_id = g.id AND p.fase = 'liga') AS total
     FROM competicion_grupos g
     INNER JOIN competiciones c ON c.id = g.competicion_id AND c.tipo = 'liga'
     ORDER BY pendientes DESC, c.nombre"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php renderAdminPageHead('Calendario pendiente'); ?>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <?php renderAdminSidebar('calendario'); ?>
    <main class="main-content">
        <h1>Partidos de calendario sin jugar</h1>
        <p class="text-muted">Cruces generados (round robin) que aún no tienen resultado reportado.</p>

        <div class="card" style="padding:16px;margin:1rem 0;">
            <form method="GET" class="filter-bar">
                <label for="grupo_id">Filtrar por grupo</label>
                <select name="grupo_id" id="grupo_id" onchange="this.form.submit()">
                    <option value="0">Todos los grupos</option>
                    <?php foreach ($gruposOpciones as $go): ?>
                        <option value="<?= (int) $go['id'] ?>" <?= $filtroGrupo === (int) $go['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($go['liga_nombre'] . ' · ' . $go['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="dashboard-cards">
            <?php foreach ($resumenGrupos as $rg): ?>
                <?php if ((int) $rg['pendientes'] > 0 || $filtroGrupo === 0): ?>
                    <div class="card stat-chip">
                        <h3 style="font-size:1rem;"><?= htmlspecialchars($rg['liga_nombre'] . ' · ' . $rg['nombre']) ?></h3>
                        <p><strong><?= (int) $rg['pendientes'] ?></strong> pendientes / <?= (int) $rg['total'] ?> en calendario</p>
                        <a href="calendario_pendientes.php?grupo_id=<?= (int) $rg['id'] ?>" class="btn btn-secondary" style="padding:6px 12px;font-size:0.85rem;">Ver listado</a>
                        <a href="inscripciones_liga.php?grupo_id=<?= (int) $rg['id'] ?>" class="btn btn-primary" style="padding:6px 12px;font-size:0.85rem;">Gestionar grupo</a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-top:1.5rem;">
            <div class="table-responsive">
                <table class="league-table">
                    <thead>
                    <tr>
                        <th>Liga / Grupo</th>
                        <th>Enfrentamiento</th>
                        <th>Fecha prevista</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendientes as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['liga_nombre'] . ' · ' . ($p['grupo_nombre'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($p['etiqueta_a']) ?> <span class="vs-badge">vs</span> <?= htmlspecialchars($p['etiqueta_b']) ?></td>
                            <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                            <td><a href="partidos.php">Ir a partidos</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($pendientes === []): ?>
                        <tr><td colspan="4">No hay partidos pendientes<?= $filtroGrupo ? ' en este grupo' : '' ?>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
