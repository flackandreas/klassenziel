<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use DateTimeImmutable;
use Klassenziel\Anzeige\Anzeigedaten;
use Klassenziel\Anzeige\Countdown;
use Klassenziel\Klassen\Klasse;
use Klassenziel\Wertung\Ferienkalender;
use Klassenziel\Wertung\Gewichtung;
use Klassenziel\Wertung\Wertung;
use PHPUnit\Framework\TestCase;

/**
 * Was die oeffentliche Seite bekommt - und was nicht.
 */
final class AnzeigedatenTest extends TestCase
{
    private static function klasse(?string $stichtag = '2027-06-30', int $ziel = 70): Klasse
    {
        return new Klasse(
            id: 1,
            anzeigename: 'Klasse 9d',
            exportKuerzel: 'R9d',
            schuljahr: '2026/27',
            belohnung: 'Kanutour mit Übernachtung',
            startdatum: new DateTimeImmutable('2026-09-14'),
            startwert: 50,
            zielwert: $ziel,
            stichtag: $stichtag !== null ? new DateTimeImmutable($stichtag) : null,
            archiviertAm: null,
            datenVersion: 3,
            hatLink: true,
            datenstand: new DateTimeImmutable('2026-10-19 13:31'),
        );
    }

    /** @param list<array{datum: string, stand: int}> $verlauf */
    private static function wertung(int $stand = 54, array $verlauf = []): Wertung
    {
        return new Wertung(
            stand: $stand,
            ereignisse: [
                ['art' => 'vorfall', 'id' => 11, 'datum' => '2026-09-16', 'richtung' => 'negativ', 'stufe' => 'klein', 'punkte' => -1, 'stand' => 49],
                ['art' => 'wochenbonus', 'id' => null, 'datum' => '2026-09-20', 'richtung' => 'positiv', 'stufe' => '', 'punkte' => 3, 'stand' => 52],
                ['art' => 'vorfall', 'id' => 12, 'datum' => '2026-10-01', 'richtung' => 'negativ', 'stufe' => 'klein', 'punkte' => -1, 'stand' => 51],
                ['art' => 'vorfall', 'id' => 13, 'datum' => '2026-10-06', 'richtung' => 'positiv', 'stufe' => 'mittel', 'punkte' => 3, 'stand' => 54],
            ],
            verlauf: $verlauf,
            wochenbilanz: 6,
            wochePositiv: 2,
            wocheNegativ: 0,
        );
    }

    /** @return array<int, array{status: string, kurztext: ?string, fach: string}> */
    private static function vorfaelle(): array
    {
        return [
            11 => ['status' => 'freigegeben', 'kurztext' => 'Unterrichtsstörung', 'fach' => 'Geschichte'],
            // Ein Kurztext ohne Freigabe erscheint nicht - nur die Kategorie.
            12 => ['status' => 'offen', 'kurztext' => 'Entwurf, nicht freigegeben', 'fach' => 'E-G'],
            13 => ['status' => 'ohne_text', 'kurztext' => null, 'fach' => 'Mathematik'],
        ];
    }

    /** @return array<string,mixed> */
    private static function daten(int $anzahl = 20, int $versatz = 0): array
    {
        return Anzeigedaten::baue(self::klasse(), self::wertung(), Gewichtung::vorgabe(), self::vorfaelle(), new DateTimeImmutable('2026-10-19'), $anzahl, $versatz);
    }

    public function testFeldlisteIstAbschliessend(): void
    {
        $daten = self::daten();

        self::assertSame(
            ['klasse', 'stand', 'ziel', 'fehlt', 'ziel_erreicht', 'stimmung', 'countdown', 'woche', 'wertung', 'quelle', 'eintraege', 'weitere', 'verlauf'],
            array_keys($daten)
        );
        self::assertSame(['start', 'ende', 'stichtag', 'punkte', 'ferien'], array_keys($daten['verlauf']));
        self::assertSame(['name', 'schuljahr', 'belohnung'], array_keys($daten['klasse']));
        self::assertSame(['stufe', 'spruch', 'status', 'naechste', 'fehlt_naechste', 'naechste_ist_ziel', 'etappen'], array_keys($daten['stimmung']));
        foreach ($daten['stimmung']['etappen'] as $etappe) {
            self::assertSame(['stufe', 'bei', 'erreicht'], array_keys($etappe));
        }

        foreach ($daten['eintraege'] as $eintrag) {
            self::assertSame(Anzeigedaten::EINTRAG_FELDER, array_keys($eintrag));
        }

        // Keine Kennung eines Vorfalls, kein Exportkuerzel, keine Kategorie im Wortlaut.
        $json = (string) json_encode($daten, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('"id"', $json);
        self::assertStringNotContainsString('R9d', $json);
        self::assertStringNotContainsString('Schüler*in', $json);
    }

    public function testVerlaufNurMitDatumUndStandAchseBisZumStichtag(): void
    {
        $verlauf = [
            ['datum' => '2026-09-14', 'stand' => 50],
            ['datum' => '2026-09-20', 'stand' => 52],
            ['datum' => '2026-09-27', 'stand' => 51],
        ];
        $ferien = new Ferienkalender([
            ['von' => '2026-08-01', 'bis' => '2026-09-11'],   // Sommerferien davor
            ['von' => '2026-10-26', 'bis' => '2026-11-06'],
            ['von' => '2027-07-29', 'bis' => '2027-09-10'],   // nach dem Stichtag
        ]);

        $v = Anzeigedaten::baue(self::klasse(), self::wertung(51, $verlauf), Gewichtung::vorgabe(), self::vorfaelle(), new DateTimeImmutable('2026-09-28'), 20, 0, $ferien)['verlauf'];

        self::assertSame('2026-09-14', $v['start']);
        self::assertSame('2027-06-30', $v['ende']);
        self::assertSame('2027-06-30', $v['stichtag']);
        self::assertSame($verlauf, $v['punkte']);
        foreach ($v['punkte'] as $punkt) {
            self::assertSame(['datum', 'stand'], array_keys($punkt));
        }
        self::assertSame([['von' => '2026-10-26', 'bis' => '2026-11-06']], $v['ferien']);
    }

    public function testOhneStichtagEndetDieAchseAmEndeDesSchuljahres(): void
    {
        $v = Anzeigedaten::baue(self::klasse(null), self::wertung(), Gewichtung::vorgabe(), self::vorfaelle(), new DateTimeImmutable('2026-10-19'))['verlauf'];

        self::assertNull($v['stichtag']);
        self::assertSame('2027-07-31', $v['ende']);
    }

    public function testNeuesteZuerstMitKurztextNurBeiFreigabe(): void
    {
        $eintraege = self::daten()['eintraege'];

        self::assertSame(['2026-10-06', '2026-10-01', '2026-09-20', '2026-09-16'], array_column($eintraege, 'datum'));
        self::assertSame('Mittlerer positiver Eintrag', $eintraege[0]['text']);
        self::assertSame('Kleiner negativer Eintrag', $eintraege[1]['text'], 'Der nicht freigegebene Entwurf bleibt intern.');
        self::assertSame(Anzeigedaten::WOCHENBONUS_TEXT, $eintraege[2]['text']);
        self::assertSame('Unterrichtsstörung', $eintraege[3]['text']);
        self::assertSame('Geschichte', $eintraege[3]['fach']);
    }

    public function testPunkteMitTypografischemMinus(): void
    {
        $eintraege = self::daten()['eintraege'];

        self::assertSame('+3', $eintraege[0]['punkte_text']);
        self::assertSame('mittel', $eintraege[0]['stufe']);
        self::assertSame("\u{2212}1", $eintraege[1]['punkte_text']);
        self::assertStringNotContainsString('-', $eintraege[1]['punkte_text']);
        self::assertSame('', $eintraege[2]['stufe'], 'Der Wochenbonus hat keine Stufe.');
        self::assertSame('0', Anzeigedaten::punkteText(0));
    }

    public function testZielUndWoche(): void
    {
        $daten = self::daten();

        self::assertSame(54, $daten['stand']);
        self::assertSame(16, $daten['fehlt']);
        self::assertFalse($daten['ziel_erreicht']);
        self::assertSame(['bilanz' => 6, 'bilanz_text' => '+6', 'positiv' => 2, 'negativ' => 0], $daten['woche']);
        self::assertSame('19.10.2026', $daten['quelle']);

        // Ziel 70: Etappen bei 18, 35, 53 und 70 - mit 54 Punkten auf Stufe 3.
        self::assertSame(3, $daten['stimmung']['stufe']);
        self::assertSame(70, $daten['stimmung']['naechste']);
        self::assertSame(16, $daten['stimmung']['fehlt_naechste']);
        self::assertTrue($daten['stimmung']['naechste_ist_ziel']);

        $erreicht = Anzeigedaten::baue(self::klasse(ziel: 54), self::wertung(), Gewichtung::vorgabe(), [], new DateTimeImmutable('2026-10-19'));
        self::assertTrue($erreicht['ziel_erreicht']);
        self::assertSame(0, $erreicht['fehlt']);
        self::assertSame(4, $erreicht['stimmung']['stufe']);
        self::assertNull($erreicht['stimmung']['naechste']);
    }

    public function testBlaettern(): void
    {
        $erste = self::daten(2, 0);
        self::assertCount(2, $erste['eintraege']);
        self::assertTrue($erste['weitere']);

        $zweite = self::daten(2, 2);
        self::assertSame(['2026-09-20', '2026-09-16'], array_column($zweite['eintraege'], 'datum'));
        self::assertFalse($zweite['weitere']);
    }

    public function testCountdown(): void
    {
        $heute = new DateTimeImmutable('2026-10-19 23:30');

        self::assertSame(['zustand' => 'laeuft', 'tage' => 254, 'stichtag' => '30.06.2027'], Countdown::fuer(new DateTimeImmutable('2027-06-30'), $heute));
        self::assertSame(['zustand' => 'laeuft', 'tage' => 1, 'stichtag' => '20.10.2026'], Countdown::fuer(new DateTimeImmutable('2026-10-20'), $heute));
        self::assertSame('heute', Countdown::fuer(new DateTimeImmutable('2026-10-19'), $heute)['zustand']);
        self::assertSame(['zustand' => 'erreicht', 'tage' => 0, 'stichtag' => '18.10.2026'], Countdown::fuer(new DateTimeImmutable('2026-10-18'), $heute));
        self::assertSame(['zustand' => 'offen', 'tage' => null, 'stichtag' => null], Countdown::fuer(null, $heute));
    }
}
