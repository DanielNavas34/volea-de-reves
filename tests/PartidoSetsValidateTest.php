<?php

require_once __DIR__ . '/../includes/partido_sets_validate.php';

function test_set_valido_6_0(): bool
{
    if (!esSetValidoPadel('6', '0') || !esSetValidoPadel(6, 0) || !esSetValidoPadel('0', '6')) {
        echo "[FAIL] sets_valido_6_0\n";
        return false;
    }
    echo "[OK] sets_valido_6_0\n";
    return true;
}

function test_set_valido_reglas_padel(): bool
{
    $ok = esSetValidoPadel('6', '4')
        && esSetValidoPadel('7', '5')
        && esSetValidoPadel('6', '7')
        && !esSetValidoPadel('6', '5')
        && !esSetValidoPadel('0', '0');
    if (!$ok) {
        echo "[FAIL] sets_reglas_padel\n";
        return false;
    }
    echo "[OK] sets_reglas_padel\n";
    return true;
}

function test_partido_sets_from_form_6_0_6_0(): bool
{
    $post = [
        'set1_yo' => '6',
        'set1_op' => '0',
        'set2_yo' => '6',
        'set2_op' => '0',
        'set3_yo' => '',
        'set3_op' => '',
    ];
    try {
        $sets = partidoSetsFromForm($post, 'a');
    } catch (Throwable $e) {
        echo "[FAIL] sets_form_6_0_6_0: " . $e->getMessage() . "\n";
        return false;
    }
    if (count($sets) !== 2 || $sets[0]['juegos_a'] !== 6 || $sets[0]['juegos_b'] !== 0) {
        echo "[FAIL] sets_form_6_0_6_0_count\n";
        return false;
    }
    if (partidoSetsToString($sets) !== '6-0, 6-0') {
        echo "[FAIL] sets_form_6_0_6_0_string\n";
        return false;
    }
    echo "[OK] sets_form_6_0_6_0\n";
    return true;
}

/** Simula formulario antiguo con tercer set en 0-0 por defecto (debe ignorarse). */
function test_partido_sets_form_legacy_third_set_0_0(): bool
{
    $post = [
        'set1_yo' => '6',
        'set1_op' => '0',
        'set2_yo' => '6',
        'set2_op' => '0',
        'set3_yo' => '0',
        'set3_op' => '0',
    ];
    try {
        $sets = partidoSetsFromForm($post, 'a');
    } catch (Throwable $e) {
        echo "[FAIL] sets_form_legacy_0_0: " . $e->getMessage() . "\n";
        return false;
    }
    if (count($sets) !== 2) {
        echo "[FAIL] sets_form_legacy_0_0\n";
        return false;
    }
    echo "[OK] sets_form_legacy_0_0\n";
    return true;
}
