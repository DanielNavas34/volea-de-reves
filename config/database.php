<?php
// config/database.php — conexión PDO (credenciales vía .env / entorno)

require_once __DIR__ . '/../includes/load_env.php';

function getDBConnection(): PDO
{
    $host = env('DB_HOST', 'db');
    $port = env('DB_PORT', '3306');
    $dbname = env('DB_NAME', 'liga_padel');
    $username = env('DB_USER', 'admin_padel');
    $password = env('DB_PASSWORD', '');
    $charset = env('DB_CHARSET', 'utf8mb4');

    if ($password === '') {
        throw new RuntimeException(
            'DB_PASSWORD no configurada. Copia .env.example a .env y define las credenciales.'
        );
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO($dsn, $username, $password, $options);
    } catch (PDOException $e) {
        if (env('APP_DEBUG', 'false') === 'true') {
            throw $e;
        }
        throw new RuntimeException('Error de conexión a la base de datos.');
    }
}
