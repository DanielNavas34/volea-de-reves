<?php

require_once __DIR__ . '/legacy_schema.php';
require_once __DIR__ . '/app_errors.php';
require_once __DIR__ . '/admin_maintenance_service.php';

const USUARIO_PERFIL_FOTO_MAX_BYTES = 2097152; // 2 MB (hosting gratuito)

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
    if (strlen($nueva) < 8) {
        return ['ok' => false, 'message' => 'La nueva contraseña debe tener al menos 8 caracteres.'];
    }
    if ($nueva !== $repetir) {
        return ['ok' => false, 'message' => 'La nueva contraseña y la repetición no coinciden.'];
    }

    $stmt = $db->prepare('SELECT password, rol FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $usuarioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($actual, (string) $row['password'])) {
        return ['ok' => false, 'message' => 'La contraseña actual no es correcta.'];
    }

    $hash = password_hash($nueva, PASSWORD_BCRYPT);
    $hasPwdCol = dbColumnExists($db, 'usuarios', 'password_changed_at');
    if ($hasPwdCol) {
        $db->prepare(
            'UPDATE usuarios SET password = :p, password_changed_at = NOW() WHERE id = :id'
        )->execute(['p' => $hash, 'id' => $usuarioId]);
    } else {
        $db->prepare('UPDATE usuarios SET password = :p WHERE id = :id')->execute(['p' => $hash, 'id' => $usuarioId]);
    }

    return ['ok' => true, 'message' => 'Contraseña cambiada. Usa la nueva en tu próximo inicio de sesión.', 'password_changed_at' => date('Y-m-d H:i:s')];
}

function usuarioPerfilEliminarArchivosFoto(int $usuarioId, ?string $exceptFilename = null): void
{
    $dir = usuarioPerfilAvatarsDir();
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'u' . $usuarioId . '.*') ?: [] as $path) {
        $base = basename($path);
        if ($exceptFilename !== null && $base === $exceptFilename) {
            continue;
        }
        @unlink($path);
    }
}

function usuarioPerfilSubirFoto(PDO $db, int $usuarioId, array $file): array
{
    if (!usuarioPerfilFotoColumnDisponible($db)) {
        return ['ok' => false, 'message' => 'La base de datos aún no tiene soporte de foto. Ejecuta sql/add_foto_perfil.sql.'];
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
    if ($info === false) {
        return ['ok' => false, 'message' => 'El archivo no es una imagen válida.'];
    }

    usuarioPerfilAsegurarDirectorioAvatars();
    $filename = 'u' . $usuarioId . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = usuarioPerfilAvatarsDir() . DIRECTORY_SEPARATOR . $filename;
    usuarioPerfilEliminarArchivosFoto($usuarioId, $filename);

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'No se pudo guardar la imagen en el servidor.'];
    }

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
