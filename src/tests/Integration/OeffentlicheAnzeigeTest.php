<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use DateTimeImmutable;
use Klassenziel\Import\Importdienst;
use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Vorfaelle\VorfallRepository;

/**
 * Die oeffentliche Klassenseite nach einem Import - ueber echtes HTTP, mit
 * dem erfundenen Export aus tests/daten.
 *
 * Kern der Pruefung: Weder ein Name noch ein Lehrkraftkuerzel noch ein Wort
 * aus dem Originaltext erreicht das HTML oder das JSON.
 */
final class OeffentlicheAnzeigeTest extends Datenbankfall
{
    use Testserver;

    private const NAMEN = ['Muster', 'Max', 'Beispiel', 'Ben', 'Test', 'Tina', 'Anders', 'Anna'];
    private const KUERZEL = ['ABC', 'XYZ', 'KLM', 'DEF', 'MIC', 'NOP', 'QRS'];
    /** Woerter, die nur im Originaltext stehen. */
    private const ORIGINAL = ['verlässt', 'Ermahnung', 'Schulleitung', 'Tisch vor der Tür', 'Fluchtwege', 'prima'];

    private string $link = '';

    protected function setUp(): void
    {
        parent::setUp();
        self::starteServer();

        $klassen = new KlassenRepository($this->db(), $this->schluessel());
        $lehrerin = $this->konto('Lehrerin Eins');
        $id = $klassen->anlegen(Klassendaten::ausEingabe([
            'anzeigename' => 'Klasse 9d',
            'export_kuerzel' => 'R9d',
            'schuljahr' => '2026/27',
            'belohnung' => 'Kanutour mit Übernachtung',
            'startdatum' => '2026-09-14',
            'stichtag' => '2027-06-30',
            'startwert' => '50',
            'zielwert' => '70',
        ]), $lehrerin);

        $import = new Importdienst($this->db(), $this->schluessel(), $klassen);
        $jetzt = new DateTimeImmutable('2026-10-19 13:31');
        $vorschau = $import->vorschau(
            (string) file_get_contents(dirname(__DIR__) . '/daten/export_beispiel.tsv'),
            'ClassregEventPerKlasse_20261019_1331.csv',
            $lehrerin,
            $jetzt
        );
        $import->bestaetigen($vorschau, $lehrerin, $jetzt);

        // Ein Vorfall freigegeben, einer ohne Text, einer eingestuft.
        $vorfaelle = new VorfallRepository($this->db(), $this->schluessel());
        foreach ($vorfaelle->fuerKlasse($id) as $vorfall) {
            match ((string) $vorfall['datum']) {
                '2026-09-16' => $vorfaelle->entscheide($id, (int) $vorfall['id'], 'freigegeben', 'negativ', 'klein', false, 'Unterrichtsstörung', $lehrerin),
                '2026-10-05' => $vorfaelle->entscheide($id, (int) $vorfall['id'], 'ohne_text', 'negativ', 'gross', false, null, $lehrerin),
                '2026-10-06' => $vorfaelle->entscheide($id, (int) $vorfall['id'], 'freigegeben', 'positiv', 'mittel', true, 'Gute Mitarbeit', $lehrerin),
                default => null,
            };
        }
        $this->db()->exec("UPDATE faecher SET klartext = 'Mathematik' WHERE kuerzel = 'M-G'");

        $klasse = $klassen->nachId($id);
        self::assertNotNull($klasse);
        $this->link = '/klassenziel/' . $klassen->link($klasse);
    }

    private static function pruefeNamensfrei(string $inhalt, string $was): void
    {
        foreach ([...self::NAMEN, ...self::KUERZEL] as $verboten) {
            self::assertDoesNotMatchRegularExpression('/\b' . preg_quote($verboten, '/') . '\b/u', $inhalt, "$was enthaelt „{$verboten}“");
        }
        foreach (self::ORIGINAL as $wort) {
            self::assertStringNotContainsString($wort, $inhalt, "$was enthaelt Originaltext „{$wort}“");
        }
        foreach (['Schüler*in', 'klein/Klasse', 'Inf. / neutral', '⟨Name⟩'] as $intern) {
            self::assertStringNotContainsString($intern, $inhalt, "$was enthaelt „{$intern}“");
        }
    }

    public function testSeiteZeigtStandUndEintraegeOhneNamen(): void
    {
        $antwort = self::hole($this->link);
        $html = $antwort['inhalt'];

        self::assertSame(200, $antwort['status']);
        self::assertArrayNotHasKey('set-cookie', $antwort['koepfe']);
        self::assertSame('noindex, nofollow', $antwort['koepfe']['x-robots-tag'] ?? null);

        // 50 -1 -1 -5 +3 -1, Bonus 20.09., 27.09., 04.10., 18.10.: 57
        self::assertStringContainsString('data-stand="57"', $html);
        self::assertStringContainsString('Noch 13 Punkte bis zum Ziel', $html);
        // Die Linie in beiden Formaten, mit Ziel und Beschreibung fuer Screenreader.
        self::assertStringContainsString('class="verlauf verlauf--beamer"', $html);
        self::assertStringContainsString('class="verlauf verlauf--handy"', $html);
        self::assertSame(2, substr_count($html, 'class="verlauf__linie"'));
        self::assertStringContainsString('>Ziel 70</text>', $html);
        self::assertStringContainsString('aria-label="Punkteverlauf seit 14.09.2026: Start bei 50, jetzt 57 von 100 Punkten, Ziel 70, Stichtag 30.06.2027."', $html);
        self::assertStringContainsString('Als Tabelle', $html);
        // Etappen bei 18, 35, 53 und 70: drei erreicht, Stufe 3 - je Format einmal.
        self::assertStringContainsString('stimmung--3', $html);
        self::assertStringContainsString('Auf der Zielgeraden!', $html);
        self::assertSame(8, substr_count($html, 'class="etappe'));
        self::assertSame(6, substr_count($html, 'etappe--erreicht'));
        self::assertSame(2, substr_count($html, 'class="etappe etappe--erreicht" data-bei="53"'));
        self::assertSame(2, substr_count($html, 'class="etappe" data-bei="70"'));
        // Kein style-Attribut: Die Content-Security-Policy liesse es nicht zu.
        self::assertStringNotContainsString(' style="', $html);
        self::assertStringNotContainsString('balken', $html);
        self::assertStringContainsString('Unterrichtsstörung', $html);
        self::assertStringContainsString('Gute Mitarbeit', $html);
        self::assertStringContainsString('Großer negativer Eintrag', $html);
        self::assertStringContainsString('Wochenbonus: Woche ohne großen Eintrag', $html);
        self::assertStringContainsString("\u{2212}1 · klein", $html);
        self::assertStringContainsString('+3 · mittel', $html);
        self::assertStringContainsString('Mathematik', $html);
        // Der Server rechnet mit dem echten heutigen Tag.
        $tage = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable('2027-06-30'))->days;
        if (new DateTimeImmutable('today') < new DateTimeImmutable('2027-06-30')) {
            self::assertStringContainsString($tage . ($tage === 1 ? ' Tag' : ' Tage'), $html);
        }
        self::assertStringContainsString('Quelle: WebUntis-Export vom 19.10.2026', $html);

        self::pruefeNamensfrei($html, 'Die Seite');
    }

    public function testKeineFremdenQuellen(): void
    {
        $html = self::hole($this->link)['inhalt'];

        self::assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#i', $html);
        self::assertStringNotContainsString('fonts.googleapis', $html);
        self::assertDoesNotMatchRegularExpression('/\sstyle=/i', $html);
    }

    public function testBeamerAnsicht(): void
    {
        $html = self::hole($this->link . '?ansicht=beamer')['inhalt'];

        // Das Beamer-Stylesheet ohne Medienabfrage, also immer.
        self::assertMatchesRegularExpression('#href="/css/anzeige-beamer\.css\?v=\d+">#', $html);
        self::assertStringContainsString('anzeige--beamer', $html);
        self::assertStringNotContainsString('Weitere laden', $html);
        self::assertSame(5, substr_count($html, '<li class="eintrag '));
        // Das Layout steht fest: nur die breite Linie, keine Tabelle.
        self::assertStringContainsString('class="verlauf verlauf--beamer"', $html);
        self::assertStringNotContainsString('verlauf--handy', $html);
        self::assertStringNotContainsString('Als Tabelle', $html);

        $handy = self::hole($this->link)['inhalt'];
        self::assertMatchesRegularExpression('#anzeige-beamer\.css\?v=\d+" media="#', $handy);
    }

    public function testDatenAlsJson(): void
    {
        $antwort = self::hole($this->link . '/stand.json?anzahl=3');
        $daten = json_decode($antwort['inhalt'], true);

        self::assertSame(200, $antwort['status']);
        self::assertStringStartsWith('application/json', $antwort['koepfe']['content-type'] ?? '');
        self::assertSame('noindex, nofollow', $antwort['koepfe']['x-robots-tag'] ?? null);
        self::assertIsArray($daten);
        self::assertSame(57, $daten['stand']);
        self::assertCount(3, $daten['eintraege']);
        self::assertTrue($daten['weitere']);
        // Der Verlauf: je Woche nur Datum und Stand, vom Start mit 50 bis heute.
        self::assertSame(['datum' => '2026-09-14', 'stand' => 50], $daten['verlauf']['punkte'][0]);
        foreach ($daten['verlauf']['punkte'] as $punkt) {
            self::assertSame(['datum', 'stand'], array_keys($punkt));
        }
        self::assertSame(57, $daten['verlauf']['punkte'][count($daten['verlauf']['punkte']) - 1]['stand']);

        $weiter = json_decode(self::hole($this->link . '/stand.json?anzahl=50&versatz=3')['inhalt'], true);
        self::assertIsArray($weiter);
        self::assertFalse($weiter['weitere']);

        self::pruefeNamensfrei($antwort['inhalt'], 'Das JSON');
        self::pruefeNamensfrei((string) json_encode($weiter, JSON_UNESCAPED_UNICODE), 'Das JSON (weitere)');
    }

    public function testJsonMitFalschemLinkIst404(): void
    {
        self::assertSame(404, self::hole('/klassenziel/' . str_repeat('x', 32) . '/stand.json')['status']);
    }

    public function testMehrEintraegeOhneJavascript(): void
    {
        $html = self::hole($this->link . '?eintraege=2')['inhalt'];

        self::assertSame(2, substr_count($html, '<li class="eintrag '));
        self::assertStringContainsString('href="?eintraege=22"', $html);
    }
}
