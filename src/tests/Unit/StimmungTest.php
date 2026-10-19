<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Anzeige\Stimmung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StimmungTest extends TestCase
{
    public function testEtappenSindViertelDesZiels(): void
    {
        self::assertSame(
            [['stufe' => 1, 'bei' => 18], ['stufe' => 2, 'bei' => 35], ['stufe' => 3, 'bei' => 53], ['stufe' => 4, 'bei' => 70]],
            Stimmung::etappen(70)
        );
        self::assertSame([25, 50, 75, 100], array_column(Stimmung::etappen(100), 'bei'));
    }

    public function testKleineZieleHabenWenigerEtappen(): void
    {
        // Zwei Viertel auf derselben Zahl: es zaehlt das hoehere.
        self::assertSame([['stufe' => 2, 'bei' => 1], ['stufe' => 4, 'bei' => 2]], Stimmung::etappen(2));
        self::assertSame([['stufe' => 4, 'bei' => 1]], Stimmung::etappen(1));

        // Die letzte Etappe ist immer das Ziel, und keine Zahl doppelt.
        for ($ziel = 1; $ziel <= 100; $ziel++) {
            $bei = array_column(Stimmung::etappen($ziel), 'bei');
            self::assertSame($ziel, end($bei), "Ziel $ziel");
            self::assertSame($bei, array_values(array_unique($bei)), "Ziel $ziel");
            self::assertSame(4, Stimmung::fuer($ziel, $ziel)['stufe'], "Ziel $ziel");
        }
    }

    /**
     * @return iterable<string, array{int, int, ?int, int, bool}>
     */
    public static function staende(): iterable
    {
        yield 'leer' => [0, 0, 18, 18, false];
        yield 'knapp unter der ersten' => [17, 0, 18, 1, false];
        yield 'erste erreicht' => [18, 1, 35, 17, false];
        yield 'Halbzeit' => [40, 2, 53, 13, false];
        yield 'Zielgerade' => [53, 3, 70, 17, true];
        yield 'Ziel' => [70, 4, null, 0, false];
        yield 'ueber dem Ziel' => [95, 4, null, 0, false];
    }

    #[DataProvider('staende')]
    public function testStufeUndNaechsteEtappe(int $stand, int $stufe, ?int $naechste, int $fehlt, bool $naechsteIstZiel): void
    {
        $stimmung = Stimmung::fuer($stand, 70);

        self::assertSame($stufe, $stimmung['stufe']);
        self::assertSame(Stimmung::SPRUECHE[$stufe], $stimmung['spruch']);
        self::assertSame($naechste, $stimmung['naechste']);
        self::assertSame($fehlt, $stimmung['fehlt_naechste']);
        self::assertSame($naechsteIstZiel, $stimmung['naechste_ist_ziel']);
        self::assertSame(
            array_map(static fn (array $e): bool => $stand >= $e['bei'], Stimmung::etappen(70)),
            array_column($stimmung['etappen'], 'erreicht')
        );
    }

    public function testStatusSagtDenNaechstenSchritt(): void
    {
        self::assertSame('Noch 18 Punkte bis zum nächsten Smiley · 70 bis zum Ziel', Stimmung::fuer(0, 70)['status']);
        self::assertSame('Noch 1 Punkt bis zum nächsten Smiley · 53 bis zum Ziel', Stimmung::fuer(17, 70)['status']);
        self::assertSame('Noch 17 Punkte bis zum Ziel', Stimmung::fuer(53, 70)['status']);
        self::assertSame('Noch 1 Punkt bis zum Ziel', Stimmung::fuer(69, 70)['status']);
        self::assertSame('Genau am Ziel', Stimmung::fuer(70, 70)['status']);
        self::assertSame('1 Punkt über dem Ziel', Stimmung::fuer(71, 70)['status']);
        self::assertSame('25 Punkte über dem Ziel', Stimmung::fuer(95, 70)['status']);
    }

    public function testSpruchZumZielPasstZurBisherigenMeldung(): void
    {
        self::assertSame('Ziel erreicht – jetzt halten!', Stimmung::SPRUECHE[4]);
    }
}
