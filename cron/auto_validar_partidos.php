<?php

/**
 * Valida partidos pendientes 24 h después del reporte.
 * docker compose exec -T web php cron/auto_validar_partidos.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/cron_guard.php';
cronRequireAuthorizedRunner();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/match_service.php';
require_once __DIR__ . '/../includes/notificaciones_usuario.php';
require_once __DIR__ . '/../includes/parejas_helpers.php';

$db = getDBConnection();
$svc = leagueMatchService($db);

$validados = $svc->autoValidarPartidosCaducados();
foreach ($validados as $partidoId) {
    notificacionesAvisarResultadoAutoValidado($db, $partidoId);
}

echo date('c') . ' — auto-validados: ' . count($validados) . "\n";
