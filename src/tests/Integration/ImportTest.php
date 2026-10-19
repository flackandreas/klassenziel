<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use DateTimeImmutable;
use Klassenziel\Import\ImportFehler;
use Klassenziel\Import\Importdienst;
use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Tresor;
use PDO;

/**
 * Der Import gegen eine echte Datenbank - mit den erfundenen Exporten aus
 * tests/daten. Der echte Export gehoert nie hierher.
 */
final class ImportTest extends Datenbankfall
{
    /** Alles, was aus den Spalten Name und Benutzer kommt. */
    private const NAMEN = ['Muster', 'Max', 'Beispiel', 'Ben', 'Test', 'Tina', 'Anders', 'Anna'];
    private const KUERZEL = ['ABC', 'XYZ', 'KLM', 'DEF', 'MIC', 'NOP', 'QRS'];

    private Importdienst $dienst;
    private KlassenRepository $klassen;
    private Konto $lehrerin;
    private int $klasseId;
    private DateTimeImmutable $jetzt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klassen = new KlassenRepository($this->db(), $this->schluessel());
        $this->dienst = new Importdienst($this->db(), $this->schluessel(), $this->klassen);
        $this->lehrerin = $this->konto('Lehrerin Eins');
        $this->jetzt = new DateTimeImmutable('2026-10-19 13:31:00');

        $daten = Klassendaten::ausEingabe([
            'anzeigename' => 'Klasse 9d',
            'export_kuerzel' => 'R9d',
            'schuljahr' => '2026/27',
            'belohnung' => 'Kanutour mit Übernachtung',
            'startdatum' => '2026-09-14',
            'stichtag' => '2027-06-30',
            'startwert' => '50',
            'zielwert' => '70',
        ]);
        $this->klasseId = $this->klassen->anlegen($daten, $this->lehrerin);
    }

    private static function datei(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/daten/' . $name);
    }

    /** @return array<string,mixed> */
    private function plan(int $id): array
    {
        $geladen = $this->dienst->lade($id, $this->lehrerin);
        self::assertNotNull($geladen);
        self::assertIsArray($geladen['plan']);

        return $geladen['plan'];
    }

    private function importiere(string $datei, string $name = 'ClassregEventPerKlasse_20261019_1331.csv'): int
    {
        $id = $this->dienst->vorschau(self::datei($datei), $name, $this->lehrerin, $this->jetzt);
        $this->dienst->bestaetigen($id, $this->lehrerin, $this->jetzt);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function vorfaelle(): array
    {
        $abfrage = $this->db()->prepare('SELECT * FROM vorfaelle WHERE klasse_id = ? ORDER BY datum, id');
        $abfrage->execute([$this->klasseId]);

        return $abfrage->fetchAll() ?: [];
    }

    public function testVorschauZaehltUndGruppiert(): void
    {
        $id = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'ClassregEventPerKlasse_20261019_1331.csv', $this->lehrerin, $this->jetzt);
        $plan = $this->plan($id);

        self::assertSame(8, $plan['zaehler']['zeilen_neu']);
        self::assertSame(0, $plan['zaehler']['zeilen_duplikat']);
        self::assertSame(1, $plan['zaehler']['zeilen_fremd']);
        self::assertSame(6, $plan['zaehler']['vorfaelle_neu']);
        self::assertSame(5, $plan['zaehler']['pruefliste']);
        self::assertSame(1, $plan['zaehler']['ohne_einstufung'], 'Der Eintrag vom 06.10. hat keine Kategorie.');
        self::assertSame(1, $plan['zaehler']['neutral']);
        self::assertSame([['kuerzel' => 'R9e', 'grund' => 'unbekannt', 'zeilen' => 1]], $plan['fremd']);
        self::assertSame('2026-10-19 13:31:00', $plan['exportzeitpunkt']);

        // Bis zum Bestaetigen ist nichts gespeichert.
        self::assertSame([], $this->vorfaelle());
        self::assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM importzeilen')->fetchColumn());
    }

    public function testBestaetigenLegtVorfaelleAn(): void
    {
        $this->importiere('export_beispiel.tsv');
        $vorfaelle = $this->vorfaelle();

        self::assertCount(6, $vorfaelle);
        self::assertSame(
            [
                ['2026-09-14', 'ignoriert', 0, 1],
                ['2026-09-16', 'offen', 1, 0],
                ['2026-10-01', 'offen', 2, 0],
                ['2026-10-05', 'offen', 1, 0],
                ['2026-10-06', 'offen', 2, 0],
                ['2026-10-09', 'offen', 0, 1],
            ],
            array_map(static fn (array $v): array => [
                (string) $v['datum'],
                (string) $v['status'],
                (int) $v['anzahl_beteiligte'],
                (int) $v['klasseneintrag'],
            ], $vorfaelle)
        );

        // Neutrale Eintraege: kein Text, keine Namen.
        self::assertNull($vorfaelle[0]['originaltext']);
        self::assertNull($vorfaelle[0]['text_vorlage']);
        self::assertNull($vorfaelle[0]['namens_kennungen']);

        // Der Originaltext liegt verschluesselt da, einmal je Vorfall.
        $tresor = new Tresor($this->schluessel());
        self::assertSame(
            'Ben verlässt in der Pause das Gelände. Nachsitzen. KLM',
            $tresor->oeffnen((string) $vorfaelle[2]['originaltext'], Importdienst::ZWECK_ORIGINAL)
        );

        self::assertSame(8, (int) $this->db()->query('SELECT COUNT(*) FROM importzeilen')->fetchColumn());

        $klasse = $this->klassen->nachId($this->klasseId);
        self::assertSame('2026-10-19 13:31', $klasse?->datenstand?->format('Y-m-d H:i'));

        // Neue Faecher und Kategorien sind fuer die Verwaltung vermerkt.
        $faecher = $this->db()->query('SELECT kuerzel FROM faecher ORDER BY kuerzel')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['E-G', 'G', 'GK', 'M-G'], $faecher);
    }

    public function testDerselbeExportZweimalErgibtNichtsNeues(): void
    {
        $this->importiere('export_beispiel.tsv');

        $id = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'ClassregEventPerKlasse_20261019_1331.csv', $this->lehrerin, $this->jetzt);
        $plan = $this->plan($id);

        self::assertSame(0, $plan['zaehler']['zeilen_neu']);
        self::assertSame(8, $plan['zaehler']['zeilen_duplikat']);
        self::assertSame(0, $plan['zaehler']['vorfaelle_neu']);

        $this->dienst->bestaetigen($id, $this->lehrerin, $this->jetzt);
        self::assertCount(6, $this->vorfaelle());
        self::assertSame(8, (int) $this->db()->query('SELECT COUNT(*) FROM importzeilen')->fetchColumn());
    }

    public function testKumulativerFolgeexportFuegtNurNeuesHinzu(): void
    {
        $this->importiere('export_beispiel.tsv');

        $id = $this->dienst->vorschau(self::datei('export_folge.tsv'), 'ClassregEventPerKlasse_20261026_1200.csv', $this->lehrerin, $this->jetzt);
        $plan = $this->plan($id);

        self::assertSame(3, $plan['zaehler']['zeilen_neu']);
        self::assertSame(8, $plan['zaehler']['zeilen_duplikat']);
        self::assertSame(2, $plan['zaehler']['vorfaelle_neu'], 'Zwei verschiedene Vorfaelle am 12.10. in derselben Stunde.');
        self::assertSame(1, $plan['zaehler']['vorfaelle_zugeordnet'], 'Max kommt zum bekannten Vorfall vom 01.10.');

        $this->dienst->bestaetigen($id, $this->lehrerin, $this->jetzt);

        $vorfaelle = $this->vorfaelle();
        self::assertCount(8, $vorfaelle);

        $geländeVorfall = array_values(array_filter($vorfaelle, static fn (array $v): bool => $v['datum'] === '2026-10-01'));
        self::assertCount(1, $geländeVorfall);
        self::assertSame(3, (int) $geländeVorfall[0]['anzahl_beteiligte'], 'Ein Vorfall zaehlt einmal, mit drei Beteiligten.');
        // Je Beteiligte Vor- und Nachname als Kennung: Ben, Tina, Max.
        self::assertCount(6, (array) json_decode((string) $geländeVorfall[0]['namens_kennungen'], true));

        $klasse = $this->klassen->nachId($this->klasseId);
        self::assertSame('2026-10-26 12:00', $klasse?->datenstand?->format('Y-m-d H:i'));
    }

    public function testLoeschregelFuerOriginaltexte(): void
    {
        $this->importiere('export_beispiel.tsv');

        // Zwei Vorfaelle sind entschieden: der vom 16.09. und der vom 01.10.
        $this->db()->exec(
            "UPDATE vorfaelle SET status = 'freigegeben', kurztext = 'Unterrichtsstörung', entschieden_am = '2026-10-20 08:00:00'
              WHERE klasse_id = {$this->klasseId} AND datum IN ('2026-09-16', '2026-10-01')"
        );

        $id = $this->dienst->vorschau(self::datei('export_folge.tsv'), 'ClassregEventPerKlasse_20261026_1200.csv', $this->lehrerin, new DateTimeImmutable('2026-10-26 13:00'));
        $ergebnis = $this->dienst->bestaetigen($id, $this->lehrerin, new DateTimeImmutable('2026-10-26 13:00'));

        self::assertSame(1, $ergebnis['texte_geloescht']);

        $nachDatum = [];
        foreach ($this->vorfaelle() as $v) {
            $nachDatum[(string) $v['datum']][] = $v;
        }

        // 16.09.: entschieden, der Folgeexport fuegt nichts hinzu -> weg.
        self::assertNull($nachDatum['2026-09-16'][0]['originaltext']);
        self::assertNull($nachDatum['2026-09-16'][0]['text_vorlage']);
        self::assertSame('Unterrichtsstörung', $nachDatum['2026-09-16'][0]['kurztext']);

        // 01.10.: entschieden, aber der Folgeexport bringt einen weiteren
        // Beteiligten -> bleibt bis zum naechsten Import.
        self::assertNotNull($nachDatum['2026-10-01'][0]['originaltext']);

        // Offene Vorfaelle behalten ihren Text.
        self::assertNotNull($nachDatum['2026-10-05'][0]['originaltext']);
    }

    public function testNachzueglerOhneTextWirdZurPruefung(): void
    {
        $this->importiere('export_beispiel.tsv');
        $this->db()->exec(
            "UPDATE vorfaelle SET status = 'freigegeben', entschieden_am = '2026-10-20 08:00:00', originaltext = NULL, text_vorlage = NULL
              WHERE klasse_id = {$this->klasseId} AND datum = '2026-10-01'"
        );

        $id = $this->dienst->vorschau(self::datei('export_folge.tsv'), 'ClassregEventPerKlasse_20261026_1200.csv', $this->lehrerin, $this->jetzt);
        $this->dienst->bestaetigen($id, $this->lehrerin, $this->jetzt);

        $zumPruefen = array_values(array_filter($this->vorfaelle(), static fn (array $v): bool => $v['status'] === 'zuordnung_pruefen'));
        self::assertCount(1, $zumPruefen);
        self::assertSame('2026-10-01', $zumPruefen[0]['datum']);
        self::assertNotNull($zumPruefen[0]['moeglich_zu']);
    }

    public function testKeineNamenUndKeineKuerzelImKlartext(): void
    {
        $this->importiere('export_beispiel.tsv');
        $this->importiere('export_folge.tsv', 'ClassregEventPerKlasse_20261026_1200.csv');

        // Eine offene Vorschau gehoert dazu: auch ihr Plan liegt in der Datenbank.
        $this->dienst->vorschau(self::datei('export_folge.tsv'), 'Max_Muster_privat.csv', $this->lehrerin, $this->jetzt);

        $verschluesselt = ['originaltext', 'text_vorlage', 'link_token', 'plan'];
        $tabellen = $this->db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($tabellen as $tabelle) {
            foreach ($this->db()->query('SELECT * FROM `' . $tabelle . '`')->fetchAll() ?: [] as $zeile) {
                foreach ($zeile as $spalte => $wert) {
                    if ($wert === null) {
                        continue;
                    }

                    if (in_array($spalte, $verschluesselt, true)) {
                        self::assertStringStartsWith('v1:', (string) $wert, "$tabelle.$spalte ist nicht verschluesselt");
                        continue;
                    }

                    foreach ([...self::NAMEN, ...self::KUERZEL] as $verboten) {
                        self::assertDoesNotMatchRegularExpression(
                            '/\b' . preg_quote($verboten, '/') . '\b/u',
                            (string) $wert,
                            "{$tabelle}.{$spalte} enthaelt „{$verboten}“"
                        );
                    }
                    self::assertStringNotContainsString('verlässt', (string) $wert, "$tabelle.$spalte enthaelt Originaltext");
                }
            }
        }

        // Der frei gewaehlte Dateiname mit Namen ist nicht gespeichert.
        self::assertSame(
            ['ClassregEventPerKlasse_20261019_1331.csv', 'ClassregEventPerKlasse_20261026_1200.csv', 'Export'],
            $this->db()->query('SELECT dateiname FROM importe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testDerVorschauplanIstNamensfrei(): void
    {
        $id = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'x.csv', $this->lehrerin, $this->jetzt);
        $plan = $this->plan($id);

        // Die schon verschluesselten Felder heraus - in einem Zufallstext
        // kann "Max" zufaellig vorkommen. Uebrig bleiben muss namensfrei sein.
        foreach ($plan['neue_vorfaelle'] as &$vorfall) {
            self::assertTrue($vorfall['originaltext'] === null || str_starts_with($vorfall['originaltext'], 'v1:'));
            unset($vorfall['originaltext'], $vorfall['text_vorlage']);

            // Die Kurzform traegt den Platzhalter statt der Namen dieser Zeile.
            foreach (self::NAMEN as $name) {
                self::assertDoesNotMatchRegularExpression('/\b' . $name . '\b/u', (string) $vorfall['kurzform']);
            }
            unset($vorfall['kurzform']);
        }
        unset($vorfall);

        $json = (string) json_encode($plan, JSON_UNESCAPED_UNICODE);
        foreach ([...self::NAMEN, ...self::KUERZEL] as $verboten) {
            self::assertDoesNotMatchRegularExpression('/\b' . preg_quote($verboten, '/') . '\b/u', $json, $verboten);
        }
    }

    public function testFremdeKlasseIstNichtZustaendig(): void
    {
        $kollege = $this->konto('Kollege Zwei');

        $id = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'x.csv', $kollege, $this->jetzt);
        $geladen = $this->dienst->lade($id, $kollege);
        self::assertNotNull($geladen);
        $plan = $geladen['plan'];
        self::assertIsArray($plan);

        self::assertSame(0, $plan['zaehler']['zeilen_neu']);
        self::assertSame(9, $plan['zaehler']['zeilen_fremd']);
        self::assertContains(['kuerzel' => 'R9d', 'grund' => 'nicht_zustaendig', 'zeilen' => 8], $plan['fremd']);

        // Und die fremde Vorschau ist fuer die Lehrerin nicht da.
        self::assertNull($this->dienst->lade($id, $this->lehrerin));

        $this->expectException(ImportFehler::class);
        $this->dienst->bestaetigen($id, $this->lehrerin, $this->jetzt);
    }

    public function testAbgelaufeneVorschau(): void
    {
        $id = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'x.csv', $this->lehrerin, $this->jetzt);

        try {
            $this->dienst->bestaetigen($id, $this->lehrerin, $this->jetzt->modify('+61 minutes'));
            self::fail('Abgelaufen haette es sein muessen.');
        } catch (ImportFehler) {
            self::assertSame('verworfen', $this->db()->query("SELECT status FROM importe WHERE id = $id")->fetchColumn());
            self::assertNull($this->db()->query("SELECT plan FROM importe WHERE id = $id")->fetchColumn());
        }

        self::assertSame([], $this->vorfaelle());
    }

    public function testFalscherSchluesselSperrtDenImport(): void
    {
        $this->importiere('export_beispiel.tsv');
        $this->db()->exec("UPDATE schluesselpruefung SET pruefwert = '" . str_repeat('0', 64) . "' WHERE id = 1");

        $this->expectException(ImportFehler::class);
        $this->expectExceptionMessageMatches('/doppelt/');
        $this->dienst->vorschau(self::datei('export_folge.tsv'), 'x.csv', $this->lehrerin, $this->jetzt);
    }

    public function testParallelerImportWirdErkannt(): void
    {
        $erste = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'x.csv', $this->lehrerin, $this->jetzt);
        $zweite = $this->dienst->vorschau(self::datei('export_beispiel.tsv'), 'x.csv', $this->lehrerin, $this->jetzt);

        $this->dienst->bestaetigen($erste, $this->lehrerin, $this->jetzt);

        $this->expectException(ImportFehler::class);
        $this->expectExceptionMessageMatches('/anderer Import/');
        $this->dienst->bestaetigen($zweite, $this->lehrerin, $this->jetzt);
    }

    public function testDateiname(): void
    {
        $jetzt = new DateTimeImmutable('2026-10-19 15:00');

        [$name, $zeit] = Importdienst::dateiname('C:\\Downloads\\ClassregEventPerKlasse_20261019_1331.csv', $jetzt);
        self::assertSame('ClassregEventPerKlasse_20261019_1331.csv', $name);
        self::assertSame('2026-10-19 13:31', $zeit->format('Y-m-d H:i'));

        [$name, $zeit] = Importdienst::dateiname('Klassenbuch Max Muster.csv', $jetzt);
        self::assertSame('Export', $name);
        self::assertSame($jetzt, $zeit);

        [$name] = Importdienst::dateiname('ClassregEventPerKlasse_20261399_9999.csv', $jetzt);
        self::assertSame('Export', $name);
    }
}
