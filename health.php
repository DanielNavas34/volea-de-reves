<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/load_env.php';

$required = ['usuarios', 'parejas', 'competiciones', 'partidos', 'configuracion'];

try {
    require_once __DIR__ . '/config/database.php';
    $db = getDBConnection();
    $dbName = env('DB_NAME', 'liga_padel');
    $missing = [];

    foreach ($required as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
        );
        $stmt->execute([$dbName, $table]);
        if ((int) $stmt->fetchColumn() === 0) {
            $missing[] = $table;
        }
    }

    if ($missing !== []) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'schema_incomplete'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    $payload = ['ok' => false, 'error' => 'database_unavailable'];
    if (env('APP_DEBUG', 'false') === 'true') {
        $payload['message'] = $e->getMessage();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
