<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Anzeige\Stimmung;
use Klassenziel\Anzeige\Verlaufsbild;
use PHPUnit\Framework\TestCase;

/**
 * Die Geometrie der Verlaufslinie auf der oeffentlichen Seite.
 */
final class VerlaufsbildTest extends TestCase
{
    /**
     * @param list<array{datum: string, stand: int}> $punkte
     * @param list<array{von: string, bis: string}> $ferien
     * @return array{start: string, ende: string, stichtag: ?string, punkte: list<array{datum: string, stand: int}>, ferien: list<array{von: string, bis: string}>}
     */
    private static function verlauf(array $punkte = [], array $ferien = [], ?string $stichtag = '2027-06-30'): array
    {
        return [
            'start' => '2026-09-14',
            'ende' => $stichtag ?? '2027-07-31',
            'stichtag' => $stichtag,
            'punkte' => $punkte !== [] ? $punkte : [
                ['datum' => '2026-09-14', 'stand' => 50],
                ['datum' => '2026-09-20', 'stand' => 52],
                ['datum' => '2026-09-27', 'stand' => 55],
                ['datum' => '2026-10-04', 'stand' => 53],
                ['datum' => '2026-10-11', 'stand' => 56],
                ['datum' => '2026-10-18', 'stand' => 57],
            ],
            'ferien' => $ferien,
        ];
    }

    /** @param array<string,mixed> $verlauf @return array<string,mixed> */
    private static function bild(array $verlauf, int $ziel = 70, string $format = 'beamer'): array
    {
        $etappen = array_map(
            static fn (array $e): array => [...$e, 'erreicht' => 57 >= $e['bei']],
            Stimmung::etappen($ziel)
        );

        /** @var array{start: string, ende: string, stichtag: ?string, punkte: list<array{datum: string, stand: int}>, ferien: list<array{von: string, bis: string}>} $verlauf */
        return Verlaufsbild::fuer($verlauf, $ziel, $etappen, $format);
    }

    public function testLinieBeginntLinksBeimStartwertUndHatEinenPunktJeWoche(): void
    {
        $b = self::bild(self::verlauf());
        $f = Verlaufsbild::FORMATE['beamer'];

        // x am Start = linker Rand; 50 Punkte = Mitte zwischen oben und unten.
        $mitte = $f['oben'] + ($f['hoehe'] - $f['unten'] - $f['oben']) / 2;
        self::assertStringStartsWith('M' . $f['links'] . ' ' . $mitte . ' L', (string) $b['pfad']);
        self::assertSame(6, substr_count((string) $b['pfad'], ' L') + 1);
    }

    public function testDieAchseReichtBisZumStichtag(): void
    {
        $b = self::bild(self::verlauf());
        $f = Verlaufsbild::FORMATE['beamer'];

        self::assertSame((string) ($f['breite'] - $f['rechts']), $b['stichtag']['x']);
        self::assertSame('Stichtag 30.6.', $b['stichtag']['text']);
        // Der heutige Stand liegt weit links: Das Jahr hat gerade begonnen.
        self::assertLessThan(($f['breite'] - $f['rechts']) / 4, (float) $b['ende']['x']);
        self::assertSame(57, $b['ende']['wert']);
    }

    public function testFerienWerdenAufDieAchseBeschnitten(): void
    {
        $b = self::bild(self::verlauf(ferien: [
            ['von' => '2026-08-01', 'bis' => '2026-09-13'],   // ganz vor dem Start
            ['von' => '2026-09-10', 'bis' => '2026-09-16'],   // ragt in den Start
            ['von' => '2026-10-26', 'bis' => '2026-11-06'],   // Herbstferien
            ['von' => '2027-07-29', 'bis' => '2027-09-10'],   // nach dem Stichtag
        ]));
        $f = Verlaufsbild::FORMATE['beamer'];

        self::assertCount(2, $b['ferien']);
        self::assertSame((string) $f['links'], $b['ferien'][0]['x']);
        self::assertGreaterThan(0, (float) $b['ferien'][1]['breite']);
    }

    public function testFerienWortStehtAuchOhnePassendenStreifenImBreitesten(): void
    {
        $ferien = [
            ['von' => '2026-10-26', 'bis' => '2026-10-30'],   // eine Woche
            ['von' => '2026-12-23', 'bis' => '2027-01-09'],   // gut zwei Wochen
        ];
        $beamer = self::bild(self::verlauf(ferien: $ferien));
        $handy = self::bild(self::verlauf(ferien: $ferien), 70, 'handy');

        // Auf dem Handy ist kein Streifen breit genug - das Wort steht trotzdem.
        self::assertNotNull($handy['ferientext']);
        self::assertSame((float) $handy['ferien'][1]['x'] + 4, (float) $handy['ferientext']['x']);
        self::assertSame((float) $beamer['ferien'][1]['x'] + 4, (float) $beamer['ferientext']['x']);
        self::assertNull(self::bild(self::verlauf())['ferientext']);
    }

    public function testEtappenSitzenAufIhrerHoeheDieLetzteAufDemZiel(): void
    {
        $b = self::bild(self::verlauf());

        self::assertSame([18, 35, 53, 70], array_column($b['etappen'], 'bei'));
        self::assertSame([true, true, true, false], array_column($b['etappen'], 'erreicht'));
        $letzte = $b['etappen'][3];
        self::assertEqualsWithDelta((float) $b['ziel']['y'], (float) $letzte['y'] + $letzte['groesse'] / 2, 0.1);
        // Von unten nach oben: jede Etappe hoeher als die vorige.
        for ($i = 1; $i < count($b['etappen']); $i++) {
            self::assertLessThan((float) $b['etappen'][$i - 1]['y'], (float) $b['etappen'][$i]['y']);
        }
    }

    public function testBeiKleinemZielWerdenDieSmileysKleiner(): void
    {
        $normal = self::bild(self::verlauf(), 70)['etappen'][0]['groesse'];
        $klein = self::bild(self::verlauf(), 20)['etappen'][0]['groesse'];

        self::assertSame(Verlaufsbild::FORMATE['beamer']['etappe'], $normal);
        self::assertLessThan($normal, $klein);
        self::assertGreaterThanOrEqual(12, $klein);
    }

    public function testZuBeginnNurDerStartpunkt(): void
    {
        $b = self::bild(self::verlauf([['datum' => '2026-09-14', 'stand' => 50]]));

        self::assertNull($b['pfad']);
        self::assertSame(50, $b['ende']['wert']);
    }

    public function testOhneStichtagKeineMarkeUndDieAchseEndetImJuli(): void
    {
        $b = self::bild(self::verlauf(stichtag: null));

        self::assertNull($b['stichtag']);
        self::assertSame('Jul', $b['monate'][count($b['monate']) - 1]['text']);
    }

    public function testMonateLaufenNichtInDieStichtagBeschriftung(): void
    {
        foreach (['beamer', 'handy'] as $format) {
            $b = self::bild(self::verlauf(), 70, $format);
            $f = Verlaufsbild::FORMATE[$format];
            $links = (float) $b['stichtag']['x'] - mb_strlen($b['stichtag']['text']) * $f['schrift'] * 0.6;

            self::assertSame('Sep', $b['monate'][0]['text']);
            self::assertSame('start', $b['monate'][0]['anker']);
            foreach ($b['monate'] as $monat) {
                self::assertLessThan($links, (float) $monat['x'], "$format: {$monat['text']} ragt in den Stichtag");
            }
        }
    }

    public function testStandOhnePlatzObenStehtUnterDemPunkt(): void
    {
        $b = self::bild(self::verlauf([
            ['datum' => '2026-09-14', 'stand' => 98],
            ['datum' => '2026-09-20', 'stand' => 100],
        ]));

        self::assertGreaterThan((float) $b['ende']['y'], (float) $b['ende']['text_y']);
    }

    public function testStandNahUntermRandStehtNochDarueber(): void
    {
        // Darunter laege die Zahl auf der Ziellinie (70).
        foreach (['beamer', 'handy'] as $format) {
            $b = self::bild(self::verlauf([
                ['datum' => '2026-09-14', 'stand' => 80],
                ['datum' => '2026-09-20', 'stand' => 88],
            ]), 70, $format);

            self::assertLessThan((float) $b['ende']['y'], (float) $b['ende']['text_y'], $format);
        }
    }

    public function testBeschreibungFuerScreenreader(): void
    {
        self::assertSame(
            'Punkteverlauf seit 14.09.2026: Start bei 50, jetzt 57 von 100 Punkten, Ziel 70, Stichtag 30.06.2027.',
            self::bild(self::verlauf())['beschreibung']
        );
    }

    public function testTabelleNenntJedeWoche(): void
    {
        $tabelle = self::bild(self::verlauf(), 70, 'handy')['tabelle'];

        self::assertCount(6, $tabelle);
        self::assertSame(['datum' => '18.10.2026', 'stand' => 57], $tabelle[5]);
    }
}
