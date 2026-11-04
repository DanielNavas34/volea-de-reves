<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/parejas_helpers.php';
require_once __DIR__ . '/../includes/usuario_padel_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['usuarios' => [], 'error' => 'no_auth']);
    exit;
}

$db = getDBConnection();
if (!usuarioEsquemaPadel($db)) {
    echo json_encode(['usuarios' => []]);
    exit;
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$rows = buscarUsuariosDisponiblesParaPareja($db, $usuarioId, $q, 20);
$usuarios = [];
foreach ($rows as $row) {
    $nombre = trim((string) ($row['nombre'] ?? ''));
    $apellidos = trim((string) ($row['apellidos'] ?? ''));
    $usuarios[] = [
        'id' => (int) $row['id'],
        'nombre' => $nombre,
        'apellidos' => $apellidos,
        'label' => trim($nombre . ' ' . $apellidos),
    ];
}

echo json_encode(['usuarios' => $usuarios]);
