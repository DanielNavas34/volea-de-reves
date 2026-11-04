<?php

require_once __DIR__ . '/../includes/security.php';

function test_csrf_generate_and_verify(): bool
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $token = generateCSRFToken();
    if ($token === '' || !verifyCSRFToken($token)) {
        echo "[FAIL] csrf_generate_and_verify\n";
        return false;
    }
    if (verifyCSRFToken('token-invalido')) {
        echo "[FAIL] csrf_rejects_invalid\n";
        return false;
    }
    echo "[OK] csrf_generate_and_verify\n";
    return true;
}

function test_legacy_tenis_guard_function_exists(): bool
{
    require_once __DIR__ . '/../includes/legacy_tenis_guard.php';
    if (!function_exists('legacyTenisRoutesBlocked') || !function_exists('redirectLegacyTenisBlocked')) {
        echo "[FAIL] legacy_tenis_guard_functions\n";
        return false;
    }
    echo "[OK] legacy_tenis_guard_functions\n";
    return true;
}

function test_padel_ui_helpers_loaded(): bool
{
    require_once __DIR__ . '/../includes/padel_ui_helpers.php';
    if (!function_exists('padelHtmlEtiquetaPareja') || !function_exists('padelLeagueTableClass')) {
        echo "[FAIL] padel_ui_helpers\n";
        return false;
    }
    echo "[OK] padel_ui_helpers\n";
    return true;
}

function test_history_service_class_exists(): bool
{
    require_once __DIR__ . '/../includes/history_service.php';
    if (!class_exists('LeagueHistoryService') || !function_exists('leagueHistoryService')) {
        echo "[FAIL] history_service_class\n";
        return false;
    }
    echo "[OK] history_service_class\n";
    return true;
}

function test_standings_service_class_exists(): bool
{
    require_once __DIR__ . '/../includes/standings_service.php';
    if (!class_exists('LeagueStandingsService') || !function_exists('leagueStandingsService')) {
        echo "[FAIL] standings_service_class\n";
        return false;
    }
    echo "[OK] standings_service_class\n";
    return true;
}

function test_match_service_class_exists(): bool
{
    require_once __DIR__ . '/../includes/match_service.php';
    if (!class_exists('LeagueMatchService') || !function_exists('leagueMatchService')) {
        echo "[FAIL] match_service_class\n";
        return false;
    }
    echo "[OK] match_service_class\n";
    return true;
}

function test_competition_service_class_exists(): bool
{
    require_once __DIR__ . '/../includes/competition_service.php';
    if (!class_exists('LeagueCompetitionService') || !function_exists('leagueCompetitionService')) {
        echo "[FAIL] competition_service_class\n";
        return false;
    }
    echo "[OK] competition_service_class\n";
    return true;
}

function test_bootstrap_fase3_functions(): bool
{
    require_once __DIR__ . '/../includes/bootstrap.php';
    foreach (['appEnsureSession', 'requireVoleaPadelInstall', 'voleaPadelInstallReadyOrEmpty', 'instalacionRequeridaUrl'] as $fn) {
        if (!function_exists($fn)) {
            echo "[FAIL] bootstrap_missing_{$fn}\n";
            return false;
        }
    }
    echo "[OK] bootstrap_fase3_functions\n";
    return true;
}
