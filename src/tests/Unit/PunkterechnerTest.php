<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use DateTimeImmutable;
use Klassenziel\Wertung\Ferienkalender;
use Klassenziel\Wertung\Gewichtung;
use Klassenziel\Wertung\Punkterechner;
use Klassenziel\Wertung\Wertungsrahmen;
use PHPUnit\Framework\TestCase;

final class PunkterechnerTest extends TestCase
{
    private static int $naechsteId = 1;

    /** @return array{id: int, datum: string, richtung: string, stufe: string} */
    private static function vorfall(string $datum, string $richtung, string $stufe): array
    {
        return ['id' => self::$naechsteId++, 'datum' => $datum, 'richtung' => $richtung, 'stufe' => $stufe];
    }

    /** Montag 14.09.2026 bis heute Montag 19.10.2026, Export von heute frueh. */
    private static function rahmen(int $startwert = 50, ?string $stichtag = null, ?string $datenstand = '2026-10-19 13:31', string $heute = '2026-10-19'): Wertungsrahmen
    {
        return new Wertungsrahmen(
            $startwert,
            new DateTimeImmutable('2026-09-14'),
            $stichtag !== null ? new DateTimeImmutable($stichtag) : null,
            $datenstand !== null ? new DateTimeImmutable($datenstand) : null,
            new DateTimeImmutable($heute),
        );
    }

    private static function ohneBonus(): Gewichtung
    {
        return new Gewichtung(1, 3, 5, 0);
    }

    public function testLaufendeBegrenzungNachUnten(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(5), self::ohneBonus(), [
            self::vorfall('2026-09-15', 'negativ', 'mittel'),
            self::vorfall('2026-09-16', 'negativ', 'mittel'),
            self::vorfall('2026-09-17', 'negativ', 'mittel'),
            self::vorfall('2026-09-18', 'positiv', 'mittel'),
        ], new Ferienkalender());

        // 5 -> 2 -> 0 (nicht -1) -> 0 (nicht -4) -> 3. Ohne laufende
        // Begrenzung stuende die Klasse bei 0 und sammelte Schulden.
        self::assertSame([2, 0, 0, 3], array_column($wertung->ereignisse, 'stand'));
        self::assertSame(3, $wertung->stand);
    }

    public function testLaufendeBegrenzungNachOben(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(98), self::ohneBonus(), [
            self::vorfall('2026-09-15', 'positiv', 'gross'),
            self::vorfall('2026-09-16', 'negativ', 'klein'),
        ], new Ferienkalender());

        self::assertSame([100, 99], array_column($wertung->ereignisse, 'stand'));
    }

    public function testEinVorfallZaehltNachSeinerStufe(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(), self::ohneBonus(), [
            self::vorfall('2026-09-15', 'negativ', 'klein'),
            self::vorfall('2026-09-16', 'negativ', 'gross'),
            self::vorfall('2026-09-17', 'positiv', 'mittel'),
        ], new Ferienkalender());

        self::assertSame([-1, -5, 3], array_column($wertung->ereignisse, 'punkte'));
        self::assertSame(47, $wertung->stand);
    }

    public function testWochenbonusFuerJedeVollstaendigeWoche(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(), new Gewichtung(1, 3, 5, 3), [], new Ferienkalender());

        $boni = array_values(array_filter($wertung->ereignisse, static fn (array $e): bool => $e['art'] === 'wochenbonus'));
        self::assertSame(['2026-09-20', '2026-09-27', '2026-10-04', '2026-10-11', '2026-10-18'], array_column($boni, 'datum'));
        self::assertSame(65, $wertung->stand);
    }

    public function testKeinBonusInEinerWocheMitGrossemNegativemVorfall(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(), new Gewichtung(1, 3, 5, 3), [
            self::vorfall('2026-10-05', 'negativ', 'gross'),
            // Ein kleiner negativer kostet den Bonus nicht.
            self::vorfall('2026-09-22', 'negativ', 'klein'),
        ], new Ferienkalender());

        $boni = array_column(array_filter($wertung->ereignisse, static fn (array $e): bool => $e['art'] === 'wochenbonus'), 'datum');
        self::assertNotContains('2026-10-11', $boni);
        self::assertContains('2026-09-27', $boni);
        self::assertSame(50 - 5 - 1 + 4 * 3, $wertung->stand);
    }

    public function testKeinBonusInDerFerienwoche(): void
    {
        $ferien = new Ferienkalender([
            ['von' => '2026-10-26', 'bis' => '2026-10-30'], // Herbstferien, ganze Woche
            ['von' => '2026-11-02', 'bis' => '2026-11-03'], // zwei Ferientage: trotzdem Schulwoche
        ]);

        $wertung = Punkterechner::rechne(
            self::rahmen(datenstand: '2026-11-09 07:00', heute: '2026-11-09'),
            new Gewichtung(1, 3, 5, 3),
            [],
            $ferien
        );

        $boni = array_column($wertung->ereignisse, 'datum');
        self::assertNotContains('2026-11-01', $boni, 'Woche 26.10.-01.11. sind Ferien.');
        self::assertContains('2026-11-08', $boni, 'Woche mit zwei Ferientagen ist eine Schulwoche.');
        self::assertCount(7, $boni);
    }

    public function testBonusErstWennDieWocheEingelesenIst(): void
    {
        // Heute ist der 28.10., eingelesen ist aber nur bis zum 19.10.
        $wertung = Punkterechner::rechne(
            self::rahmen(datenstand: '2026-10-19 13:31', heute: '2026-10-28'),
            new Gewichtung(1, 3, 5, 3),
            [],
            new Ferienkalender()
        );

        self::assertNotContains('2026-10-25', array_column($wertung->ereignisse, 'datum'));
        self::assertSame('2026-10-18', $wertung->ereignisse[count($wertung->ereignisse) - 1]['datum']);
    }

    public function testOhneExportKeinBonus(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(datenstand: null), new Gewichtung(1, 3, 5, 3), [], new Ferienkalender());

        self::assertSame([], $wertung->ereignisse);
        self::assertSame(50, $wertung->stand);
    }

    public function testBonusErstNachAblaufDerWoche(): void
    {
        // Sonntag, 18.10.: die Woche laeuft noch.
        $wertung = Punkterechner::rechne(
            self::rahmen(datenstand: '2026-10-19 13:31', heute: '2026-10-18'),
            new Gewichtung(1, 3, 5, 3),
            [],
            new Ferienkalender()
        );

        self::assertNotContains('2026-10-18', array_column($wertung->ereignisse, 'datum'));
    }

    public function testWochenbonusNullSchaltetIhnAb(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(), new Gewichtung(1, 3, 5, 0), [], new Ferienkalender());

        self::assertSame([], $wertung->ereignisse);
    }

    public function testNurDerWertungszeitraumZaehlt(): void
    {
        $wertung = Punkterechner::rechne(
            self::rahmen(stichtag: '2026-10-07', datenstand: '2026-10-19 13:31'),
            new Gewichtung(1, 3, 5, 3),
            [
                self::vorfall('2026-09-11', 'negativ', 'gross'), // vor dem Start
                self::vorfall('2026-10-06', 'negativ', 'klein'),
                self::vorfall('2026-10-08', 'negativ', 'gross'), // nach dem Stichtag
            ],
            new Ferienkalender()
        );

        $vorfaelle = array_values(array_filter($wertung->ereignisse, static fn (array $e): bool => $e['art'] === 'vorfall'));
        self::assertSame(['2026-10-06'], array_column($vorfaelle, 'datum'));

        $boni = array_column(array_filter($wertung->ereignisse, static fn (array $e): bool => $e['art'] === 'wochenbonus'), 'datum');
        self::assertSame(['2026-09-20', '2026-09-27', '2026-10-04'], array_values($boni), 'Die Woche mit dem Stichtag endet erst danach.');
    }

    public function testBonusKommtNachDenVorfaellenDesSonntags(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(1), new Gewichtung(1, 3, 5, 3), [
            self::vorfall('2026-09-20', 'negativ', 'mittel'),
        ], new Ferienkalender());

        // 1 -> 0 (Vorfall) -> 3 (Bonus). Umgekehrt waere es 4 -> 1.
        self::assertSame(['vorfall', 'wochenbonus'], [$wertung->ereignisse[0]['art'], $wertung->ereignisse[1]['art']]);
        self::assertSame([0, 3], [$wertung->ereignisse[0]['stand'], $wertung->ereignisse[1]['stand']]);
    }

    public function testGeaenderteGewichteWirkenRueckwirkend(): void
    {
        $vorfaelle = [
            self::vorfall('2026-09-15', 'negativ', 'klein'),
            self::vorfall('2026-09-16', 'negativ', 'klein'),
            self::vorfall('2026-10-05', 'negativ', 'gross'),
        ];

        $vorher = Punkterechner::rechne(self::rahmen(), new Gewichtung(1, 3, 5, 0), $vorfaelle, new Ferienkalender());
        $nachher = Punkterechner::rechne(self::rahmen(), new Gewichtung(2, 3, 8, 0), $vorfaelle, new Ferienkalender());

        self::assertSame(43, $vorher->stand);
        self::assertSame(38, $nachher->stand);
    }

    public function testVerlaufUndWochenbilanz(): void
    {
        $wertung = Punkterechner::rechne(
            self::rahmen(heute: '2026-10-21', datenstand: '2026-10-21 08:00'),
            new Gewichtung(1, 3, 5, 3),
            [
                self::vorfall('2026-09-16', 'negativ', 'klein'),
                self::vorfall('2026-10-19', 'positiv', 'mittel'),
                self::vorfall('2026-10-20', 'negativ', 'klein'),
            ],
            new Ferienkalender()
        );

        self::assertSame(
            ['2026-09-14', '2026-09-20', '2026-09-27', '2026-10-04', '2026-10-11', '2026-10-18', '2026-10-21'],
            array_column($wertung->verlauf, 'datum')
        );
        self::assertSame([50, 52, 55, 58, 61, 64, 66], array_column($wertung->verlauf, 'stand'));
        self::assertSame(2, $wertung->wochenbilanz);
        self::assertSame(1, $wertung->wochePositiv);
        self::assertSame(1, $wertung->wocheNegativ);
        self::assertSame(66, $wertung->stand);
    }

    public function testNeutraleUndUngepruefteZaehlenNicht(): void
    {
        $wertung = Punkterechner::rechne(self::rahmen(), self::ohneBonus(), [
            self::vorfall('2026-09-15', 'neutral', ''),
            self::vorfall('2026-09-16', 'pruefen', ''),
        ], new Ferienkalender());

        self::assertSame([], $wertung->ereignisse);
        self::assertSame(50, $wertung->stand);
    }
}
