<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/legacy_schema.php';
require_once __DIR__ . '/../includes/parejas_helpers.php';

function run_admin_query_smoke_tests(): array
{
    $db = getDBConnection();
    $results = [];

    $try = static function (string $name, callable $fn) use (&$results): void {
        try {
            $fn();
            $results[] = ['name' => $name, 'ok' => true];
        } catch (Throwable $e) {
            $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
        }
    };

    $try('dashboard_stats', function () use ($db) {
        $db->query("SELECT COUNT(*) FROM competicion_grupos")->fetchColumn();
        $db->query("SELECT COUNT(*) FROM partidos WHERE estado = 'validado'")->fetchColumn();
    });

    $try('usuarios_list_padel', function () use ($db) {
        $etiq = sqlParejaEtiqueta('p', $db);
        $db->query(
            "SELECT u.id FROM usuarios u
             LEFT JOIN pareja_miembros pm ON pm.usuario_id = u.id AND pm.activo = 1
             LEFT JOIN parejas p ON p.id = pm.pareja_id
             WHERE u.rol = 'usuario' LIMIT 1"
        )->fetch();
    });

    $try('parejas_list', function () use ($db) {
        fetchParejasActivasAdmin($db);
    });

    $try('partidos_list', function () use ($db) {
        $db->query(
            "SELECT p.id FROM partidos p
             INNER JOIN competiciones c ON c.id = p.competicion_id LIMIT 1"
        )->fetch();
    });

    $try('torneos_admin', function () use ($db) {
        require_once __DIR__ . '/../includes/competiciones_helpers.php';
        fetchTorneosAdmin($db);
    });

    $try('usuario_padel_helpers', function () use ($db) {
        require_once __DIR__ . '/../includes/usuario_padel_helpers.php';
        fetchParejaIdUsuario($db, 1);
        $db->query(
            "SELECT id FROM competiciones WHERE tipo IN ('pull','torneo') LIMIT 1"
        )->fetch();
    });

    return $results;
}
