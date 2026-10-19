<?php

declare(strict_types=1);

namespace Klassenziel\Import;

/**
 * Aehnlichkeit zweier Texte als Zahl zwischen 0 und 1: eins minus der
 * Levenshtein-Abstand, geteilt durch die Laenge des laengeren Textes.
 *
 * Gerechnet wird auf Zeichen, nicht auf Bytes: ein Umlaut ist in UTF-8 zwei
 * Bytes lang und zaehlte sonst doppelt.
 */
final class Aehnlichkeit
{
    public static function verhaeltnis(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        [$a, $b] = self::alsEinzelbyte($a, $b);
        $laengste = max(strlen($a), strlen($b));

        if ($laengste === 0) {
            return 1.0;
        }

        return 1.0 - levenshtein($a, $b) / $laengste;
    }

    /**
     * Bildet jedes vorkommende Zeichen auf ein eigenes Byte ab. Bis zu 256
     * verschiedene Zeichen reichen fuer zwei Klassenbuchtexte lange; darueber
     * bleibt es beim Vergleich auf Bytes.
     *
     * @return array{0: string, 1: string}
     */
    private static function alsEinzelbyte(string $a, string $b): array
    {
        $zeichenA = mb_str_split($a);
        $zeichenB = mb_str_split($b);
        $alphabet = array_values(array_unique(array_merge($zeichenA, $zeichenB)));

        if (count($alphabet) > 256) {
            return [$a, $b];
        }

        $abbildung = array_flip($alphabet);
        $umsetzen = static fn (array $zeichen): string => implode('', array_map(
            static fn (string $z): string => chr($abbildung[$z]),
            $zeichen
        ));

        return [$umsetzen($zeichenA), $umsetzen($zeichenB)];
    }
}
