<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/LegacySchemaTest.php';
require_once __DIR__ . '/AdminQueriesTest.php';
require_once __DIR__ . '/LigaServiceTest.php';
require_once __DIR__ . '/GuardrailsTest.php';
require_once __DIR__ . '/PartidoSetsValidateTest.php';
require_once __DIR__ . '/LigaInscripcionTest.php';
require_once __DIR__ . '/SecurityCsrfTest.php';
require_once __DIR__ . '/PairServiceTest.php';
require_once __DIR__ . '/CompetitionServiceTest.php';
require_once __DIR__ . '/MatchServiceTest.php';
require_once __DIR__ . '/ScoringValidatorTest.php';
require_once __DIR__ . '/StandingsServiceTest.php';
require_once __DIR__ . '/HistoryServiceTest.php';
require_once __DIR__ . '/PadelUiHelpersTest.php';
require_once __DIR__ . '/MailerTest.php';
require_once __DIR__ . '/PasswordResetTokenTest.php';

$failed = 0;
$passed = 0;

try {
    if (!test_legacy_schema_prefers_padel()) {
        $failed++;
    } else {
        $passed++;
    }

    foreach (['test_liga_round_robin_math', 'test_liga_round_robin_jornadas', 'test_liga_partido_fecha_futura_bloqueo'] as $fnLiga) {
        if (!function_exists($fnLiga) || !$fnLiga()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (['test_csrf_generate_and_verify', 'test_legacy_tenis_guard_function_exists', 'test_bootstrap_fase3_functions', 'test_competition_service_class_exists', 'test_match_service_class_exists', 'test_standings_service_class_exists', 'test_history_service_class_exists', 'test_padel_ui_helpers_loaded', 'test_mailer_verification_helpers', 'test_enviar_correo_pin_fake_driver', 'test_mailer_recovery_helpers', 'test_password_reset_token_hash_and_match', 'test_password_reset_legacy_plain_token', 'test_app_clean_path_strips_php', 'test_partido_cuenta_atras_formato'] as $fnSec) {
        if (!function_exists($fnSec) || !$fnSec()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (
        [
            'test_padeld_codigo_error_reporte_validado',
            'test_padeld_codigo_error_reporte_pendiente_con_ganador',
            'test_padeld_codigo_error_reporte_abierto',
            'test_padeld_codigo_error_reporte_incidencia',
        ] as $fn
    ) {
        if (!function_exists($fn) || !$fn()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    $dbGuard = getDBConnection();
    if (!test_pareja_inscripcion_viva_query($dbGuard)) {
        $failed++;
    } else {
        $passed++;
    }

    if (!test_liga_creada_cero_inscritos()) {
        $failed++;
    } else {
        $passed++;
    }

    foreach (
        [
            'test_pair_service_rechaza_mismo_jugador',
            'test_buscar_usuarios_disponibles_para_pareja',
            'test_pair_service_bloqueo_auto_invitacion',
            'test_pair_service_crear_duplicado_e_invitacion',
        ] as $fnPair
    ) {
        if (!function_exists($fnPair) || !$fnPair()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (
        [
            'test_competition_solicitud_duplicada',
            'test_competition_confirmada_clasificacion',
            'test_competition_calendario_solo_confirmadas',
        ] as $fnComp
    ) {
        if (!function_exists($fnComp) || !$fnComp()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (
        [
            'test_match_validar_crea_snapshot',
            'test_match_reporte_y_aceptar_clasificacion',
        ] as $fnMatch
    ) {
        if (!function_exists($fnMatch) || !$fnMatch()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (
        [
            'test_scoring_super_tiebreak_set3',
            'test_scoring_winner_from_two_sets',
            'test_standings_desempate_diferencia_sets',
            'test_standings_lee_configuracion',
            'test_history_service_class_and_resumen_vacio',
            'test_standings_global_ordena_grupos',
            'test_padel_html_etiqueta_dos_miembros',
            'test_padel_html_enfrentamiento',
            'test_padel_league_table_class',
        ] as $fnScore
    ) {
        if (!function_exists($fnScore) || !$fnScore()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (
        [
            'test_set_valido_6_0',
            'test_set_valido_reglas_padel',
            'test_partido_sets_from_form_6_0_6_0',
            'test_partido_sets_form_legacy_third_set_0_0',
        ] as $fnSets
    ) {
        if (!function_exists($fnSets) || !$fnSets()) {
            $failed++;
        } else {
            $passed++;
        }
    }

    foreach (run_admin_query_smoke_tests() as $row) {
        if ($row['ok']) {
            $passed++;
            echo "[OK] admin_{$row['name']}\n";
        } else {
            $failed++;
            echo "[FAIL] admin_{$row['name']}: " . ($row['error'] ?? '') . "\n";
        }
    }

    $results = run_schema_tests();
    foreach ($results as $row) {
        if ($row['ok']) {
            $passed++;
            echo "[OK] {$row['name']}\n";
        } else {
            $failed++;
            echo "[FAIL] {$row['name']}\n";
        }
    }
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nResumen: {$passed} ok, {$failed} fail\n";
exit($failed > 0 ? 1 : 0);
