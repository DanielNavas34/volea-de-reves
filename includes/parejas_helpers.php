<?php

require_once __DIR__ . '/legacy_schema.php';

/** ¿La tabla parejas incluye nombre_display? (BD creadas antes del init actual pueden no tenerla). */
function parejasTieneNombreDisplay(PDO $db): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = dbColumnExists($db, 'parejas', 'nombre_display');
    }

    return $cache;
}

/** Subconsulta SQL: etiqueta legible de una pareja (alias de tabla parejas: p). */
function sqlParejaEtiqueta(string $parejaAlias = 'p', ?PDO $db = null): string
{
    if ($db !== null) {
        parejasTieneNombreDisplay($db);
    }
    $p = $parejaAlias;
    $miembros = "(SELECT GROUP_CONCAT(CONCAT(u.nombre, ' ', u.apellidos) ORDER BY pm.id SEPARATOR ' / ')
         FROM pareja_miembros pm
         INNER JOIN usuarios u ON u.id = pm.usuario_id
         WHERE pm.pareja_id = {$p}.id AND pm.activo = 1)";
    $fallback = "CONCAT('Pareja #', {$p}.id)";
    if ($db !== null && parejasTieneNombreDisplay($db)) {
        return "COALESCE(NULLIF(TRIM({$p}.nombre_display), ''), NULLIF({$miembros}, ''), {$fallback})";
    }
    if ($db === null) {
        return "COALESCE(NULLIF({$miembros}, ''), {$fallback})";
    }

    return "COALESCE(NULLIF({$miembros}, ''), {$fallback})";
}

function fetchParejaEtiqueta(PDO $db, int $parejaId): string
{
    $sql = 'SELECT ' . sqlParejaEtiqueta('p', $db) . ' AS etiqueta FROM parejas p WHERE p.id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute(['id' => $parejaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && $row['etiqueta'] ? (string) $row['etiqueta'] : ('Pareja #' . $parejaId);
}

/** Parejas activas con miembros para selects admin. */
function fetchParejasActivasAdmin(PDO $db): array
{
    $etiq = sqlParejaEtiqueta('p', $db);
    $sql = "SELECT p.id, p.estado, ({$etiq}) AS etiqueta
            FROM parejas p
            WHERE p.estado IN ('activa', 'borrador')
            ORDER BY etiqueta ASC";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Usuario con pareja activa (si existe). */
function fetchParejaActivaDeUsuario(PDO $db, int $usuarioId): ?array
{
    $etiq = sqlParejaEtiqueta('p', $db);
    $notaCol = dbColumnExists($db, 'parejas', 'nota_admin') ? ', p.nota_admin' : '';
    $sql = "SELECT p.id, p.estado, ({$etiq}) AS etiqueta{$notaCol}
            FROM pareja_miembros pm
            INNER JOIN parejas p ON p.id = pm.pareja_id
            WHERE pm.usuario_id = :uid AND pm.activo = 1 AND p.estado IN ('activa', 'borrador')
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute(['uid' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function usuarioTieneParejaActiva(PDO $db, int $usuarioId, ?int $exceptParejaId = null): bool
{
    $sql = "SELECT 1 FROM pareja_miembros pm
            INNER JOIN parejas p ON p.id = pm.pareja_id
            WHERE pm.usuario_id = :uid AND pm.activo = 1 AND p.estado IN ('activa', 'borrador')";
    $params = ['uid' => $usuarioId];
    if ($exceptParejaId !== null) {
        $sql .= ' AND p.id != :pid';
        $params['pid'] = $exceptParejaId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function fetchMiembrosPareja(PDO $db, int $parejaId): array
{
    $stmt = $db->prepare(
        "SELECT u.id, u.nombre, u.apellidos, u.email, pm.activo, pm.rol
         FROM pareja_miembros pm
         INNER JOIN usuarios u ON u.id = pm.usuario_id
         WHERE pm.pareja_id = :pid AND pm.activo = 1
         ORDER BY pm.id ASC"
    );
    $stmt->execute(['pid' => $parejaId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminCrearPareja(PDO $db, int $usuario1Id, int $usuario2Id, ?string $nombreDisplay, int $creadorId): int
{
    if ($usuario1Id === $usuario2Id) {
        throw new InvalidArgumentException('Los dos jugadores deben ser distintos.');
    }
    if (usuarioTieneParejaActiva($db, $usuario1Id) || usuarioTieneParejaActiva($db, $usuario2Id)) {
        throw new InvalidArgumentException('Uno de los jugadores ya pertenece a otra pareja activa.');
    }

    $db->beginTransaction();
    try {
        if (parejasTieneNombreDisplay($db)) {
            $stmt = $db->prepare(
                "INSERT INTO parejas (nombre_display, estado, creado_por_usuario_id)
                 VALUES (:nombre, 'activa', :creador)"
            );
            $stmt->execute([
                'nombre' => $nombreDisplay !== '' && $nombreDisplay !== null ? $nombreDisplay : null,
                'creador' => $creadorId,
            ]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO parejas (estado, creado_por_usuario_id) VALUES ('activa', :creador)"
            );
            $stmt->execute(['creador' => $creadorId]);
        }
        $parejaId = (int) $db->lastInsertId();

        $ins = $db->prepare(
            "INSERT INTO pareja_miembros (pareja_id, usuario_id, rol, activo) VALUES (:pid, :uid, 'titular', 1)"
        );
        $ins->execute(['pid' => $parejaId, 'uid' => $usuario1Id]);
        $ins->execute(['pid' => $parejaId, 'uid' => $usuario2Id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $parejaId;
}

/** Subconsulta SQL: usuario sin pareja activa ni borrador en curso. */
function sqlUsuarioDisponibleParaNuevaPareja(string $usuarioAlias = 'u'): string
{
    return "NOT EXISTS (
        SELECT 1 FROM pareja_miembros pm
        INNER JOIN parejas p ON p.id = pm.pareja_id
        WHERE pm.usuario_id = {$usuarioAlias}.id AND pm.activo = 1
          AND p.estado IN ('activa', 'borrador')
    )";
}

/**
 * Jugadores sin pareja que coinciden con nombre/apellidos (tokens AND).
 *
 * @return list<array{id:int,nombre:string,apellidos:string}>
 */
function buscarUsuariosDisponiblesParaPareja(PDO $db, int $invitadorId, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($invitadorId <= 0 || mb_strlen($query) < 2) {
        return [];
    }
    $tokens = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false || $tokens === []) {
        return [];
    }

    $where = [
        "u.activo = 1",
        "u.rol = 'usuario'",
        'u.id != :invitador',
        sqlUsuarioDisponibleParaNuevaPareja('u'),
        "NOT EXISTS (
            SELECT 1 FROM pareja_invitaciones pi
            WHERE pi.invitado_usuario_id = u.id AND pi.estado = 'pendiente' AND pi.expira_en > NOW()
        )",
    ];
    $params = ['invitador' => $invitadorId];
    $i = 0;
    foreach ($tokens as $token) {
        if (mb_strlen($token) < 2) {
            continue;
        }
        $key = 't' . $i;
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $token) . '%';
        $where[] = "(u.nombre LIKE :{$key}n OR u.apellidos LIKE :{$key}a
            OR CONCAT(u.nombre, ' ', u.apellidos) LIKE :{$key}f
            OR CONCAT(u.apellidos, ' ', u.nombre) LIKE :{$key}r)";
        $params[$key . 'n'] = $like;
        $params[$key . 'a'] = $like;
        $params[$key . 'f'] = $like;
        $params[$key . 'r'] = $like;
        $i++;
    }
    if ($i === 0) {
        return [];
    }

    $limit = max(1, min(30, $limit));
    $sql = 'SELECT u.id, u.nombre, u.apellidos FROM usuarios u WHERE ' . implode(' AND ', $where)
        . ' ORDER BY u.apellidos ASC, u.nombre ASC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
