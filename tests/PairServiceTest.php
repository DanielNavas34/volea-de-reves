<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pair_service.php';

function pairTestCrearUsuario(PDO $db, string $suffix): int
{
    $email = 'pair_test_' . $suffix . '@test.local';
    $db->prepare(
        "INSERT INTO usuarios (nombre, apellidos, email, telefono, password, rol, email_verificado, tipo_perfil)
         VALUES ('Test', :s, :email, '', 'hash', 'usuario', 1, 'liga')"
    )->execute(['s' => $suffix, 'email' => $email]);

    return (int) $db->lastInsertId();
}

function pairTestLimpiarUsuarios(PDO $db, array $userIds): void
{
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM pareja_invitaciones WHERE invitador_usuario_id = :u OR invitado_usuario_id = :u2')
            ->execute(['u' => $uid, 'u2' => $uid]);
        $db->prepare(
            'DELETE pm FROM pareja_miembros pm INNER JOIN parejas p ON p.id = pm.pareja_id
             WHERE pm.usuario_id = :u OR p.creado_por_usuario_id = :u2'
        )->execute(['u' => $uid, 'u2' => $uid]);
        $db->prepare('DELETE FROM parejas WHERE creado_por_usuario_id = :u')->execute(['u' => $uid]);
        $db->prepare('DELETE FROM usuarios WHERE id = :id AND email LIKE \'pair_test_%\'')->execute(['id' => $uid]);
    }
}

function test_pair_service_rechaza_mismo_jugador(): bool
{
    $db = getDBConnection();
    $svc = pairService($db);
    try {
        $svc->crearParejaAdmin(1, 1, null, 1);
        echo "[FAIL] pair_rechaza_mismo_jugador\n";
        return false;
    } catch (InvalidArgumentException) {
        echo "[OK] pair_rechaza_mismo_jugador\n";
        return true;
    }
}

function test_buscar_usuarios_disponibles_para_pareja(): bool
{
    $db = getDBConnection();
    $u1 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_busca1');
    $u2 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_busca2');
    $db->prepare('UPDATE usuarios SET nombre = :n, apellidos = :a WHERE id = :id')
        ->execute(['n' => 'Zelda', 'a' => 'BusquedaUnica', 'id' => $u2]);

    $hits = buscarUsuariosDisponiblesParaPareja($db, $u1, 'Zelda Busqueda', 10);
    $found = false;
    foreach ($hits as $h) {
        if ((int) $h['id'] === $u2) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "[FAIL] buscar_companero_sin_pareja\n";
        pairTestLimpiarUsuarios($db, [$u1, $u2]);
        return false;
    }

    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $svc = pairService($db);
    try {
        $svc->crearParejaAdmin($u1, $u2, null, $adminId > 0 ? $adminId : $u1);
    } catch (Throwable $e) {
        echo "[FAIL] buscar_setup_pareja: " . $e->getMessage() . "\n";
        pairTestLimpiarUsuarios($db, [$u1, $u2]);
        return false;
    }
    $u3 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_busca3');
    $hits2 = buscarUsuariosDisponiblesParaPareja($db, $u3, 'Zelda', 10);
    foreach ($hits2 as $h) {
        if ((int) $h['id'] === $u2) {
            echo "[FAIL] buscar_excluye_con_pareja\n";
            pairTestLimpiarUsuarios($db, [$u1, $u2, $u3]);
            return false;
        }
    }

    pairTestLimpiarUsuarios($db, [$u1, $u2, $u3]);
    echo "[OK] buscar_companero_sin_pareja\n";
    return true;
}

function test_pair_service_bloqueo_auto_invitacion(): bool
{
    $db = getDBConnection();
    $svc = pairService($db);
    $r = $svc->enviarInvitacionPorEmail(99, 'no-es-email');
    if ($r['ok']) {
        echo "[FAIL] pair_bloqueo_email_invalido\n";
        return false;
    }
    $r2 = $svc->enviarInvitacionPorEmail(1, 'admin@liga-padel.local');
    if ($r2['ok']) {
        echo "[FAIL] pair_bloqueo_auto_invitacion\n";
        return false;
    }
    echo "[OK] pair_bloqueo_auto_invitacion\n";
    return true;
}

function test_pair_service_crear_duplicado_e_invitacion(): bool
{
    $db = getDBConnection();
    $svc = pairService($db);
    $adminId = (int) $db->query("SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn();
    $u1 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_a');
    $u2 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_b');

    try {
        $parejaId = $svc->crearParejaAdmin($u1, $u2, 'Test Pair', $adminId > 0 ? $adminId : $u1);
        if ($svc->contarTitularesActivos($parejaId) !== 2) {
            echo "[FAIL] pair_dos_titulares\n";
            pairTestLimpiarUsuarios($db, [$u1, $u2]);
            return false;
        }
        $uExtra = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_extra');
        try {
            $svc->crearParejaAdmin($u1, $uExtra, null, $adminId);
            echo "[FAIL] pair_duplicado_deberia_fallar\n";
            pairTestLimpiarUsuarios($db, [$u1, $u2, $uExtra]);
            return false;
        } catch (InvalidArgumentException) {
            pairTestLimpiarUsuarios($db, [$uExtra]);
        }

        $u3 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_c');
        $u4 = pairTestCrearUsuario($db, bin2hex(random_bytes(3)) . '_d');
        $stmtEmail = $db->prepare('SELECT email FROM usuarios WHERE id = :id');
        $stmtEmail->execute(['id' => $u4]);
        $emailU4 = (string) $stmtEmail->fetchColumn();
        $inv = $svc->enviarInvitacionPorEmail($u3, $emailU4);
        if (!$inv['ok']) {
            echo "[FAIL] pair_invitacion_envio: " . $inv['message'] . "\n";
            pairTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
            return false;
        }
        $invRow = $db->prepare(
            "SELECT id FROM pareja_invitaciones WHERE invitado_usuario_id = :uid AND estado = 'pendiente' LIMIT 1"
        );
        $invRow->execute(['uid' => $u4]);
        $invId = (int) $invRow->fetchColumn();
        $acept = $svc->responderInvitacion($u4, $invId, 'aceptar');
        if (!$acept['ok']) {
            echo "[FAIL] pair_aceptar_invitacion: " . $acept['message'] . "\n";
            pairTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
            return false;
        }
        $parejaInv = fetchParejaActivaDeUsuario($db, $u3);
        if (!$parejaInv || $svc->contarTitularesActivos((int) $parejaInv['id']) !== 2) {
            echo "[FAIL] pair_confirmacion_dos_titulares\n";
            pairTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
            return false;
        }

        pairTestLimpiarUsuarios($db, [$u1, $u2, $u3, $u4]);
        echo "[OK] pair_crear_duplicado_e_invitacion\n";
        return true;
    } catch (Throwable $e) {
        pairTestLimpiarUsuarios($db, [$u1, $u2]);
        echo "[FAIL] pair_crear_duplicado_e_invitacion: " . $e->getMessage() . "\n";
        return false;
    }
}
