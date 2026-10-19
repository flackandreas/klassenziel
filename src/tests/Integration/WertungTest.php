<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use DateTimeImmutable;
use Klassenziel\Import\Importdienst;
use Klassenziel\Klassen\Klasse;
use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Klassen\Namensliste;
use Klassenziel\Konten\Konto;
use Klassenziel\Verwaltung\Stammdaten;
use Klassenziel\Vorfaelle\Namenspruefung;
use Klassenziel\Vorfaelle\VorfallRepository;
use Klassenziel\Wertung\Einstellungen;
use Klassenziel\Wertung\Gewichtung;
use Klassenziel\Wertung\Standdienst;

/**
 * Pruefliste, Gewichtung, Wochenbonus und Ferien gegen eine echte Datenbank,
 * mit dem erfundenen Export aus tests/daten.
 *
 * Ausgangslage nach dem Import (heute: Montag, 19.10.2026, Export 13:31 Uhr):
 *   16.09. klein negativ    -1
 *   01.10. klein negativ    -1   (zwei Beteiligte, zaehlt einmal)
 *   05.10. gross negativ    -5   (kein Wochenbonus fuer die Woche 05.-11.10.)
 *   06.10. ohne Kategorie    0   (zaehlt erst nach dem Einstufen)
 *   09.10. klein/Klasse     -1
 *   Wochenbonus 20.09., 27.09., 04.10., 18.10.: 4 x 3 = +12
 *   50 - 1 - 1 - 5 - 1 + 12 = 54
 */
final class WertungTest extends Datenbankfall
{
    private KlassenRepository $klassen;
    private VorfallRepository $vorfaelle;
    private Standdienst $stand;
    private Einstellungen $einstellungen;
    private Konto $lehrerin;
    private int $klasseId;
    private DateTimeImmutable $heute;
    private string $ablage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klassen = new KlassenRepository($this->db(), $this->schluessel());
        $this->vorfaelle = new VorfallRepository($this->db(), $this->schluessel());
        $this->einstellungen = new Einstellungen($this->db());
        $this->ablage = sys_get_temp_dir() . '/klassenziel-test-ablage-' . bin2hex(random_bytes(4));
        $this->stand = new Standdienst($this->db(), $this->ablage);
        $this->lehrerin = $this->konto('Lehrerin Eins');
        $this->heute = new DateTimeImmutable('2026-10-19');

        $this->klasseId = $this->klassen->anlegen(Klassendaten::ausEingabe([
            'anzeigename' => 'Klasse 9d',
            'export_kuerzel' => 'R9d',
            'schuljahr' => '2026/27',
            'belohnung' => 'Kanutour mit Übernachtung',
            'startdatum' => '2026-09-14',
            'stichtag' => '2027-06-30',
            'startwert' => '50',
            'zielwert' => '70',
        ]), $this->lehrerin);

        $import = new Importdienst($this->db(), $this->schluessel(), $this->klassen);
        $jetzt = new DateTimeImmutable('2026-10-19 13:31');
        $id = $import->vorschau(
            (string) file_get_contents(dirname(__DIR__) . '/daten/export_beispiel.tsv'),
            'ClassregEventPerKlasse_20261019_1331.csv',
            $this->lehrerin,
            $jetzt
        );
        $import->bestaetigen($id, $this->lehrerin, $jetzt);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->ablage . '/cache/stand/*') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->ablage . '/cache/stand');
        @rmdir($this->ablage . '/cache');
        @rmdir($this->ablage);

        parent::tearDown();
    }

    private function klasse(): Klasse
    {
        $klasse = $this->klassen->nachId($this->klasseId);
        self::assertNotNull($klasse);

        return $klasse;
    }

    private function stand(): int
    {
        return $this->stand->wertung($this->klasse(), $this->heute)->stand;
    }

    private function vorfallAm(string $datum): int
    {
        $abfrage = $this->db()->prepare(
            "SELECT id FROM vorfaelle WHERE klasse_id = ? AND datum = ? AND NOT (status = 'ignoriert' AND entschieden_von IS NULL) ORDER BY id LIMIT 1"
        );
        $abfrage->execute([$this->klasseId, $datum]);

        return (int) $abfrage->fetchColumn();
    }

    public function testStandNachDemImport(): void
    {
        $wertung = $this->stand->wertung($this->klasse(), $this->heute);

        self::assertSame(54, $wertung->stand);
        self::assertSame(
            ['2026-09-20', '2026-09-27', '2026-10-04', '2026-10-18'],
            array_values(array_column(array_filter($wertung->ereignisse, static fn (array $e): bool => $e['art'] === 'wochenbonus'), 'datum'))
        );
    }

    public function testEinstufenBringtDenVorfallOhneKategorieZurWertung(): void
    {
        $id = $this->vorfallAm('2026-10-06');
        $this->vorfaelle->entscheide($this->klasseId, $id, 'freigegeben', 'positiv', 'mittel', true, 'Gute Mitarbeit', $this->lehrerin);

        self::assertSame(57, $this->stand());

        $vorfall = $this->vorfaelle->eines($id);
        self::assertSame('freigegeben', $vorfall['status'] ?? null);
        self::assertSame('Gute Mitarbeit', $vorfall['kurztext'] ?? null);
        self::assertSame(1, (int) ($vorfall['manuell_bewertet'] ?? 0));
    }

    public function testIgnorierenUndOhneText(): void
    {
        $this->vorfaelle->entscheide($this->klasseId, $this->vorfallAm('2026-09-16'), 'ignoriert', null, null, false, null, $this->lehrerin);
        self::assertSame(55, $this->stand());

        // Ohne Text: zaehlt, aber ein mitgeschickter Kurztext wird verworfen.
        $id = $this->vorfallAm('2026-10-01');
        $this->vorfaelle->entscheide($this->klasseId, $id, 'ohne_text', 'negativ', 'klein', false, 'soll nicht stehen', $this->lehrerin);
        self::assertSame(55, $this->stand());
        $vorfall = $this->vorfaelle->eines($id);
        self::assertIsArray($vorfall);
        self::assertNull($vorfall['kurztext']);
    }

    public function testGeaenderteGewichtungWirktSofortUndRueckwirkend(): void
    {
        self::assertSame(54, $this->stand(), 'Der Wert liegt jetzt im Zwischenspeicher.');

        $this->einstellungen->speichereGewichtung($this->klasseId, new Gewichtung(2, 3, 8, 0));

        // 50 - 2 - 2 - 8 - 2, ohne Bonus. Der Zwischenspeicher darf den alten
        // Stand nicht mehr liefern.
        self::assertSame(36, $this->stand());
    }

    public function testFolgtDerKategorieAuchNachEinerAenderung(): void
    {
        // Lehrerin bestaetigt die Kategorie, ohne abzuweichen: der Vorfall
        // bleibt an die Zuordnung gebunden.
        $id = $this->vorfallAm('2026-10-09');
        $this->vorfaelle->entscheide($this->klasseId, $id, 'ohne_text', 'negativ', 'klein', false, null, $this->lehrerin);

        $this->einstellungen->setzeZuordnung($this->klasseId, 'klein/Klasse', 'neutral', null);

        self::assertSame(55, $this->stand(), 'Der Klasseneintrag zaehlt jetzt nicht mehr.');
    }

    public function testKategorieVorgabeDerSchuleWirktUeberDenZwischenspeicher(): void
    {
        self::assertSame(54, $this->stand());

        (new Stammdaten($this->db()))->setzeKategorieVorgabe('klein/Schüler*in', 'negativ', 'mittel');

        // 16.09. und 01.10. kosten jetzt je 3 statt 1.
        self::assertSame(50, $this->stand());
    }

    public function testFerienwocheKostetDenBonus(): void
    {
        self::assertSame(54, $this->stand());

        $fehler = (new Stammdaten($this->db()))->fuegeFerienHinzu('2026-10-12', '2026-10-16', 'Testferien');
        self::assertNull($fehler);

        self::assertSame(51, $this->stand(), 'Die Woche 12.-18.10. ist eine Ferienwoche.');
    }

    public function testSimulationSpeichertNichts(): void
    {
        $simuliert = $this->stand->simuliere($this->klasse(), $this->heute, new Gewichtung(1, 2, 3, 5), [
            'groß/Schüler*in' => ['richtung' => 'negativ', 'stufe' => 'mittel'],
        ]);

        // 50 - 1 - 1 - 2 - 1 + 5 Wochen x 5 (keine Woche mehr mit grossem Vorfall)
        self::assertSame(70, $simuliert->stand);
        self::assertSame(54, $this->stand());
        self::assertSame([1, 3, 5, 3], (function (): array {
            $g = $this->einstellungen->gewichtung($this->klasseId);

            return [$g->klein, $g->mittel, $g->gross, $g->wochenbonus];
        })());
        self::assertSame([], $this->einstellungen->zuordnungen($this->klasseId));
    }

    public function testTeilenUndZusammenfuehren(): void
    {
        $id = $this->vorfallAm('2026-10-01');
        self::assertSame(2, (int) ($this->vorfaelle->eines($id)['zeilen'] ?? 0));

        $neu = $this->vorfaelle->teile($this->klasseId, $id, 1);

        self::assertSame(1, (int) ($this->vorfaelle->eines($id)['anzahl_beteiligte'] ?? 0));
        self::assertSame(1, (int) ($this->vorfaelle->eines($neu)['anzahl_beteiligte'] ?? 0));
        self::assertSame(1, (int) ($this->vorfaelle->eines($neu)['zeilen'] ?? 0));
        self::assertSame(53, $this->stand(), 'Zwei Vorfaelle zaehlen zweimal.');

        $this->vorfaelle->fuehreZusammen($this->klasseId, $neu, $id);

        self::assertNull($this->vorfaelle->eines($neu));
        self::assertSame(2, (int) ($this->vorfaelle->eines($id)['anzahl_beteiligte'] ?? 0));
        self::assertSame(2, (int) ($this->vorfaelle->eines($id)['zeilen'] ?? 0));
        self::assertSame(54, $this->stand());
    }

    public function testTeilenBrauchtMindestensZweiZeilen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vorfaelle->teile($this->klasseId, $this->vorfallAm('2026-09-16'), 1);
    }

    public function testZusammenfuehrenNurInDerselbenKlasse(): void
    {
        $andere = $this->klassen->anlegen(Klassendaten::ausEingabe([
            'anzeigename' => 'Klasse 9e', 'export_kuerzel' => 'R9e', 'schuljahr' => '2026/27',
            'belohnung' => 'Zoo', 'startdatum' => '2026-09-14', 'stichtag' => '', 'startwert' => '50', 'zielwert' => '70',
        ]), $this->lehrerin);

        $this->expectException(\InvalidArgumentException::class);
        $this->vorfaelle->fuehreZusammen($andere, $this->vorfallAm('2026-09-16'), $this->vorfallAm('2026-10-01'));
    }

    public function testNamenswarnung(): void
    {
        $vorfall = $this->vorfaelle->eines($this->vorfallAm('2026-10-01'));
        self::assertNotNull($vorfall);
        $kennungen = $this->vorfaelle->namensKennungen($vorfall);

        self::assertSame(['Ben'], Namenspruefung::treffer($this->schluessel(), 'Ben verlässt das Gelände', $kennungen));
        self::assertSame(['tina', 'Beispiel'], Namenspruefung::treffer($this->schluessel(), 'tina und Beispiel-Kind', $kennungen));
        self::assertSame([], Namenspruefung::treffer($this->schluessel(), 'Pausenregel nicht beachtet', $kennungen));
        self::assertSame([], Namenspruefung::treffer($this->schluessel(), 'Max stört', $kennungen), 'Max gehoert zu einem anderen Vorfall.');

        // Die Namensliste der Klasse faengt, was nicht in der Spalte Name steht.
        $liste = new Namensliste($this->db(), $this->schluessel());
        // Anna, Lena-Marie, Lena, Marie
        self::assertSame(4, $liste->ersetze($this->klasseId, "Anna\nLena-Marie"));
        self::assertSame(['Lena'], Namenspruefung::treffer($this->schluessel(), 'Lena hilft', $liste->kennungen($this->klasseId)));

        // Und im Klartext steht kein Name in der Liste.
        $roh = implode(' ', $liste->kennungen($this->klasseId));
        self::assertStringNotContainsString('Anna', $roh);
        self::assertMatchesRegularExpression('/^[0-9a-f ]+$/', $roh);
    }

    public function testZuordnungPruefenZaehltErstNachDerEntscheidung(): void
    {
        $id = $this->vorfallAm('2026-09-16');
        $this->db()->exec("UPDATE vorfaelle SET status = 'zuordnung_pruefen' WHERE id = $id");
        $this->db()->exec("UPDATE klassen SET daten_version = daten_version + 1 WHERE id = {$this->klasseId}");
        self::assertSame(55, $this->stand());

        $this->vorfaelle->alsEigenerVorfall($this->klasseId, $id);
        self::assertSame(54, $this->stand());
    }

    public function testOffeneUndNaechster(): void
    {
        // 5 offene: 16.09., 01.10., 05.10., 06.10., 09.10. - der neutrale
        // Info-Eintrag ist von der Regel ignoriert und zaehlt nicht dazu.
        self::assertSame(5, $this->vorfaelle->offeneAnzahl($this->klasseId));
        self::assertCount(5, $this->vorfaelle->fuerKlasse($this->klasseId));
        self::assertCount(5, $this->vorfaelle->fuerKlasse($this->klasseId, 'alle'));

        $erster = $this->vorfallAm('2026-09-16');
        self::assertSame($this->vorfallAm('2026-10-01'), $this->vorfaelle->naechsterOffener($this->klasseId, $erster));
    }
}
