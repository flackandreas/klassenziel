<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Import\ExportParser;
use Klassenziel\Import\ImportFehler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nur mit erfundenen Daten. Der echte Export gehoert nie hierher.
 */
final class ExportParserTest extends TestCase
{
    private const KOPF = ['Klasse', 'Datum', 'Fach', 'Name', 'Benutzer', 'Eintragskategorie', 'Kategoriegruppe', 'Text'];

    private static function datei(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/daten/' . $name);
    }

    /**
     * @param list<list<string>> $zeilen
     */
    private static function baue(string $trenner, array $zeilen, string $zeilenende = "\n"): string
    {
        $quote = static fn (string $feld): string => (str_contains($feld, $trenner) || str_contains($feld, '"') || str_contains($feld, "\n"))
            ? '"' . str_replace('"', '""', $feld) . '"'
            : $feld;

        $text = '';
        foreach ([self::KOPF, ...$zeilen] as $zeile) {
            $text .= implode($trenner, array_map($quote, $zeile)) . $zeilenende;
        }

        return $text;
    }

    public function testBeispielexportMitTabulator(): void
    {
        $ergebnis = ExportParser::lies(self::datei('export_beispiel.tsv'));

        self::assertSame("\t", $ergebnis['trennzeichen']);
        self::assertSame([], $ergebnis['unlesbar']);
        self::assertCount(9, $ergebnis['zeilen'], 'Die Leerzeile am Ende zaehlt nicht.');

        $erste = $ergebnis['zeilen'][0];
        self::assertSame('R9d', $erste->klasse);
        self::assertSame('2026-09-14', $erste->datum->format('Y-m-d'));
        self::assertSame('', $erste->fach, 'Leeres Fach bleibt leer.');
        self::assertTrue($erste->istKlasseneintrag());
        self::assertSame('Inf. / neutral', $erste->kategoriegruppe);

        $anfuehrung = $ergebnis['zeilen'][7];
        self::assertSame('Unterricht heute kaum möglich, "Tisch vor der Tür" eingerichtet. MIC', $anfuehrung->text);

        $ohneKategorie = $ergebnis['zeilen'][5];
        self::assertSame('', $ohneKategorie->kategorie);
        self::assertSame('', $ohneKategorie->kategoriegruppe);
        self::assertFalse($ohneKategorie->istKlasseneintrag());
    }

    public function testRandleerzeichenWerdenAbgeschnitten(): void
    {
        $ergebnis = ExportParser::lies(self::datei('export_beispiel.tsv'));

        // "...Nachsitzen. KLM " steht im Export mit Leerzeichen am Ende.
        self::assertSame('Ben verlässt in der Pause das Gelände. Nachsitzen. KLM', $ergebnis['zeilen'][2]->text);
    }

    public function testZeilenumbruchInAnfuehrungszeichen(): void
    {
        $ergebnis = ExportParser::lies(self::datei('export_folge.tsv'));
        $letzte = $ergebnis['zeilen'][count($ergebnis['zeilen']) - 1];

        self::assertSame("Ben wirft mit Papierkugeln,\nobwohl schon ermahnt. NOP", $letzte->text);
        self::assertSame(13, $letzte->zeilennummer);
    }

    /** @return iterable<string,array{string}> */
    public static function trennzeichen(): iterable
    {
        yield 'Tabulator' => ["\t"];
        yield 'Semikolon' => [';'];
        yield 'Komma' => [','];
    }

    #[DataProvider('trennzeichen')]
    public function testTrennzeichenWerdenErkannt(string $trenner): void
    {
        $inhalt = self::baue($trenner, [
            ['R9d', '01.10.26', 'E-G', 'Beispiel, Ben', 'KLM', 'klein/Schüler*in', 'negativ', 'Ben sagt "Nein"; geht, kommt wieder.'],
            ['R9d', '02.10.26', '', 'R9d', 'KLM', '', '', ''],
        ]);

        $ergebnis = ExportParser::lies($inhalt);

        self::assertSame($trenner, $ergebnis['trennzeichen']);
        self::assertCount(2, $ergebnis['zeilen']);
        self::assertSame('Beispiel, Ben', $ergebnis['zeilen'][0]->name);
        self::assertSame('Ben sagt "Nein"; geht, kommt wieder.', $ergebnis['zeilen'][0]->text);
        self::assertSame('', $ergebnis['zeilen'][1]->text);
    }

    public function testBomUndWindowsZeilenenden(): void
    {
        $inhalt = "\xEF\xBB\xBF" . self::baue("\t", [['R9d', '16.09.26', 'G', 'Muster Max', 'XYZ', 'klein/Schüler*in', 'negativ', 'stört']], "\r\n") . "\r\n";

        $ergebnis = ExportParser::lies($inhalt);

        self::assertCount(1, $ergebnis['zeilen']);
        self::assertSame('R9d', $ergebnis['zeilen'][0]->klasse);
        self::assertSame('stört', $ergebnis['zeilen'][0]->text);
    }

    public function testKodierungAusExcel(): void
    {
        $utf8 = self::baue(';', [['R9d', '16.09.26', 'G', 'Müller Jörg', 'XYZ', 'groß/Schüler*in', 'negativ', 'Störung']]);
        $windows = (string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        self::assertFalse(mb_check_encoding($windows, 'UTF-8'));

        $zeile = ExportParser::lies($windows)['zeilen'][0];

        self::assertSame('Müller Jörg', $zeile->name);
        self::assertSame('groß/Schüler*in', $zeile->kategorie);
    }

    public function testUmlauteAlsNfc(): void
    {
        // "ö" als o + kombinierendes Trema.
        $zerlegt = "Jo\u{0308}rg";
        $zeile = ExportParser::lies(self::baue("\t", [['R9d', '16.09.26', 'G', $zerlegt, 'XYZ', '', '', 'x']]))['zeilen'][0];

        self::assertSame("J\u{00F6}rg", $zeile->name);
    }

    public function testDatumsformen(): void
    {
        self::assertSame('2026-09-16', ExportParser::datum('16.09.26')?->format('Y-m-d'));
        self::assertSame('2027-01-05', ExportParser::datum('5.1.27')?->format('Y-m-d'));
        self::assertSame('2026-09-16', ExportParser::datum('16.09.2026')?->format('Y-m-d'));
        self::assertNull(ExportParser::datum('31.02.27'));
        self::assertNull(ExportParser::datum('2026-09-16'));
        self::assertNull(ExportParser::datum(''));
    }

    public function testUnlesbareZeilenWerdenGemeldetOhneInhalt(): void
    {
        $inhalt = self::baue("\t", [
            ['R9d', '16.09.26', 'G', 'Muster Max', 'XYZ', '', '', 'gut'],
            ['R9d', 'gestern', 'G', 'Muster Max', 'XYZ', '', '', 'kaputt'],
            ['', '17.09.26', 'G', 'Muster Max', 'XYZ', '', '', 'ohne Klasse'],
        ]);

        $ergebnis = ExportParser::lies($inhalt);

        self::assertCount(1, $ergebnis['zeilen']);
        self::assertSame([3, 4], $ergebnis['unlesbar']);
    }

    public function testSpaltenReihenfolgeUndGrossschreibungEgal(): void
    {
        $inhalt = "TEXT\tklasse\tDatum\tFach\tName\tBenutzer\tEintragskategorie\tKategoriegruppe\tZusatz\n"
            . "hallo\tR9d\t01.10.26\tD-M\tTest Tina\tNOP\t\t\tegal\n";

        $zeile = ExportParser::lies($inhalt)['zeilen'][0];

        self::assertSame('hallo', $zeile->text);
        self::assertSame('D-M', $zeile->fach);
    }

    public function testFremdeDateiWirdAbgewiesen(): void
    {
        $this->expectException(ImportFehler::class);
        $this->expectExceptionMessageMatches('/Es fehlen die Spalten: .*Eintragskategorie/');

        ExportParser::lies("Name;Vorname;Klasse\nMuster;Max;9d\n");
    }

    public function testLeereDatei(): void
    {
        $this->expectException(ImportFehler::class);
        ExportParser::lies("\xEF\xBB\xBF\n\n");
    }

    public function testFehlermeldungNenntKeineInhalte(): void
    {
        try {
            ExportParser::lies("Muster Max\tgeheimer Text\n");
            self::fail('Es haette eine Ausnahme geben muessen.');
        } catch (ImportFehler $e) {
            self::assertStringNotContainsString('Muster', $e->getMessage());
            self::assertStringNotContainsString('geheim', $e->getMessage());
        }
    }

    public function testZeileVerraetSichNichtImDump(): void
    {
        $zeile = ExportParser::lies(self::datei('export_beispiel.tsv'))['zeilen'][1];

        $dump = print_r($zeile, true);
        self::assertStringNotContainsString('Muster', $dump);
        self::assertStringNotContainsString('XYZ', $dump);
        self::assertStringNotContainsString('stört', $dump);
    }
}
