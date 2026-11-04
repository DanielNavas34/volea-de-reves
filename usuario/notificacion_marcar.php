<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/notificaciones_usuario.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
}

$db = getDBConnection();
$uid = (int) $_SESSION['usuario_id'];
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit;
}

$url = notificacionMarcarLeidaYObtenerUrl($db, $uid, $id);
if ($url === null) {
    echo json_encode(['ok' => false, 'error' => 'No encontrada']);
    exit;
}

echo json_encode(['ok' => true, 'redirect' => $url]);
