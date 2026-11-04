<?php

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/notificaciones_usuario.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php');
    exit;
}

$db = getDBConnection();
$uid = (int) $_SESSION['usuario_id'];
notificacionesMarcarLeidas($db, $uid);

$back = $_POST['redirect'] ?? 'dashboard.php';
if (!is_string($back) || !preg_match('#^[a-z_]+\.php#i', $back)) {
    $back = 'dashboard.php';
}
header('Location: ' . $back);
exit;
