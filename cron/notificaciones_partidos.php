<?php

/**
 * CRON diario: cuenta atrás 3, 2, 1 y HOY con nombre de pareja rival (sin duplicar por tipo/día).
 *
 * docker compose exec -T web php cron/notificaciones_partidos.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/cron_guard.php';
cronRequireAuthorizedRunner();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notificaciones_usuario.php';
require_once __DIR__ . '/../includes/legacy_schema.php';
require_once __DIR__ . '/../includes/liga_service.php';
require_once __DIR__ . '/../includes/parejas_helpers.php';

$db = getDBConnection();

if (!dbColumnExists($db, 'partidos', 'fecha_programada') || !notificacionesTablaDisponible($db)) {
    fwrite(STDERR, "Requiere fecha_programada y tabla notificaciones_usuarios.\n");
    exit(1);
}

$hoy = new DateTimeImmutable('today');
$maxDias = 3;
$fechaHasta = $hoy->modify('+' . $maxDias . ' days')->format('Y-m-d');
$fechaDesde = $hoy->format('Y-m-d');

$etiqA = sqlParejaEtiqueta('pa', $db);
$etiqB = sqlParejaEtiqueta('pb', $db);

$stmt = $db->prepare(
    "SELECT p.id, p.fecha_programada, p.pareja_a_id, p.pareja_b_id,
            ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
     FROM partidos p
     INNER JOIN parejas pa ON pa.id = p.pareja_a_id
     INNER JOIN parejas pb ON pb.id = p.pareja_b_id
     WHERE p.fase = 'liga'
       AND p.estado = 'pendiente'
       AND p.ganador_pareja_id IS NULL
       AND p.fecha_programada IS NOT NULL
       AND p.fecha_programada BETWEEN :desde AND :hasta"
);
$stmt->execute(['desde' => $fechaDesde, 'hasta' => $fechaHasta]);
$partidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$insertadas = 0;

foreach ($partidos as $p) {
    $partidoId = (int) $p['id'];
    $fechaPartido = DateTimeImmutable::createFromFormat('Y-m-d', (string) $p['fecha_programada']);
    if (!$fechaPartido) {
        continue;
    }
    $diasRestantes = (int) $hoy->diff($fechaPartido)->days;
    if ($diasRestantes < 0 || $diasRestantes > $maxDias) {
        continue;
    }
    $tipo = notificacionTipoPartidoCountdown($diasRestantes);
    $url = notificacionUrlPartido($partidoId);

    $mapParejas = [
        (int) $p['pareja_a_id'] => (string) $p['etiqueta_b'],
        (int) $p['pareja_b_id'] => (string) $p['etiqueta_a'],
    ];

    foreach ($mapParejas as $parejaId => $nombreRival) {
        $texto = notificacionTextoPartidoCountdown($diasRestantes, $nombreRival);
        foreach (ligaUsuariosActivosPareja($db, $parejaId) as $uid) {
            if (notificacionInsertarSiNueva($db, $uid, $partidoId, $tipo, $texto, $texto, $url)) {
                $insertadas++;
            }
        }
    }
}

echo date('c') . " — notificaciones nuevas: {$insertadas}\n";
