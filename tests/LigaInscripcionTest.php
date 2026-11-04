<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/liga_service.php';
require_once __DIR__ . '/../includes/padel_guardrails.php';

function test_liga_creada_cero_inscritos(): bool
{
    $db = getDBConnection();
    $nombre = 'Test Liga Vacía ' . bin2hex(random_bytes(4));
    try {
        $compId = ligaCrearVacia($db, $nombre, 'general');
        $count = ligaContarInscripcionesCompeticion($db, $compId);
        competicionEliminarLigaCompleta($db, $compId);
        if ($count !== 0) {
            echo "[FAIL] liga_creada_cero_inscritos count={$count}\n";
            return false;
        }
        echo "[OK] liga_creada_cero_inscritos\n";
        return true;
    } catch (Throwable $e) {
        echo "[FAIL] liga_creada_cero_inscritos: " . $e->getMessage() . "\n";
        return false;
    }
}
