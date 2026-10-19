<?php

declare(strict_types=1);

namespace Klassenziel\Import;

use Klassenziel\Sicherheit\Schluessel;

/**
 * Die Teile eines Namens aus der Spalte "Name" - und was der Import damit tut.
 *
 * Zweierlei:
 *  - Fuer die Gruppierung werden sie im Text durch einen Platzhalter ersetzt.
 *    "Ben verlaesst in der Pause das Gelaende" und "Tina verlaesst ..." sind
 *    danach derselbe Text, also derselbe Vorfall.
 *  - Fuer die Namenswarnung beim Kurztext bleiben sie als Kennung (HMAC)
 *    erhalten. Der Name selbst wird nie gespeichert.
 *
 * Das ersetzt nicht zuverlaessig jede Erwaehnung: "I." oder eine andere
 * Schreibweise im Text findet es nur, wenn sie zum Namen passt. Deshalb ist
 * das hier nie der einzige Schutz - der Originaltext bleibt verschluesselt und
 * erscheint nie oeffentlich.
 */
final class Namensbestandteile
{
    public const PLATZHALTER = '⟨Name⟩';
    private const ZWECK = 'name';

    /**
     * @return list<string> der ganze Name und seine Teile, laengste zuerst
     */
    public static function aus(string $name): array
    {
        $name = preg_replace('/\s+/u', ' ', Normalisierung::feld($name)) ?? '';
        if ($name === '') {
            return [];
        }

        $teile = [$name];
        foreach (preg_split('/[\s,;\/]+/u', $name) ?: [] as $teil) {
            $teile[] = $teil;
            // Doppelnamen: "Anna-Lena" steht im Text auch mal als "Anna".
            if (str_contains($teil, '-')) {
                array_push($teile, ...explode('-', $teil));
            }
        }

        $teile = array_values(array_unique(array_filter(
            array_map(static fn (string $t): string => trim($t, " \t.-"), $teile),
            static fn (string $t): bool => mb_strlen($t) >= 2
        )));

        usort($teile, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $teile;
    }

    /**
     * Ersetzt die Namensteile im Text durch den Platzhalter: als ganzes Wort,
     * ohne Ruecksicht auf Gross- und Kleinschreibung, dazu Initialen wie "B.".
     *
     * @param list<string> $teile
     */
    public static function ersetze(string $text, array $teile): string
    {
        foreach ($teile as $teil) {
            $muster = '/(?<![\p{L}\p{N}])' . preg_quote($teil, '/') . '(?![\p{L}\p{N}])/iu';
            $text = preg_replace($muster, self::PLATZHALTER, $text) ?? $text;
        }

        // Initialen nur gross geschrieben und mit Punkt - aber nicht die
        // gaengigen Abkuerzungen "z. B." und "u. U.".
        foreach ($teile as $teil) {
            $initiale = mb_substr($teil, 0, 1);
            if ($initiale === mb_strtoupper($initiale) && preg_match('/\p{Lu}/u', $initiale) === 1) {
                $muster = '/(?<![\p{L}\p{N}])(?<!z\.\s)(?<!z\.)(?<!u\.\s)(?<!u\.)'
                    . preg_quote($initiale, '/') . '\.(?=\s|$|[,;:)!?])/u';
                $text = preg_replace($muster, self::PLATZHALTER, $text) ?? $text;
            }
        }

        return $text;
    }

    /**
     * Kennungen der Namensteile, fuer die Warnung beim Kurztext.
     *
     * @param list<string> $teile
     * @return list<string>
     */
    public static function kennungen(Schluessel $schluessel, array $teile): array
    {
        $kennungen = [];
        foreach ($teile as $teil) {
            // Nur einzelne Woerter: ein Kurztext wird Wort fuer Wort geprueft.
            if (preg_match('/\s/u', $teil) === 1) {
                continue;
            }
            $kennungen[] = self::kennung($schluessel, $teil);
        }

        $kennungen = array_values(array_unique($kennungen));
        sort($kennungen);

        return $kennungen;
    }

    public static function kennung(Schluessel $schluessel, string $wort): string
    {
        return $schluessel->kennung(self::ZWECK, mb_strtolower(Normalisierung::feld($wort)));
    }
}
