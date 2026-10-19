<?php

declare(strict_types=1);

namespace Klassenziel\Import;

/**
 * Fasst Exportzeilen zu Vorfaellen zusammen.
 *
 * WebUntis exportiert einen Eintrag mit mehreren Beteiligten als eine Zeile je
 * Person; die Texte unterscheiden sich dann oft nur im Namen. Zum selben
 * Vorfall gehoeren Zeilen, wenn alle drei Bedingungen gelten:
 *
 *  1. gleiche Klasse, gleiches Datum, gleiches Fach, gleiche Lehrkraft
 *  2. gleiche Kategorie
 *  3. aehnlicher Text, nachdem die Namen der jeweiligen Zeile durch einen
 *     Platzhalter ersetzt sind (Aehnlichkeit mindestens 0,8)
 *
 * Bedingung 1 und 2 stecken im Schluessel, 3 im Vergleich der Vorlagen. Zwei
 * verschiedene Vorfaelle derselben Stunde bleiben so getrennt.
 *
 * Rein rechnerisch, ohne Datenbank: der Importdienst gibt die Zeilen und die
 * bereits bekannten Vorfaelle hinein.
 */
final class Gruppierer
{
    public const SCHWELLE = 0.8;

    /**
     * @param list<array{schluessel: string, vorlage: string}> $zeilen
     * @param list<array{id: int, schluessel: string, vorlage: ?string}> $bekannte
     *        Vorfaelle aus frueheren Importen. Ohne Vorlage (sie ist nach der
     *        Entscheidung geloescht) laesst sich der Text nicht mehr vergleichen.
     *
     * @return list<array{art: 'bekannt', vorfall: int}|array{art: 'neu', gruppe: int, moeglich_zu: ?int}>
     *         je Zeile, in derselben Reihenfolge
     */
    public static function ordneZu(array $zeilen, array $bekannte, float $schwelle = self::SCHWELLE): array
    {
        /** @var list<array{schluessel: string, vorlagen: list<string>, moeglich_zu: ?int}> $gruppen */
        $gruppen = [];
        $ergebnis = [];

        foreach ($zeilen as $zeile) {
            // 1. Ein bekannter Vorfall mit aehnlichem Text.
            $besterBekannter = null;
            $besteAehnlichkeit = $schwelle;
            $ohneVorlage = null;

            foreach ($bekannte as $bekannt) {
                if ($bekannt['schluessel'] !== $zeile['schluessel']) {
                    continue;
                }

                if ($bekannt['vorlage'] === null) {
                    $ohneVorlage ??= $bekannt['id'];
                    continue;
                }

                $aehnlichkeit = Aehnlichkeit::verhaeltnis($zeile['vorlage'], $bekannt['vorlage']);
                if ($aehnlichkeit >= $besteAehnlichkeit) {
                    $besterBekannter = $bekannt['id'];
                    $besteAehnlichkeit = $aehnlichkeit;
                }
            }

            if ($besterBekannter !== null) {
                $ergebnis[] = ['art' => 'bekannt', 'vorfall' => $besterBekannter];
                continue;
            }

            // 2. Ein Vorfall, der in diesem Import schon entstanden ist.
            $besteGruppe = null;
            $besteAehnlichkeit = $schwelle;

            foreach ($gruppen as $nummer => $gruppe) {
                if ($gruppe['schluessel'] !== $zeile['schluessel']) {
                    continue;
                }

                foreach ($gruppe['vorlagen'] as $vorlage) {
                    $aehnlichkeit = Aehnlichkeit::verhaeltnis($zeile['vorlage'], $vorlage);
                    if ($aehnlichkeit >= $besteAehnlichkeit) {
                        $besteGruppe = $nummer;
                        $besteAehnlichkeit = $aehnlichkeit;
                    }
                }
            }

            if ($besteGruppe !== null) {
                $gruppen[$besteGruppe]['vorlagen'][] = $zeile['vorlage'];
                $ergebnis[] = ['art' => 'neu', 'gruppe' => $besteGruppe, 'moeglich_zu' => $gruppen[$besteGruppe]['moeglich_zu']];
                continue;
            }

            // 3. Ein neuer Vorfall. Gibt es in derselben Stunde einen
            //    bekannten, dessen Text schon geloescht ist, koennte die Zeile
            //    ein Nachzuegler sein - das entscheidet die Lehrkraft.
            $gruppen[] = ['schluessel' => $zeile['schluessel'], 'vorlagen' => [$zeile['vorlage']], 'moeglich_zu' => $ohneVorlage];
            $ergebnis[] = ['art' => 'neu', 'gruppe' => count($gruppen) - 1, 'moeglich_zu' => $ohneVorlage];
        }

        return $ergebnis;
    }

    /**
     * Der Schluessel fuer die Bedingungen 1 und 2.
     */
    public static function schluessel(int $klasseId, string $datum, string $fach, string $lehrkraftKennung, string $kategorie): string
    {
        return implode("\x1F", [(string) $klasseId, $datum, $fach, $lehrkraftKennung, $kategorie]);
    }
}
