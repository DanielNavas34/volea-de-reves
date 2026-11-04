<?php

require_once __DIR__ . '/MatchFormat.php';

final class ScoringValidator
{
    public function __construct(private string $formatCode = MatchFormat::DEFAULT)
    {
        $this->formatCode = MatchFormat::normalize($formatCode);
    }

    public function withFormat(string $formatCode): self
    {
        return new self($formatCode);
    }

    public function isValidSet($g1, $g2, int $setNumber = 1): bool
    {
        if ($this->formatCode === MatchFormat::BEST_OF_3_SUPER_TB && $setNumber === 3) {
            return $this->isValidSuperTiebreakSet($g1, $g2);
        }

        return $this->isValidStandardSet($g1, $g2);
    }

    public function isValidStandardSet($g1, $g2): bool
    {
        if ($g1 === null || $g2 === null) {
            return false;
        }
        $s1 = trim((string) $g1);
        $s2 = trim((string) $g2);
        if ($s1 === '' || $s2 === '') {
            return false;
        }
        if (!is_numeric($s1) || !is_numeric($s2)) {
            return false;
        }
        $j1 = (int) $s1;
        $j2 = (int) $s2;
        if ($j1 < 0 || $j2 < 0 || $j1 > 7 || $j2 > 7) {
            return false;
        }
        if ($j1 === $j2) {
            return false;
        }
        $max = max($j1, $j2);
        $min = min($j1, $j2);
        if ($max === 6 && $min <= 4) {
            return true;
        }
        if ($max === 7 && in_array($min, [5, 6], true)) {
            return true;
        }

        return false;
    }

    /** Super TB: primer equipo a 10 con diferencia ≥ 2 (tope 15). */
    public function isValidSuperTiebreakSet($g1, $g2): bool
    {
        if ($g1 === null || $g2 === null) {
            return false;
        }
        if (!is_numeric($g1) || !is_numeric($g2)) {
            return false;
        }
        $j1 = (int) $g1;
        $j2 = (int) $g2;
        if ($j1 < 0 || $j2 < 0 || $j1 === $j2) {
            return false;
        }
        $max = max($j1, $j2);
        $min = min($j1, $j2);
        if ($max > 15 || $max < 10) {
            return false;
        }

        return ($max - $min) >= 2;
    }

    /** @return 'a'|'b'|null */
    public function winnerSideFromSet($g1, $g2, int $setNumber = 1): ?string
    {
        if (!$this->isValidSet($g1, $g2, $setNumber)) {
            return null;
        }
        $j1 = (int) $g1;
        $j2 = (int) $g2;

        return $j1 > $j2 ? 'a' : 'b';
    }

    /**
     * @param list<array{numero:int,juegos_a:int,juegos_b:int}> $sets
     *
     * @return 'a'|'b'|null
     */
    public function winnerSideFromSets(array $sets): ?string
    {
        $winsA = 0;
        $winsB = 0;
        foreach ($sets as $set) {
            $side = $this->winnerSideFromSet(
                $set['juegos_a'],
                $set['juegos_b'],
                (int) ($set['numero'] ?? 1)
            );
            if ($side === 'a') {
                $winsA++;
            } elseif ($side === 'b') {
                $winsB++;
            }
        }
        if ($winsA >= 2) {
            return 'a';
        }
        if ($winsB >= 2) {
            return 'b';
        }

        return null;
    }

    /**
     * @param list<array{numero:int,juegos_a:int,juegos_b:int}> $sets
     */
    public function assertEncounterRules(array $sets): void
    {
        if ($sets === []) {
            throw new InvalidArgumentException('Indica al menos un set.');
        }
        foreach ($sets as $set) {
            $n = (int) ($set['numero'] ?? 0);
            if (!$this->isValidSet($set['juegos_a'], $set['juegos_b'], $n > 0 ? $n : 1)) {
                throw new InvalidArgumentException('Set ' . $n . ' no válido para formato ' . $this->formatCode . '.');
            }
        }
        $winner = $this->winnerSideFromSets($sets);
        if ($winner === null) {
            throw new InvalidArgumentException('El marcador no define un ganador (best of 3).');
        }
    }
}
