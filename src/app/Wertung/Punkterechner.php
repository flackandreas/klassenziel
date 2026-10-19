<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use DateTimeImmutable;

/**
 * Rechnet den Punktestand einer Klasse aus - rein rechnerisch, ohne
 * Datenbank, damit sich jede Regel einzeln pruefen laesst.
 *
 * Reihenfolge (Planung, Abschnitt 9):
 *   1. Start mit dem Startwert.
 *   2. Alle zaehlenden Vorfaelle chronologisch; der Wochenbonus am Sonntag
 *      der Woche, nach den Vorfaellen dieses Tages.
 *   3. Nach jedem Schritt auf 0 bis 100 begrenzen. Eine Klasse, die bei 0
 *      angekommen ist, steigt mit den naechsten Pluspunkten sofort sichtbar
 *      wieder - eine Begrenzung erst am Ende liesse sie wochenlang bei 0
 *      stehen, waehrend sie in Wahrheit Schulden abtraegt.
 *
 * Gezaehlt wird vom Startdatum bis zum Stichtag (oder bis heute).
 *
 * Wochenbonus fuer eine Woche (Montag bis Sonntag), wenn:
 *   - ihr Sonntag im Wertungszeitraum liegt und schon vorbei ist,
 *   - sie vollstaendig eingelesen ist: der Datenstand liegt nach ihrem
 *     Sonntag. Sonst bekaeme sie den Bonus, bevor der Freitagseintrag
 *     eingelesen ist, und verloere ihn beim naechsten Import wieder,
 *   - sie keine Ferienwoche ist,
 *   - in ihr kein zaehlender grosser negativer Vorfall liegt.
 */
final class Punkterechner
{
    public const MINIMUM = 0;
    public const MAXIMUM = 100;

    /**
     * @param list<array{id: int, datum: string, richtung: string, stufe: string}> $vorfaelle
     *        nur zaehlende Vorfaelle mit wirksamer Richtung (positiv/negativ) und Stufe
     */
    public static function rechne(Wertungsrahmen $rahmen, Gewichtung $gewichtung, array $vorfaelle, Ferienkalender $ferien): Wertung
    {
        $beginn = $rahmen->startdatum->format('Y-m-d');
        $ende = $rahmen->ende()->format('Y-m-d');

        $imZeitraum = array_values(array_filter(
            $vorfaelle,
            static fn (array $v): bool => $v['datum'] >= $beginn && $v['datum'] <= $ende
                && in_array($v['richtung'], ['positiv', 'negativ'], true)
        ));

        /** @var list<array{art: string, id: ?int, datum: string, richtung: string, stufe: string, punkte: int, reihenfolge: int}> $ereignisse */
        $ereignisse = [];
        foreach ($imZeitraum as $vorfall) {
            $ereignisse[] = [
                'art' => 'vorfall',
                'id' => $vorfall['id'],
                'datum' => $vorfall['datum'],
                'richtung' => $vorfall['richtung'],
                'stufe' => $vorfall['stufe'],
                'punkte' => $gewichtung->fuer($vorfall['richtung'], $vorfall['stufe']),
                'reihenfolge' => 0,
            ];
        }

        foreach (self::bonuswochen($rahmen, $imZeitraum, $ferien) as $sonntag) {
            if ($gewichtung->wochenbonus > 0) {
                $ereignisse[] = [
                    'art' => 'wochenbonus',
                    'id' => null,
                    'datum' => $sonntag,
                    'richtung' => 'positiv',
                    'stufe' => '',
                    'punkte' => $gewichtung->wochenbonus,
                    'reihenfolge' => 1,
                ];
            }
        }

        usort($ereignisse, static fn (array $a, array $b): int => [$a['datum'], $a['reihenfolge'], $a['id'] ?? 0]
            <=> [$b['datum'], $b['reihenfolge'], $b['id'] ?? 0]);

        $stand = self::begrenze($rahmen->startwert);
        $verlauf = [];
        $berechnet = [];

        foreach ($ereignisse as $ereignis) {
            $stand = self::begrenze($stand + $ereignis['punkte']);
            unset($ereignis['reihenfolge']);
            $ereignis['stand'] = $stand;
            $berechnet[] = $ereignis;
        }

        // Verlauf: Stand am Ende jeder Woche, vom Start bis zur laufenden Woche.
        $verlauf[] = ['datum' => $beginn, 'stand' => self::begrenze($rahmen->startwert)];
        $sonntag = Wertungsrahmen::sonntag($rahmen->startdatum);
        $letzter = Wertungsrahmen::sonntag($rahmen->ende());
        $index = 0;
        $standBis = self::begrenze($rahmen->startwert);

        while ($sonntag <= $letzter) {
            $stichtag = $sonntag < $rahmen->ende() ? $sonntag : $rahmen->ende();
            $grenze = $stichtag->format('Y-m-d');

            while ($index < count($berechnet) && $berechnet[$index]['datum'] <= $grenze) {
                $standBis = $berechnet[$index]['stand'];
                $index++;
            }

            if ($grenze !== $beginn) {
                $verlauf[] = ['datum' => $grenze, 'stand' => $standBis];
            }

            $sonntag = $sonntag->modify('+7 days');
        }

        // Diese Woche: Montag bis Sonntag der laufenden Woche.
        $wocheVon = Wertungsrahmen::montag($rahmen->heute)->format('Y-m-d');
        $wocheBis = Wertungsrahmen::sonntag($rahmen->heute)->format('Y-m-d');
        $dieseWoche = array_values(array_filter(
            $berechnet,
            static fn (array $e): bool => $e['datum'] >= $wocheVon && $e['datum'] <= $wocheBis
        ));

        return new Wertung(
            stand: $stand,
            ereignisse: $berechnet,
            verlauf: $verlauf,
            wochenbilanz: array_sum(array_column($dieseWoche, 'punkte')),
            wochePositiv: count(array_filter($dieseWoche, static fn (array $e): bool => $e['art'] === 'vorfall' && $e['punkte'] > 0)),
            wocheNegativ: count(array_filter($dieseWoche, static fn (array $e): bool => $e['art'] === 'vorfall' && $e['punkte'] < 0)),
        );
    }

    /**
     * Die Sonntage der Wochen, die einen Bonus bekommen.
     *
     * @param list<array{id: int, datum: string, richtung: string, stufe: string}> $vorfaelle
     * @return list<string>
     */
    public static function bonuswochen(Wertungsrahmen $rahmen, array $vorfaelle, Ferienkalender $ferien): array
    {
        if ($rahmen->datenstand === null) {
            return [];
        }

        $grosseNegative = [];
        foreach ($vorfaelle as $vorfall) {
            if ($vorfall['richtung'] === 'negativ' && $vorfall['stufe'] === 'gross') {
                $grosseNegative[Wertungsrahmen::montag(new DateTimeImmutable($vorfall['datum']))->format('Y-m-d')] = true;
            }
        }

        $sonntage = [];
        $sonntag = Wertungsrahmen::sonntag($rahmen->startdatum);
        $vollstaendigBis = $rahmen->datenstand->setTime(0, 0);

        while (true) {
            if ($sonntag >= $rahmen->heute || $sonntag >= $vollstaendigBis) {
                break;
            }
            if ($rahmen->stichtag !== null && $sonntag > $rahmen->stichtag) {
                break;
            }

            $montag = $sonntag->modify('-6 days');
            if (!$ferien->istFerienwoche($montag) && !isset($grosseNegative[$montag->format('Y-m-d')])) {
                $sonntage[] = $sonntag->format('Y-m-d');
            }

            $sonntag = $sonntag->modify('+7 days');
        }

        return $sonntage;
    }

    public static function begrenze(int $stand): int
    {
        return max(self::MINIMUM, min(self::MAXIMUM, $stand));
    }
}
