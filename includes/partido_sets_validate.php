<?php

require_once __DIR__ . '/scoring/MatchFormat.php';
require_once __DIR__ . '/scoring/ScoringValidator.php';

/**
 * ¿El par de juegos del formulario está sin rellenar (set no jugado)?
 */
function partidoSetParVacio($g1, $g2): bool
{
    $s1 = $g1 === null ? '' : trim((string) $g1);
    $s2 = $g2 === null ? '' : trim((string) $g2);
    if ($s1 === '' && $s2 === '') {
        return true;
    }
    if ($s1 === '' || $s2 === '') {
        return false;
    }
    $n1 = (int) $s1;
    $n2 = (int) $s2;

    return $n1 === 0 && $n2 === 0;
}

function esSetValidoPadel($g1, $g2, int $setNumber = 1, ?string $formatCode = null): bool
{
    return (new ScoringValidator(MatchFormat::normalize($formatCode)))->isValidSet($g1, $g2, $setNumber);
}

function ganadorSetPadel($g1, $g2, int $setNumber = 1, ?string $formatCode = null): ?string
{
    return (new ScoringValidator(MatchFormat::normalize($formatCode)))->winnerSideFromSet($g1, $g2, $setNumber);
}

/** @return list<array{numero:int,juegos_a:int,juegos_b:int}> */
function partidoSetsFromForm(array $post, string $ladoYo = 'a', ?string $formatCode = null): array
{
    $validator = new ScoringValidator(MatchFormat::normalize($formatCode));
    $sets = [];
    for ($s = 1; $s <= MatchFormat::maxSets(MatchFormat::normalize($formatCode)); $s++) {
        $yo = $post["set{$s}_yo"] ?? '';
        $op = $post["set{$s}_op"] ?? '';
        if (partidoSetParVacio($yo, $op)) {
            continue;
        }
        $yoStr = trim((string) $yo);
        $opStr = trim((string) $op);
        if ($yoStr === '' || $opStr === '') {
            throw new InvalidArgumentException('Set ' . $s . ': indica juegos para ambas parejas o déjalo vacío.');
        }
        if (!$validator->isValidSet($yoStr, $opStr, $s)) {
            throw new InvalidArgumentException('Set ' . $s . ' no válido (pádel: 6-0…6-4, 7-5 o 7-6).');
        }
        $sets[] = [
            'numero' => $s,
            'juegos_a' => $ladoYo === 'a' ? (int) $yoStr : (int) $opStr,
            'juegos_b' => $ladoYo === 'a' ? (int) $opStr : (int) $yoStr,
        ];
    }
    if ($sets !== []) {
        $validator->assertEncounterRules($sets);
    }

    return $sets;
}

function partidoSetsToString(array $sets): string
{
    $parts = [];
    foreach ($sets as $set) {
        $parts[] = $set['juegos_a'] . '-' . $set['juegos_b'];
    }

    return implode(', ', $parts);
}
