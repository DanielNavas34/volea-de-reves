<?php

require_once __DIR__ . '/../includes/scoring/MatchFormat.php';
require_once __DIR__ . '/../includes/scoring/ScoringValidator.php';

function test_scoring_super_tiebreak_set3(): bool
{
    $v = new ScoringValidator(MatchFormat::BEST_OF_3_SUPER_TB);
    if (!$v->isValidSet(10, 8, 3) || !$v->isValidSet(6, 4, 1) || $v->isValidSet(10, 8, 2)) {
        echo "[FAIL] scoring_super_tiebreak_set3\n";
        return false;
    }
    echo "[OK] scoring_super_tiebreak_set3\n";
    return true;
}

function test_scoring_winner_from_two_sets(): bool
{
    $v = new ScoringValidator();
    $side = $v->winnerSideFromSets([
        ['numero' => 1, 'juegos_a' => 6, 'juegos_b' => 2],
        ['numero' => 2, 'juegos_a' => 6, 'juegos_b' => 4],
    ]);
    if ($side !== 'a') {
        echo "[FAIL] scoring_winner_from_two_sets\n";
        return false;
    }
    echo "[OK] scoring_winner_from_two_sets\n";
    return true;
}
