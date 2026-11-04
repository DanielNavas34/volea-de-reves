<?php

require_once __DIR__ . '/usuario_perfil_service.php';

function usuarioAvatarIniciales(string $nombre, string $apellidos): string
{
    $a = strtoupper(substr(trim($nombre), 0, 1));
    $b = strtoupper(substr(trim($apellidos), 0, 1));

    return $a . $b;
}

/** @return array{nombre: string, apellidos: string, foto_perfil: ?string} */
function usuarioAvatarContextFromDb(PDO $db, int $usuarioId): array
{
    $fotoCol = usuarioPerfilFotoColumnDisponible($db) ? ', foto_perfil' : '';
    $stmt = $db->prepare("SELECT nombre, apellidos{$fotoCol} FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'nombre' => (string) ($row['nombre'] ?? ''),
        'apellidos' => (string) ($row['apellidos'] ?? ''),
        'foto_perfil' => isset($row['foto_perfil']) && $row['foto_perfil'] !== '' ? (string) $row['foto_perfil'] : null,
    ];
}

function usuarioAvatarRefreshSession(PDO $db, int $usuarioId): void
{
    $ctx = usuarioAvatarContextFromDb($db, $usuarioId);
    $_SESSION['nombre'] = $ctx['nombre'];
    $_SESSION['usuario_apellidos'] = $ctx['apellidos'];
    $_SESSION['usuario_foto_perfil'] = $ctx['foto_perfil'];
}

/** @return array{nombre: string, apellidos: string, foto_perfil: ?string}|null */
function usuarioAvatarContextForSession(?PDO $db = null): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    $nombre = (string) ($_SESSION['nombre'] ?? '');
    $apellidos = (string) ($_SESSION['usuario_apellidos'] ?? '');
    $foto = $_SESSION['usuario_foto_perfil'] ?? null;
    if ($foto === '') {
        $foto = null;
    }

    if ($apellidos === '' && $db instanceof PDO) {
        usuarioAvatarRefreshSession($db, (int) $_SESSION['usuario_id']);
        $nombre = (string) ($_SESSION['nombre'] ?? '');
        $apellidos = (string) ($_SESSION['usuario_apellidos'] ?? '');
        $foto = $_SESSION['usuario_foto_perfil'] ?? null;
        if ($foto === '') {
            $foto = null;
        }
    }

    if ($nombre === '') {
        return null;
    }

    return [
        'nombre' => $nombre,
        'apellidos' => $apellidos,
        'foto_perfil' => is_string($foto) ? $foto : null,
    ];
}

/**
 * @param array{nombre?: string, apellidos?: string, foto_perfil?: ?string} $user
 */
function usuarioAvatarHtml(array $user, int $sizePx = 44, string $extraClass = ''): string
{
    $nombre = (string) ($user['nombre'] ?? '');
    $apellidos = (string) ($user['apellidos'] ?? '');
    $fotoUrl = usuarioPerfilFotoUrl($user['foto_perfil'] ?? null);
    $class = trim('usuario-avatar ' . $extraClass);
    $size = max(28, min(120, $sizePx));

    if ($fotoUrl !== null) {
        return sprintf(
            '<span class="%s" style="width:%dpx;height:%dpx" role="img" aria-label="Foto de %s">' .
            '<img src="%s" alt="" class="usuario-avatar-img" width="%2$d" height="%2$d" decoding="async">' .
            '</span>',
            htmlspecialchars($class),
            $size,
            $size,
            htmlspecialchars($nombre),
            htmlspecialchars($fotoUrl)
        );
    }

    $iniciales = usuarioAvatarIniciales($nombre, $apellidos);

    $fontSize = max(12, (int) round($size * 0.38));

    return sprintf(
        '<span class="%s usuario-avatar--iniciales" style="width:%dpx;height:%dpx" role="img" aria-label="Avatar de %s">' .
        '<span class="usuario-avatar-iniciales-text" style="font-size:%dpx">%s</span></span>',
        htmlspecialchars($class),
        $size,
        $size,
        htmlspecialchars(trim($nombre . ' ' . $apellidos)),
        $fontSize,
        htmlspecialchars($iniciales)
    );
}

/** Saludo «Hola, Nombre» con avatar (HTML seguro para echo). */
function usuarioSaludoConAvatarHtml(array $user, int $avatarSize = 44, string $wrapClass = 'usuario-saludo-chip'): string
{
    $nombre = (string) ($user['nombre'] ?? 'jugador');
    $avatar = usuarioAvatarHtml($user, $avatarSize, 'usuario-avatar--saludo');

    return '<span class="' . htmlspecialchars($wrapClass) . '">' . $avatar .
        '<span class="usuario-saludo-texto">Hola, <strong>' . htmlspecialchars($nombre) . '</strong></span></span>';
}
