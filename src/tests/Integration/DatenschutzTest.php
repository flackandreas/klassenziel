<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use DateTimeImmutable;
use Klassenziel\Import\ImportFehler;
use Klassenziel\Import\Importdienst;
use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Klassen\Namensliste;
use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Tresor;
use Klassenziel\Sicherheit\TresorFehler;
use Klassenziel\Vorfaelle\VorfallRepository;
use PDO;

/**
 * Der Datenschutz-Test (Planung, Abschnitt 11).
 *
 * Ein ganzer Durchlauf mit erfundenen Daten - zwei Klassen, Import,
 * Entscheidungen, Namensliste, Folge-Export, eine offene Vorschau, eine
 * archivierte Klasse, Abrufe der oeffentlichen Seite und ein absichtlich
 * ausgeloester Fehler. Danach darf kein Wert aus der Spalte "Name", kein
 * Lehrkraftkuerzel und kein Originaltext im Klartext stehen:
 *
 *   - in keiner Spalte der Datenbank (ausser verschluesselt),
 *   - in keiner Datei der Ablage (Zwischenspeicher),
 *   - in keinem Protokoll (Fehlerprotokoll des Moduls und des Servers),
 *   - in keiner oeffentlichen Antwort (HTML und JSON).
 */
final class DatenschutzTest extends Datenbankfall
{
    use Testserver;

    /** Alles, was in den Spalten Name und Benutzer der Testdateien steht. */
    private const NAMEN = ['Muster', 'Max', 'Beispiel', 'Ben', 'Test', 'Tina', 'Anders', 'Anna', 'Neu', 'Nora'];
    private const KUERZEL = ['ABC', 'XYZ', 'KLM', 'DEF', 'MIC', 'NOP', 'QRS', 'TUV'];
    /** Woerter, die nur in Originaltexten vorkommen. */
    private const ORIGINAL = ['verlässt', 'Ermahnung', 'Schulleitung', 'Tisch vor der Tür', 'Fluchtwege', 'prima', 'Papierkugeln', 'telefoniert', 'Kaugummi', 'zu spät'];

    /** Spalten, die verschluesselt sein muessen. */
    private const VERSCHLUESSELT = [
        'vorfaelle.originaltext' => Importdienst::ZWECK_ORIGINAL,
        'vorfaelle.text_vorlage' => Importdienst::ZWECK_VORLAGE,
        'klassen.link_token' => 'klassen.link_token',
        'importe.plan' => Importdienst::ZWECK_PLAN,
    ];

    private string $protokoll = '';
    private string $altesProtokoll = '';
    private Konto $lehrerin;
    private KlassenRepository $klassen;
    private Importdienst $import;
    private int $neunD = 0;
    private int $neunE = 0;
    private int $offeneVorschau = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::starteServer();

        // Das Fehlerprotokoll dieses Prozesses mitschneiden.
        $this->protokoll = sys_get_temp_dir() . '/klassenziel-datenschutz-' . getmypid() . '.log';
        @unlink($this->protokoll);
        $this->altesProtokoll = (string) ini_get('error_log');
        ini_set('error_log', $this->protokoll);

        $this->durchlauf();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->altesProtokoll);
        @unlink($this->protokoll);
        parent::tearDown();
    }

    private static function datei(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/daten/' . $name);
    }

    private function durchlauf(): void
    {
        $this->klassen = new KlassenRepository($this->db(), $this->schluessel());
        $this->import = new Importdienst($this->db(), $this->schluessel(), $this->klassen);
        $this->lehrerin = $this->konto('Lehrerin Eins');

        foreach (['R9d' => 'Klasse 9d', 'R9e' => 'Klasse 9e'] as $kuerzel => $name) {
            $id = $this->klassen->anlegen(Klassendaten::ausEingabe([
                'anzeigename' => $name, 'export_kuerzel' => $kuerzel, 'schuljahr' => '2026/27',
                'belohnung' => 'Kanutour mit Übernachtung', 'startdatum' => '2026-09-14', 'stichtag' => '2027-06-30',
                'startwert' => '50', 'zielwert' => '70',
            ]), $this->lehrerin);
            $kuerzel === 'R9d' ? $this->neunD = $id : $this->neunE = $id;
        }

        $jetzt = new DateTimeImmutable('2026-10-19 13:31');
        $id = $this->import->vorschau(self::datei('export_beispiel.tsv'), 'ClassregEventPerKlasse_20261019_1331.csv', $this->lehrerin, $jetzt);
        $this->import->bestaetigen($id, $this->lehrerin, $jetzt);

        $vorfaelle = new VorfallRepository($this->db(), $this->schluessel());
        foreach ($vorfaelle->fuerKlasse($this->neunD) as $vorfall) {
            match ((string) $vorfall['datum']) {
                '2026-09-16' => $vorfaelle->entscheide($this->neunD, (int) $vorfall['id'], 'freigegeben', 'negativ', 'klein', false, 'Unterrichtsstörung', $this->lehrerin),
                '2026-10-01' => $vorfaelle->entscheide($this->neunD, (int) $vorfall['id'], 'ignoriert', null, null, false, null, $this->lehrerin),
                '2026-10-05' => $vorfaelle->entscheide($this->neunD, (int) $vorfall['id'], 'ohne_text', 'negativ', 'gross', false, null, $this->lehrerin),
                default => null,
            };
        }
        $this->db()->exec("UPDATE vorfaelle SET entschieden_am = '2026-10-20 08:00:00' WHERE entschieden_von IS NOT NULL");

        (new Namensliste($this->db(), $this->schluessel()))->ersetze($this->neunD, "Max, Tina\nBen");

        $spaeter = new DateTimeImmutable('2026-10-26 12:00');
        $id = $this->import->vorschau(self::datei('export_folge.tsv'), 'ClassregEventPerKlasse_20261026_1200.csv', $this->lehrerin, $spaeter);
        $this->import->bestaetigen($id, $this->lehrerin, $spaeter);

        // Ein zweites Mal bestaetigen: ein Fehler, der gemeldet werden muss -
        // ohne Inhalte.
        try {
            $this->import->bestaetigen($id, $this->lehrerin, $spaeter);
        } catch (ImportFehler) {
        }

        // Eine offene Vorschau mit neuen Zeilen beider Klassen, und eine
        // Datei mit Namen im Dateinamen.
        $mehr = rtrim(self::datei('export_folge.tsv'), "\n") . "\n"
            . "R9d\t20.10.26\tBIO\tNeu Nora\tTUV\tklein/Schüler*in\tnegativ\tNora kaut Kaugummi. TUV\n"
            . "R9e\t20.10.26\tBIO\tAnders Anna\tTUV\tklein/Schüler*in\tnegativ\tAnna kommt wieder zu spät. TUV\n";
        $this->offeneVorschau = $this->import->vorschau($mehr, 'Nora Neu und Anna.csv', $this->lehrerin, $spaeter);

        // Die 9e wird archiviert - mit ihr muss die offene Vorschau gehen.
        $this->klassen->archivieren($this->neunE);

        // Abrufe der oeffentlichen Seite.
        $klasse = $this->klassen->nachId($this->neunD);
        self::assertNotNull($klasse);
        $link = '/klassenziel/' . $this->klassen->link($klasse);
        self::assertSame(200, self::hole($link)['status']);
        self::assertSame(200, self::hole($link . '?ansicht=beamer')['status']);
        self::assertSame(200, self::hole($link . '/stand.json?anzahl=200')['status']);
        self::assertSame(404, self::hole('/klassenziel/' . str_repeat('A', 32))['status']);
    }

    /**
     * @param list<string> $zusaetzlich
     */
    private static function pruefe(string $inhalt, string $wo, array $zusaetzlich = []): void
    {
        foreach ([...self::NAMEN, ...self::KUERZEL, ...$zusaetzlich] as $verboten) {
            self::assertDoesNotMatchRegularExpression('/\b' . preg_quote($verboten, '/') . '\b/u', $inhalt, "$wo enthaelt „{$verboten}“");
        }
        foreach (self::ORIGINAL as $wort) {
            self::assertStringNotContainsString($wort, $inhalt, "$wo enthaelt Originaltext „{$wort}“");
        }
    }

    public function testDatenbankOhneKlartext(): void
    {
        $tabellen = $this->db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tresor = new Tresor($this->schluessel());
        $verschluesselteWerte = 0;

        foreach ($tabellen as $tabelle) {
            foreach ($this->db()->query('SELECT * FROM `' . $tabelle . '`')->fetchAll() ?: [] as $zeile) {
                foreach ($zeile as $spalte => $wert) {
                    if ($wert === null) {
                        continue;
                    }

                    $wo = $tabelle . '.' . $spalte;
                    if (isset(self::VERSCHLUESSELT[$wo])) {
                        // Wirklich verschluesselt: mit dem richtigen Zweck zu
                        // oeffnen, mit einem anderen nicht.
                        $tresor->oeffnen((string) $wert, self::VERSCHLUESSELT[$wo]);
                        try {
                            $tresor->oeffnen((string) $wert, 'anderer.zweck');
                            self::fail("$wo laesst sich mit fremdem Zweck oeffnen");
                        } catch (TresorFehler) {
                        }
                        $verschluesselteWerte++;
                        continue;
                    }

                    self::pruefe((string) $wert, $wo);
                }
            }
        }

        self::assertGreaterThan(5, $verschluesselteWerte, 'Der Durchlauf hat verschluesselte Werte erzeugt.');

        // Gegenprobe: die Namen waren da - nur eben verschluesselt. Ohne diese
        // Pruefung bestuende der Test auch, wenn der Import nichts speicherte.
        // Nicht jeder Originaltext nennt einen Namen, also alle durchsehen.
        $originale = $this->db()->query(
            "SELECT originaltext FROM vorfaelle WHERE klasse_id = {$this->neunD} AND originaltext IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        self::assertNotSame([], $originale);
        $mitNamen = array_filter($originale, fn (mixed $original): bool => preg_match(
            '/\b(' . implode('|', self::NAMEN) . ')\b/u',
            $tresor->oeffnen((string) $original, Importdienst::ZWECK_ORIGINAL)
        ) === 1);
        self::assertNotSame([], $mitNamen, 'Mindestens ein Originaltext nennt einen Namen.');
    }

    public function testNachDemArchivierenBleibtNichtsAusDemKlassenbuch(): void
    {
        $abfrage = $this->db()->prepare(
            'SELECT COUNT(*) FROM vorfaelle WHERE klasse_id = ? AND (originaltext IS NOT NULL OR text_vorlage IS NOT NULL OR namens_kennungen IS NOT NULL)'
        );
        $abfrage->execute([$this->neunE]);
        self::assertSame(0, (int) $abfrage->fetchColumn());

        $abfrage = $this->db()->prepare('SELECT link_token, link_token_kennung, archiviert_am FROM klassen WHERE id = ?');
        $abfrage->execute([$this->neunE]);
        $klasse = $abfrage->fetch();
        self::assertIsArray($klasse);
        self::assertNull($klasse['link_token']);
        self::assertNull($klasse['link_token_kennung']);
        self::assertNotNull($klasse['archiviert_am']);

        // Die offene Vorschau mit einer Zeile der 9e ist verworfen.
        $abfrage = $this->db()->prepare('SELECT status, plan FROM importe WHERE id = ?');
        $abfrage->execute([$this->offeneVorschau]);
        $vorschau = $abfrage->fetch();
        self::assertIsArray($vorschau);
        self::assertSame('verworfen', $vorschau['status']);
        self::assertNull($vorschau['plan']);

        // Die 9d ist nicht archiviert und behaelt, was sie braucht.
        self::assertGreaterThan(0, (int) $this->db()->query("SELECT COUNT(*) FROM namensliste WHERE klasse_id = {$this->neunD}")->fetchColumn());
    }

    public function testLoeschregelHatErledigteTexteEntfernt(): void
    {
        $zeilen = $this->db()->query(
            "SELECT datum, status, originaltext IS NULL AS ohne_text FROM vorfaelle
              WHERE klasse_id = {$this->neunD} AND entschieden_von IS NOT NULL ORDER BY datum"
        )->fetchAll() ?: [];

        $nachDatum = array_column($zeilen, 'ohne_text', 'datum');
        self::assertSame(1, (int) $nachDatum['2026-09-16'], 'Entschieden, nichts hinzugekommen: Text geloescht.');
        self::assertSame(1, (int) $nachDatum['2026-10-05']);
        self::assertSame(0, (int) $nachDatum['2026-10-01'], 'Der Folgeexport brachte einen Beteiligten dazu: Text bleibt bis zum naechsten Import.');
    }

    public function testAblageOhneKlartext(): void
    {
        $dateien = 0;
        $verzeichnis = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::testablage(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($verzeichnis as $datei) {
            if ($datei instanceof \SplFileInfo && $datei->isFile()) {
                self::pruefe((string) file_get_contents($datei->getPathname()), 'Ablage ' . $datei->getFilename());
                $dateien++;
            }
        }

        self::assertGreaterThan(0, $dateien, 'Der Zwischenspeicher wurde benutzt.');
    }

    public function testOeffentlicheSeitenOhneKlartext(): void
    {
        $klasse = $this->klassen->nachId($this->neunD);
        self::assertNotNull($klasse);
        $link = '/klassenziel/' . $this->klassen->link($klasse);

        // Mit allen Eintraegen, damit auch die aeltesten durchsucht werden. Der
        // Beamer zeigt nur die neuesten, daher dort keine Gegenprobe.
        $seiten = [$link . '?eintraege=200' => true, $link . '/stand.json?anzahl=200' => true, $link . '?ansicht=beamer' => false];
        foreach ($seiten as $pfad => $gegenprobe) {
            $antwort = self::hole($pfad);
            self::assertSame(200, $antwort['status'], $pfad);
            if ($gegenprobe) {
                // Die Seite zeigt die Eintraege, nur ohne Namen.
                self::assertStringContainsString('Unterrichtsst', $antwort['inhalt'], $pfad);
            }
            self::pruefe($antwort['inhalt'], 'Die Seite ' . $pfad, ['R9d', 'R9e']);
        }
    }

    public function testProtokolleOhneKlartext(): void
    {
        // Ein unbehandelter Fehler mitten in der oeffentlichen Seite: die
        // Tabelle mit den Faechern fehlt.
        $klasse = $this->klassen->nachId($this->neunD);
        self::assertNotNull($klasse);
        $this->db()->exec('UPDATE klassen SET daten_version = daten_version + 1');
        $this->db()->exec('DROP TABLE faecher');
        self::assertSame(500, self::hole('/klassenziel/' . $this->klassen->link($klasse))['status']);

        $server = (string) @file_get_contents(sys_get_temp_dir() . '/klassenziel-testserver.log');
        self::assertStringContainsString('unbehandelter Fehler in anzeige.php', $server, 'Der Fehler ist protokolliert.');
        self::pruefe($server, 'Das Serverprotokoll');

        self::pruefe((string) @file_get_contents($this->protokoll), 'Das Fehlerprotokoll');
    }
}
