<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use DateTimeImmutable;
use Klassenziel\Import\Aehnlichkeit;
use Klassenziel\Import\Exportzeile;
use Klassenziel\Import\Fingerabdruck;
use Klassenziel\Import\Gruppierer;
use Klassenziel\Import\Namensbestandteile;
use Klassenziel\Import\Normalisierung;
use Klassenziel\Sicherheit\Schluessel;
use PHPUnit\Framework\TestCase;

final class GruppierungTest extends TestCase
{
    private Schluessel $schluessel;

    protected function setUp(): void
    {
        $this->schluessel = Schluessel::ausWert(base64_encode(str_repeat('g', 32)));
    }

    private static function zeile(string $name, string $text, string $fach = 'E-G', string $benutzer = 'KLM', string $kategorie = 'klein/Schüler*in', string $datum = '2026-10-01'): Exportzeile
    {
        return new Exportzeile(1, 'R9d', new DateTimeImmutable($datum), $fach, $name, $benutzer, $kategorie, 'negativ', $text);
    }

    /** @return array{schluessel: string, vorlage: string} */
    private function gruppierbar(Exportzeile $z): array
    {
        $teile = $z->istKlasseneintrag() ? [] : Namensbestandteile::aus($z->name);

        return [
            'schluessel' => Gruppierer::schluessel(1, $z->datum->format('Y-m-d'), $z->fach, Fingerabdruck::lehrkraft($this->schluessel, $z), $z->kategorie),
            'vorlage' => Normalisierung::vergleich(Namensbestandteile::ersetze($z->text, $teile)),
        ];
    }

    public function testVierZeilenMitGleichemTextSindEinVorfall(): void
    {
        $zeilen = array_map([$this, 'gruppierbar'], [
            self::zeile('Beispiel Ben', 'Ben verlässt in der Pause das Gelände. Nachsitzen. KLM'),
            self::zeile('Test Tina', 'Tina verlässt in der Pause das Gelände. Nachsitzen. KLM'),
            self::zeile('Muster Max', 'Max verlässt in der Pause das Gelände. Nachsitzen. KLM'),
            self::zeile('Anders Anna-Lena', 'Anna verlässt in der Pause das Gelände.  Nachsitzen. KLM'),
        ]);

        $ergebnis = Gruppierer::ordneZu($zeilen, []);

        self::assertSame([0, 0, 0, 0], array_column($ergebnis, 'gruppe'));
    }

    public function testZweiVorfaelleDerselbenStundeBleibenGetrennt(): void
    {
        $zeilen = array_map([$this, 'gruppierbar'], [
            self::zeile('Beispiel Ben', 'Ben verlässt in der Pause das Gelände. Nachsitzen. KLM'),
            self::zeile('Test Tina', 'Tina beleidigt eine Mitschülerin und wird vor die Tür gesetzt. KLM'),
            self::zeile('Muster Max', 'Max verlässt in der Pause das Gelände. Nachsitzen. KLM'),
        ]);

        $ergebnis = Gruppierer::ordneZu($zeilen, []);

        self::assertSame([0, 1, 0], array_column($ergebnis, 'gruppe'));
    }

    public function testAndereLehrkraftOderKategorieTrennt(): void
    {
        $zeilen = array_map([$this, 'gruppierbar'], [
            self::zeile('Beispiel Ben', 'Ben stört. KLM'),
            self::zeile('Test Tina', 'Tina stört. KLM', benutzer: 'XYZ'),
            self::zeile('Muster Max', 'Max stört. KLM', kategorie: 'groß/Schüler*in'),
            self::zeile('Anders Anna', 'Anna stört. KLM', fach: 'M-G'),
        ]);

        self::assertSame([0, 1, 2, 3], array_column(Gruppierer::ordneZu($zeilen, []), 'gruppe'));
    }

    public function testZeileFindetIhrenBekanntenVorfall(): void
    {
        $neu = $this->gruppierbar(self::zeile('Muster Max', 'Max verlässt in der Pause das Gelände. Nachsitzen. KLM'));
        $bekannt = $this->gruppierbar(self::zeile('Beispiel Ben', 'Ben verlässt in der Pause das Gelände. Nachsitzen. KLM'));

        $ergebnis = Gruppierer::ordneZu([$neu], [
            ['id' => 41, 'schluessel' => $bekannt['schluessel'], 'vorlage' => 'etwas ganz anderes'],
            ['id' => 42, 'schluessel' => $bekannt['schluessel'], 'vorlage' => $bekannt['vorlage']],
        ]);

        self::assertSame([['art' => 'bekannt', 'vorfall' => 42]], $ergebnis);
    }

    public function testOhneVorlageWirdZuordnungGeprueft(): void
    {
        $neu = $this->gruppierbar(self::zeile('Muster Max', 'Max verlässt das Gelände. KLM'));

        $ergebnis = Gruppierer::ordneZu([$neu, $neu], [
            ['id' => 7, 'schluessel' => $neu['schluessel'], 'vorlage' => null],
        ]);

        self::assertSame('neu', $ergebnis[0]['art']);
        self::assertSame(7, $ergebnis[0]['moeglich_zu'] ?? null);
        self::assertSame(0, $ergebnis[1]['gruppe'] ?? null, 'Die zweite gleiche Zeile kommt in denselben neuen Vorfall.');
    }

    public function testNamensteileUndPlatzhalter(): void
    {
        self::assertSame(['Anders Anna-Lena', 'Anna-Lena', 'Anders', 'Anna', 'Lena'], Namensbestandteile::aus(' Anders  Anna-Lena '));
        self::assertSame([], Namensbestandteile::aus(''));
        self::assertSame(['Ox X', 'Ox'], Namensbestandteile::aus('Ox X'), 'Einzelne Buchstaben sind keine Namensteile.');

        $teile = Namensbestandteile::aus('Beispiel Ben');
        self::assertSame(
            '⟨Name⟩ und ⟨Name⟩ stören; z. B. Benjamin nicht. ⟨Name⟩ geht.',
            Namensbestandteile::ersetze('Ben und BEN stören; z. B. Benjamin nicht. B. geht.', $teile)
        );
    }

    public function testNamensKennungen(): void
    {
        $kennungen = Namensbestandteile::kennungen($this->schluessel, Namensbestandteile::aus('Beispiel Ben'));

        self::assertCount(2, $kennungen, 'Nur einzelne Woerter, nicht der ganze Name.');
        self::assertContains(Namensbestandteile::kennung($this->schluessel, 'BEN'), $kennungen);
        self::assertNotContains(Namensbestandteile::kennung($this->schluessel, 'Tina'), $kennungen);
        foreach ($kennungen as $kennung) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $kennung);
        }
    }

    public function testAehnlichkeit(): void
    {
        self::assertSame(1.0, Aehnlichkeit::verhaeltnis('', ''));
        self::assertSame(1.0, Aehnlichkeit::verhaeltnis('gleich', 'gleich'));
        self::assertSame(0.0, Aehnlichkeit::verhaeltnis('abc', 'xyz'));
        // Ein Umlaut ist ein Zeichen, nicht zwei.
        self::assertEqualsWithDelta(0.8, Aehnlichkeit::verhaeltnis('störe', 'stöße'), 0.0001);
        self::assertGreaterThanOrEqual(0.8, Aehnlichkeit::verhaeltnis('⟨name⟩ stört. klm', '⟨name⟩ stört! klm'));
    }

    public function testFingerabdruck(): void
    {
        $eine = self::zeile('Muster Max', 'Max stört.');
        $gleich = new Exportzeile(99, 'R9d', new DateTimeImmutable('2026-10-01'), 'E-G', 'Muster Max', 'KLM', 'klein/Schüler*in', 'anders egal', 'Max stört.');

        // Zeilennummer und Kategoriegruppe gehoeren nicht dazu.
        self::assertSame(Fingerabdruck::von($this->schluessel, $eine), Fingerabdruck::von($this->schluessel, $gleich));

        foreach ([
            self::zeile('Muster Moritz', 'Max stört.'),
            self::zeile('Muster Max', 'Max stört!'),
            self::zeile('Muster Max', 'Max stört.', fach: 'M-G'),
            self::zeile('Muster Max', 'Max stört.', benutzer: 'XYZ'),
            self::zeile('Muster Max', 'Max stört.', kategorie: ''),
            self::zeile('Muster Max', 'Max stört.', datum: '2026-10-02'),
        ] as $andere) {
            self::assertNotSame(Fingerabdruck::von($this->schluessel, $eine), Fingerabdruck::von($this->schluessel, $andere));
        }

        self::assertStringNotContainsString('Max', Fingerabdruck::von($this->schluessel, $eine));
    }

    public function testNormalisierung(): void
    {
        self::assertSame('Text', Normalisierung::feld("\u{00A0} Text \t"));
        self::assertSame("J\u{00F6}rg", Normalisierung::feld("Jo\u{0308}rg"));
        self::assertSame('ein text', Normalisierung::vergleich("  Ein \n  TEXT "));
    }
}
