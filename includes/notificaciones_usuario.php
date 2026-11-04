<?php

require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/parejas_helpers.php';
require_once __DIR__ . '/liga_service.php';
require_once __DIR__ . '/partido_validacion_mensajes.php';

function notificacionesTablaDisponible(PDO $db): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = (bool) $db->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'notificaciones_usuarios' LIMIT 1"
    )->fetchColumn();

    return $cache;
}

function notificacionesTieneUrlDestino(PDO $db): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = dbColumnExists($db, 'notificaciones_usuarios', 'url_destino');
    }

    return $cache;
}

function notificacionesContadorNoLeidas(PDO $db, int $usuarioId): int
{
    if (!notificacionesTablaDisponible($db)) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM notificaciones_usuarios WHERE usuario_id = :uid AND leida = 0'
    );
    $stmt->execute(['uid' => $usuarioId]);

    return (int) $stmt->fetchColumn();
}

/** Texto exacto de cuenta atrás con rival. */
function notificacionTextoPartidoCountdown(int $diasRestantes, string $nombreRival): string
{
    $rival = trim($nombreRival);
    if ($diasRestantes <= 0) {
        return '¡Partido HOY con ' . $rival . '!';
    }
    if ($diasRestantes === 1) {
        return 'Partido en 1 día con ' . $rival;
    }

    return 'Partido en ' . $diasRestantes . ' días con ' . $rival;
}

function notificacionTipoPartidoCountdown(int $diasRestantes): string
{
    return match ($diasRestantes) {
        0 => 'partido_hoy',
        1 => 'partido_1d',
        2 => 'partido_2d',
        3 => 'partido_3d',
        default => 'partido_' . $diasRestantes . 'd',
    };
}

function notificacionUrlPartido(?int $partidoId): string
{
    if ($partidoId !== null && $partidoId > 0) {
        return 'partidos.php#partido-' . $partidoId;
    }

    return 'partidos.php';
}

function notificacionInsertarOActualizar(
    PDO $db,
    int $usuarioId,
    ?int $partidoId,
    string $tipo,
    string $titulo,
    string $mensaje,
    ?string $urlDestino = null
): bool {
    if (!notificacionesTablaDisponible($db)) {
        return false;
    }
    $urlDestino = $urlDestino ?? notificacionUrlPartido($partidoId);
    if (notificacionesTieneUrlDestino($db)) {
        $stmt = $db->prepare(
            "INSERT INTO notificaciones_usuarios (usuario_id, partido_id, tipo, titulo, mensaje, url_destino, leida)
             VALUES (:uid, :pid, :tipo, :titulo, :mensaje, :url, 0)
             ON DUPLICATE KEY UPDATE
               titulo = VALUES(titulo),
               mensaje = VALUES(mensaje),
               url_destino = VALUES(url_destino),
               leida = 0,
               creado_en = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'uid' => $usuarioId,
            'pid' => $partidoId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => $urlDestino,
        ]);
    } else {
        $stmt = $db->prepare(
            "INSERT INTO notificaciones_usuarios (usuario_id, partido_id, tipo, titulo, mensaje, leida)
             VALUES (:uid, :pid, :tipo, :titulo, :mensaje, 0)
             ON DUPLICATE KEY UPDATE
               titulo = VALUES(titulo),
               mensaje = VALUES(mensaje),
               leida = 0,
               creado_en = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            'uid' => $usuarioId,
            'pid' => $partidoId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
        ]);
    }

    return $stmt->rowCount() > 0;
}

function notificacionInsertarSiNueva(
    PDO $db,
    int $usuarioId,
    ?int $partidoId,
    string $tipo,
    string $titulo,
    string $mensaje,
    ?string $urlDestino = null
): bool {
    if (!notificacionesTablaDisponible($db)) {
        return false;
    }
    $urlDestino = $urlDestino ?? notificacionUrlPartido($partidoId);
    if (notificacionesTieneUrlDestino($db)) {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO notificaciones_usuarios (usuario_id, partido_id, tipo, titulo, mensaje, url_destino)
             VALUES (:uid, :pid, :tipo, :titulo, :mensaje, :url)"
        );
        $stmt->execute([
            'uid' => $usuarioId,
            'pid' => $partidoId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => $urlDestino,
        ]);
    } else {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO notificaciones_usuarios (usuario_id, partido_id, tipo, titulo, mensaje)
             VALUES (:uid, :pid, :tipo, :titulo, :mensaje)"
        );
        $stmt->execute([
            'uid' => $usuarioId,
            'pid' => $partidoId,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
        ]);
    }

    return $stmt->rowCount() > 0;
}

/** @return list<array<string, mixed>> */
function notificacionesRecientesUsuario(PDO $db, int $usuarioId, int $limit = 20): array
{
    if (!notificacionesTablaDisponible($db)) {
        return [];
    }
    $limit = max(1, min(40, $limit));
    $cols = 'id, tipo, titulo, mensaje, leida, creado_en, partido_id';
    if (notificacionesTieneUrlDestino($db)) {
        $cols .= ', url_destino';
    }
    $stmt = $db->prepare(
        "SELECT {$cols}
         FROM notificaciones_usuarios
         WHERE usuario_id = :uid
         ORDER BY leida ASC, creado_en DESC
         LIMIT {$limit}"
    );
    $stmt->execute(['uid' => $usuarioId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['tiempo_relativo'] = notificacionTiempoRelativoEs((string) $row['creado_en']);
        $row['icono'] = notificacionIconoTipo((string) $row['tipo']);
        $row['url'] = !empty($row['url_destino'])
            ? (string) $row['url_destino']
            : notificacionUrlPartido(isset($row['partido_id']) ? (int) $row['partido_id'] : null);
    }
    unset($row);

    return $rows;
}

function notificacionTiempoRelativoEs(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 45) {
        return 'Hace un momento';
    }
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);

        return 'Hace ' . $m . ' min';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);

        return 'Hace ' . $h . ' hora' . ($h === 1 ? '' : 's');
    }
    if ($diff < 604800) {
        $d = (int) floor($diff / 86400);

        return 'Hace ' . $d . ' día' . ($d === 1 ? '' : 's');
    }

    return date('d/m/Y H:i', $ts);
}

/** calendar | alert | success | bell */
function notificacionIconoTipo(string $tipo): string
{
    if (str_starts_with($tipo, 'partido_')) {
        return 'calendar';
    }
    if ($tipo === 'resultado_pendiente') {
        return 'alert';
    }
    if ($tipo === 'resultado_validado') {
        return 'success';
    }
    if ($tipo === 'admin_mensaje') {
        return 'bell';
    }

    return 'bell';
}

function notificacionRenderIconoHtml(string $icono): string
{
    return match ($icono) {
        'calendar' => '<span class="notif-icon notif-icon-calendar" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>',
        'alert' => '<span class="notif-icon notif-icon-alert" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>',
        'success' => '<span class="notif-icon notif-icon-success" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>',
        default => '<span class="notif-icon notif-icon-bell" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg></span>',
    };
}

function notificacionesMarcarLeidas(PDO $db, int $usuarioId, ?array $ids = null): void
{
    if (!notificacionesTablaDisponible($db)) {
        return;
    }
    if ($ids === null || $ids === []) {
        $db->prepare('UPDATE notificaciones_usuarios SET leida = 1 WHERE usuario_id = :uid')
            ->execute(['uid' => $usuarioId]);

        return;
    }
    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "UPDATE notificaciones_usuarios SET leida = 1 WHERE usuario_id = ? AND id IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$usuarioId], $ids));
}

/** Marca una notificación leída y devuelve URL de destino. */
function notificacionMarcarLeidaYObtenerUrl(PDO $db, int $usuarioId, int $notifId): ?string
{
    if (!notificacionesTablaDisponible($db)) {
        return null;
    }
    $cols = 'id, url_destino, partido_id';
    if (!notificacionesTieneUrlDestino($db)) {
        $cols = 'id, partido_id';
    }
    $stmt = $db->prepare(
        "SELECT {$cols} FROM notificaciones_usuarios WHERE id = :id AND usuario_id = :uid LIMIT 1"
    );
    $stmt->execute(['id' => $notifId, 'uid' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $db->prepare('UPDATE notificaciones_usuarios SET leida = 1 WHERE id = :id AND usuario_id = :uid')
        ->execute(['id' => $notifId, 'uid' => $usuarioId]);
    if (!empty($row['url_destino'])) {
        return (string) $row['url_destino'];
    }

    return notificacionUrlPartido(isset($row['partido_id']) ? (int) $row['partido_id'] : null);
}

function notificacionesTotalNavbar(PDO $db, int $usuarioId, int $accionesPartido = 0): int
{
    return $accionesPartido + notificacionesContadorNoLeidas($db, $usuarioId);
}

function notificacionesAvisarRivalResultadoPendiente(PDO $db, int $partidoId, int $parejaReportaId): void
{
    $etiqA = sqlParejaEtiqueta('pa', $db);
    $etiqB = sqlParejaEtiqueta('pb', $db);
    $stmt = $db->prepare(
        "SELECT p.*, ({$etiqA}) AS etiqueta_a, ({$etiqB}) AS etiqueta_b
         FROM partidos p
         INNER JOIN parejas pa ON pa.id = p.pareja_a_id
         INNER JOIN parejas pb ON pb.id = p.pareja_b_id
         WHERE p.id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $partidoId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return;
    }
    $rivalId = (int) $p['pareja_a_id'] === $parejaReportaId
        ? (int) $p['pareja_b_id']
        : (int) $p['pareja_a_id'];
    require_once __DIR__ . '/padel_league_points.php';
    $p['resultado'] = padelFormatSetsForPartido($db, $partidoId);
    $info = partidoMensajeValidacionParaPareja($db, $p, $rivalId);
    $url = notificacionUrlPartido($partidoId);
    foreach (ligaUsuariosActivosPareja($db, $rivalId) as $uid) {
        notificacionInsertarOActualizar(
            $db,
            $uid,
            $partidoId,
            'resultado_pendiente',
            $info['titulo'],
            $info['mensaje'],
            $url
        );
    }
}

/** Aviso tras validación automática a las 24 h (ambas parejas). */
function notificacionesAvisarResultadoAutoValidado(PDO $db, int $partidoId): void
{
    $stmt = $db->prepare(
        'SELECT pareja_a_id, pareja_b_id, ganador_pareja_id FROM partidos WHERE id = :id AND estado = \'validado\' LIMIT 1'
    );
    $stmt->execute(['id' => $partidoId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p || empty($p['ganador_pareja_id'])) {
        return;
    }
    $ganador = (int) $p['ganador_pareja_id'];
    $url = notificacionUrlPartido($partidoId);
    foreach ([(int) $p['pareja_a_id'], (int) $p['pareja_b_id']] as $parejaId) {
        $rivalId = $parejaId === (int) $p['pareja_a_id'] ? (int) $p['pareja_b_id'] : (int) $p['pareja_a_id'];
        $rivalNom = fetchParejaEtiqueta($db, $rivalId);
        if ($parejaId === $ganador) {
            $titulo = 'Partido validado automáticamente';
            $texto = 'Victoria confirmada contra ' . $rivalNom
                . ' (plazo de 24 h sin respuesta del rival). Clasificación actualizada.';
        } else {
            $titulo = 'Partido validado automáticamente';
            $texto = 'Derrota confirmada contra ' . $rivalNom
                . ' (resultado validado tras 24 h). El administrador puede corregirlo si hace falta.';
        }
        foreach (ligaUsuariosActivosPareja($db, $parejaId) as $uid) {
            notificacionInsertarOActualizar(
                $db,
                $uid,
                $partidoId,
                'resultado_validado',
                $titulo,
                $texto,
                $url
            );
        }
    }
}

/** Mensaje del club/admin visible en el panel de la pareja. */
function notificacionesAvisarMensajeAdminPareja(PDO $db, int $parejaId, string $mensaje, ?int $partidoId = null): void
{
    $mensaje = trim($mensaje);
    if ($mensaje === '' || !notificacionesTablaDisponible($db)) {
        return;
    }
    $titulo = 'Mensaje del administrador';
    $url = $partidoId !== null && $partidoId > 0
        ? notificacionUrlPartido($partidoId)
        : 'partidos.php';
    foreach (ligaUsuariosActivosPareja($db, $parejaId) as $uid) {
        notificacionInsertarOActualizar(
            $db,
            $uid,
            $partidoId,
            'admin_mensaje',
            $titulo,
            $mensaje,
            $url
        );
    }
}

function notificacionesAvisarResultadoValidado(PDO $db, int $partidoId, int $parejaValidaId): void
{
    $stmt = $db->prepare(
        'SELECT pareja_a_id, pareja_b_id, reportado_por_usuario_id FROM partidos WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $partidoId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return;
    }
    $repUid = (int) ($p['reportado_por_usuario_id'] ?? 0);
    $parejaReportaId = $repUid > 0 ? fetchParejaIdUsuario($db, $repUid) : null;
    if (!$parejaReportaId) {
        return;
    }
    $nombreValida = fetchParejaEtiqueta($db, $parejaValidaId);
    $texto = 'Resultado validado por ' . $nombreValida . '.';
    $url = notificacionUrlPartido($partidoId);
    foreach (ligaUsuariosActivosPareja($db, (int) $parejaReportaId) as $uid) {
        notificacionInsertarOActualizar(
            $db,
            $uid,
            $partidoId,
            'resultado_validado',
            $texto,
            $texto,
            $url
        );
    }
}
