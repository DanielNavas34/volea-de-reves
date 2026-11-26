<?php

require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/app_errors.php';
require_once __DIR__ . '/admin_maintenance_service.php';
require_once __DIR__ . '/password_policy.php';

const USUARIO_PERFIL_FOTO_MAX_BYTES = 2097152; // 2 MB (hosting gratuito)
const USUARIO_PERFIL_FOTO_MAX_PIXELES = 16000000; // limita la memoria al decodificar
const USUARIO_PERFIL_FOTO_MAX_LADO = 512;

/** @return list<string> */
function usuarioPerfilFotoExtensionesPermitidas(): array
{
    return ['jpg', 'jpeg', 'png', 'webp'];
}

function usuarioPerfilFotoColumnDisponible(PDO $db): bool
{
    return dbColumnExists($db, 'usuarios', 'foto_perfil');
}

function usuarioPerfilAvatarsDir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
}

function usuarioPerfilAsegurarDirectorioAvatars(): void
{
    $dir = usuarioPerfilAvatarsDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = dirname($dir) . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n");
    }
}

/** URL pública hacia la foto (desde la raíz del sitio, válida en /usuario/…). */
function usuarioPerfilFotoUrl(?string $fotoPerfil): ?string
{
    if ($fotoPerfil === null || trim($fotoPerfil) === '') {
        return null;
    }
    $fotoPerfil = str_replace('\\', '/', trim($fotoPerfil));
    if (str_contains($fotoPerfil, '..') || !str_starts_with($fotoPerfil, 'uploads/')) {
        return null;
    }

    $url = '/' . $fotoPerfil;
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fotoPerfil);
    if (is_file($fullPath)) {
        $url .= '?v=' . (string) filemtime($fullPath);
    }

    return $url;
}

function usuarioPerfilFetch(PDO $db, int $usuarioId): ?array
{
    $fotoCol = usuarioPerfilFotoColumnDisponible($db) ? ', foto_perfil' : '';
    $stmt = $db->prepare(
        "SELECT id, nombre, apellidos, email, telefono, rol, tipo_perfil, fecha_registro, email_verificado{$fotoCol}
         FROM usuarios WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function usuarioPerfilEtiquetaTipo(?string $tipo): string
{
    require_once __DIR__ . '/perfil_helpers.php';
    if (tipoPerfilEsPull($tipo)) {
        return 'Pull (pozos)';
    }

    return 'Liga por parejas';
}

function usuarioPerfilActualizarDatos(PDO $db, int $usuarioId, string $nombre, string $apellidos, string $telefono): array
{
    $nombre = trim($nombre);
    $apellidos = trim($apellidos);
    $telefono = trim($telefono);
    if ($nombre === '' || $apellidos === '') {
        return ['ok' => false, 'message' => 'Nombre y apellidos son obligatorios.'];
    }
    if (strlen($nombre) > 100 || strlen($apellidos) > 100) {
        return ['ok' => false, 'message' => 'Nombre o apellidos demasiado largos.'];
    }
    if (strlen($telefono) > 15) {
        return ['ok' => false, 'message' => 'El teléfono es demasiado largo.'];
    }

    $db->prepare(
        'UPDATE usuarios SET nombre = :n, apellidos = :a, telefono = :t WHERE id = :id'
    )->execute([
        'n' => $nombre,
        'a' => $apellidos,
        't' => $telefono,
        'id' => $usuarioId,
    ]);

    return ['ok' => true, 'message' => 'Datos actualizados correctamente.'];
}

function usuarioPerfilCambiarPassword(PDO $db, int $usuarioId, string $actual, string $nueva, string $repetir): array
{
    if ($nueva !== $repetir) {
        return ['ok' => false, 'message' => 'La nueva contraseña y la repetición no coinciden.'];
    }

    $stmt = $db->prepare('SELECT password, rol, email FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($actual, (string) $row['password'])) {
        return ['ok' => false, 'message' => 'La contraseña actual no es correcta.'];
    }
    $pwdError = passwordPolicyError($nueva, (string) ($row['email'] ?? ''));
    if ($pwdError !== null) {
        return ['ok' => false, 'message' => $pwdError];
    }

    $hash = password_hash($nueva, PASSWORD_BCRYPT);
    $hasPwdCol = dbColumnExists($db, 'usuarios', 'password_changed_at');
    if (!$hasPwdCol) {
        $db->prepare('UPDATE usuarios SET password = :p WHERE id = :id')->execute(['p' => $hash, 'id' => $usuarioId]);

        return ['ok' => true, 'message' => 'Contraseña cambiada. Usa la nueva en tu próximo inicio de sesión.'];
    }

    $db->prepare(
        'UPDATE usuarios SET password = :p, password_changed_at = NOW() WHERE id = :id'
    )->execute(['p' => $hash, 'id' => $usuarioId]);
    $stmt = $db->prepare('SELECT password_changed_at FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $usuarioId]);

    return [
        'ok' => true,
        'message' => 'Contraseña cambiada. Usa la nueva en tu próximo inicio de sesión.',
        'password_changed_at' => $stmt->fetchColumn() ?: null,
    ];
}

function usuarioPerfilEliminarArchivosFoto(int $usuarioId, ?string $exceptFilename = null): void
{
    $dir = usuarioPerfilAvatarsDir();
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'u' . $usuarioId . '[._]*') ?: [] as $path) {
        $base = basename($path);
        if ($exceptFilename !== null && $base === $exceptFilename) {
            continue;
        }
        @unlink($path);
    }
}

function usuarioPerfilFotoExtensionDesdeMime(string $mime): ?string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => null,
    };
}

/** Vuelve a generar la imagen con GD: elimina metadatos (EXIF/GPS) y cualquier contenido incrustado. */
function usuarioPerfilFotoRecodificar(string $src, string $dest, string $ext): bool
{
    $data = @file_get_contents($src);
    $img = $data !== false ? @imagecreatefromstring($data) : false;
    if ($img === false) {
        return false;
    }

    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $max = USUARIO_PERFIL_FOTO_MAX_LADO;
    if ($w > $max || $h > $max) {
        $scale = min($max / $w, $max / $h);
        $scaled = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        if ($scaled !== false) {
            imagedestroy($img);
            $img = $scaled;
        }
    }
    if ($ext !== 'jpg') {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    $ok = match ($ext) {
        'jpg' => imagejpeg($img, $dest, 85),
        'png' => imagepng($img, $dest, 6),
        'webp' => function_exists('imagewebp') && imagewebp($img, $dest, 85),
        default => false,
    };
    imagedestroy($img);

    return $ok && is_file($dest);
}

function usuarioPerfilSubirFoto(PDO $db, int $usuarioId, array $file): array
{
    if (!usuarioPerfilFotoColumnDisponible($db)) {
        return ['ok' => false, 'message' => 'La base de datos aún no tiene soporte de foto. Importa de nuevo sql/liga_padel_init.sql en una base vacía.'];
    }
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Selecciona una imagen.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'No se pudo subir la imagen. Prueba con un archivo más pequeño.'];
    }
    if ((int) ($file['size'] ?? 0) > USUARIO_PERFIL_FOTO_MAX_BYTES) {
        return ['ok' => false, 'message' => 'La imagen supera el límite de 2 MB. Comprímela e inténtalo de nuevo.'];
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, usuarioPerfilFotoExtensionesPermitidas(), true)) {
        return ['ok' => false, 'message' => 'Formato no permitido. Usa JPG, PNG o WebP.'];
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Subida no válida.'];
    }

    $info = @getimagesize($tmp);
    $ext = $info !== false ? usuarioPerfilFotoExtensionDesdeMime((string) ($info['mime'] ?? '')) : null;
    if ($ext === null) {
        return ['ok' => false, 'message' => 'El archivo no es una imagen JPG, PNG o WebP válida.'];
    }
    if ((int) $info[0] * (int) $info[1] > USUARIO_PERFIL_FOTO_MAX_PIXELES) {
        return ['ok' => false, 'message' => 'La imagen tiene demasiada resolución. Redúcela e inténtalo de nuevo.'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'message' => 'El servidor no puede procesar imágenes ahora mismo. Inténtalo más tarde.'];
    }

    usuarioPerfilAsegurarDirectorioAvatars();
    $filename = 'u' . $usuarioId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = usuarioPerfilAvatarsDir() . DIRECTORY_SEPARATOR . $filename;

    if (!usuarioPerfilFotoRecodificar($tmp, $dest, $ext)) {
        @unlink($dest);
        return ['ok' => false, 'message' => 'No se pudo procesar la imagen. Prueba con otro archivo.'];
    }
    usuarioPerfilEliminarArchivosFoto($usuarioId, $filename);

    $relative = 'uploads/avatars/' . $filename;
    $db->prepare('UPDATE usuarios SET foto_perfil = :f WHERE id = :id')->execute([
        'f' => $relative,
        'id' => $usuarioId,
    ]);

    return ['ok' => true, 'message' => 'Foto de perfil actualizada.', 'foto_perfil' => $relative];
}

function usuarioPerfilQuitarFoto(PDO $db, int $usuarioId): array
{
    if (!usuarioPerfilFotoColumnDisponible($db)) {
        return ['ok' => false, 'message' => 'La base de datos aún no tiene soporte de foto.'];
    }
    usuarioPerfilEliminarArchivosFoto($usuarioId);
    $db->prepare('UPDATE usuarios SET foto_perfil = NULL WHERE id = :id')->execute(['id' => $usuarioId]);

    return ['ok' => true, 'message' => 'Foto de perfil eliminada.'];
}

function usuarioPerfilEliminarCuenta(PDO $db, int $usuarioId, string $password, bool $confirmado): array
{
    if (!$confirmado) {
        return ['ok' => false, 'message' => 'Debes confirmar que entiendes que la baja es permanente.'];
    }

    $stmt = $db->prepare('SELECT password, rol FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Usuario no encontrado.'];
    }
    if (($row['rol'] ?? '') === 'admin') {
        return ['ok' => false, 'message' => 'Las cuentas de administrador no pueden eliminarse desde aquí.'];
    }
    if (!password_verify($password, (string) $row['password'])) {
        return ['ok' => false, 'message' => 'Contraseña incorrecta. No se ha eliminado la cuenta.'];
    }

    try {
        adminEliminarUsuarioCompleto($db, $usuarioId);
        usuarioPerfilEliminarArchivosFoto($usuarioId);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => appSafeExceptionMessage($e, 'No se pudo eliminar la cuenta. Contacta con soporte.')];
    }

    return ['ok' => true, 'message' => 'Cuenta eliminada permanentemente.'];
}
