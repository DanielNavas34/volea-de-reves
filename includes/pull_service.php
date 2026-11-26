<?php

require_once __DIR__ . '/perfil_helpers.php';

function pullInscripcionesTableExists(PDO $db): bool
{
    $stmt = $db->query("SHOW TABLES LIKE 'inscripciones_pull'");

    return (bool) $stmt->fetchColumn();
}

/** @return list<array<string, mixed>> */
function fetchPullsAbiertas(PDO $db): array
{
    $tipoIn = competicionTipoPullSqlIn();

    return $db->query(
        "SELECT id, nombre AS titulo, descripcion, fecha_inicio, fecha_fin, estado
         FROM competiciones
         WHERE tipo IN ({$tipoIn}) AND estado IN ('abierta', 'en_curso')
           AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
         ORDER BY COALESCE(fecha_inicio, '9999-12-31') ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<int> */
function fetchPullIdsInscritoUsuario(PDO $db, int $usuarioId): array
{
    if (!pullInscripcionesTableExists($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT competicion_id FROM inscripciones_pull WHERE usuario_id = :uid');
    $stmt->execute(['uid' => $usuarioId]);

    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'competicion_id'));
}

function inscribirUsuarioEnPull(PDO $db, int $usuarioId, int $competicionId): void
{
    if (!pullInscripcionesTableExists($db)) {
        throw new RuntimeException('La tabla inscripciones_pull no existe. El esquema de la base de datos no está actualizado.');
    }

    $tipoIn = competicionTipoPullSqlIn();
    $stmt = $db->prepare(
        "SELECT id FROM competiciones
         WHERE id = :id AND tipo IN ({$tipoIn}) AND estado IN ('abierta', 'en_curso')
         LIMIT 1"
    );
    $stmt->execute(['id' => $competicionId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('Esta Pull no admite inscripciones ahora.');
    }

    $db->prepare(
        "INSERT INTO inscripciones_pull (competicion_id, usuario_id, estado) VALUES (:cid, :uid, 'pendiente')"
    )->execute(['cid' => $competicionId, 'uid' => $usuarioId]);
}

/** @return list<array<string, mixed>> */
function fetchMisPullsUsuario(PDO $db, int $usuarioId): array
{
    if (!pullInscripcionesTableExists($db)) {
        return [];
    }
    $tipoIn = competicionTipoPullSqlIn();

    $stmt = $db->prepare(
        "SELECT c.id AS pull_id, c.nombre AS titulo, c.fecha_inicio, c.fecha_fin, c.estado AS estado_pull,
                ip.estado, ip.fecha_inscripcion,
                CONCAT(u.nombre, ' ', u.apellidos) AS jugador_nombre
         FROM inscripciones_pull ip
         INNER JOIN competiciones c ON c.id = ip.competicion_id AND c.tipo IN ({$tipoIn})
         INNER JOIN usuarios u ON u.id = ip.usuario_id
         WHERE ip.usuario_id = :uid
         ORDER BY c.fecha_inicio DESC"
    );
    $stmt->execute(['uid' => $usuarioId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
