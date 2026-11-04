<?php

/** Códigos alineados con `partidos.formato_partido_codigo` (sql/liga_padel_init.sql). */
final class MatchFormat
{
    /** Sets a 6 juegos; TB 7-6; best of 3 (set 3 mismo formato). */
    public const BEST_OF_3_STANDARD = 'best_of_3_standard';

    /** Sets 1-2 estándar; set 3 = super tie-break (juegos 10+, diferencia 2). */
    public const BEST_OF_3_SUPER_TB = 'best_of_3_super_tb';

    public const DEFAULT = self::BEST_OF_3_STANDARD;

    /** @return list<string> */
    public static function supported(): array
    {
        return [self::BEST_OF_3_STANDARD, self::BEST_OF_3_SUPER_TB];
    }

    public static function normalize(?string $code): string
    {
        $code = $code !== null ? trim($code) : '';

        return in_array($code, self::supported(), true) ? $code : self::DEFAULT;
    }

    public static function maxSets(string $formatCode): int
    {
        return 3;
    }
}
