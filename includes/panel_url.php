<?php

require_once __DIR__ . '/perfil_helpers.php';

function panelUrlForSession(): string
{
    if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin') {
        return 'admin/dashboard';
    }
    if (tipoPerfilEsPull($_SESSION['tipo_perfil'] ?? null)) {
        return 'usuario/mis_pulls';
    }

    return 'usuario/dashboard';
}
