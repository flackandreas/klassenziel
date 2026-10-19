<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlasseExistiert;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\Schluesselpruefung;
use Klassenziel\Sicherheit\Tresor;

final class KlassenTest extends Datenbankfall
{
    private KlassenRepository $klassen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->klassen = new KlassenRepository($this->db(), $this->schluessel());
    }

    private function daten(string $kuerzel = 'R9d'): Klassendaten
    {
        $daten = Klassendaten::ausEingabe([
            'anzeigename' => 'Klasse 9d',
            'export_kuerzel' => $kuerzel,
            'schuljahr' => '2026/27',
            'belohnung' => 'Kanutour mit Übernachtung',
            'startdatum' => '2026-09-14',
            'stichtag' => '2027-06-30',
            'startwert' => '50',
            'zielwert' => '70',
        ]);
        self::assertTrue($daten->istGueltig());

        return $daten;
    }

    public function testAnlegenMitZustaendigkeitLinkUndEinstellungen(): void
    {
        $lehrerin = $this->konto();
        $id = $this->klassen->anlegen($this->daten(), $lehrerin);

        $klasse = $this->klassen->fuer($lehrerin, $id);
        self::assertNotNull($klasse);
        self::assertSame('R9d', $klasse->exportKuerzel);
        self::assertSame(50, $klasse->startwert);
        self::assertTrue($klasse->hatLink);

        self::assertSame([['id' => $lehrerin->id, 'name' => 'Frau Beispiel']], $this->klassen->zustaendige($id));

        $einstellungen = $this->db()->query("SELECT * FROM einstellungen WHERE klasse_id = $id")->fetch();
        self::assertIsArray($einstellungen);
        self::assertSame([1, 3, 5, 3], [
            (int) $einstellungen['punkte_klein'],
            (int) $einstellungen['punkte_mittel'],
            (int) $einstellungen['punkte_gross'],
            (int) $einstellungen['wochenbonus'],
        ]);
    }

    public function testLinkStehtNieImKlartextInDerDatenbank(): void
    {
        $id = $this->klassen->anlegen($this->daten(), $this->konto());
        $klasse = $this->klassen->nachId($id);
        self::assertNotNull($klasse);
        $link = $this->klassen->link($klasse);
        self::assertNotNull($link);

        $zeile = $this->db()->query("SELECT link_token, link_token_kennung FROM klassen WHERE id = $id")->fetch();
        self::assertIsArray($zeile);
        self::assertStringNotContainsString($link, (string) $zeile['link_token']);
        self::assertStringNotContainsString($link, (string) $zeile['link_token_kennung']);
        self::assertSame($link, (new Tresor($this->schluessel()))->oeffnen((string) $zeile['link_token'], 'klassen.link_token'));
    }

    public function testNeuerLinkMachtDenAltenUngueltig(): void
    {
        $id = $this->klassen->anlegen($this->daten(), $this->konto());
        $klasse = $this->klassen->nachId($id);
        self::assertNotNull($klasse);

        $alt = $this->klassen->link($klasse);
        self::assertNotNull($alt);
        self::assertSame($id, $this->klassen->nachLink($alt)?->id);

        $neu = $this->klassen->neuerLink($id);

        self::assertNotSame($alt, $neu);
        self::assertNull($this->klassen->nachLink($alt));
        self::assertSame($id, $this->klassen->nachLink($neu)?->id);
    }

    public function testFremdeKlasseIstUnsichtbar(): void
    {
        $eine = $this->konto('Frau Eins');
        $andere = $this->konto('Herr Zwei');
        $verwaltung = $this->konto('Schulleitung', true);

        $id = $this->klassen->anlegen($this->daten(), $eine);

        self::assertNull($this->klassen->fuer($andere, $id));
        self::assertSame([], $this->klassen->sichtbarFuer($andere));
        self::assertNotNull($this->klassen->fuer($verwaltung, $id));
        self::assertCount(1, $this->klassen->sichtbarFuer($verwaltung));
    }

    public function testDoppeltesKuerzelImSelbenSchuljahr(): void
    {
        $konto = $this->konto();
        $this->klassen->anlegen($this->daten(), $konto);

        $this->expectException(KlasseExistiert::class);
        $this->klassen->anlegen($this->daten(), $konto);
    }

    public function testDieLetzteZustaendigkeitBleibt(): void
    {
        $eine = $this->konto('Frau Eins');
        $andere = $this->konto('Herr Zwei');
        $id = $this->klassen->anlegen($this->daten(), $eine);

        self::assertFalse($this->klassen->entferneZustaendig($id, $eine->id));

        $this->klassen->setzeZustaendig($id, $andere->id);
        self::assertTrue($this->klassen->entferneZustaendig($id, $eine->id));
        self::assertNull($this->klassen->fuer($eine, $id));
        self::assertNotNull($this->klassen->fuer($andere, $id));
    }

    public function testArchivierenLoeschtLinkUndTexte(): void
    {
        $id = $this->klassen->anlegen($this->daten(), $this->konto());
        $klasse = $this->klassen->nachId($id);
        self::assertNotNull($klasse);
        $link = $this->klassen->link($klasse);
        self::assertNotNull($link);

        $tresor = new Tresor($this->schluessel());
        $this->db()->prepare(
            "INSERT INTO vorfaelle (klasse_id, datum, lehrkraft_kennung, originaltext, text_vorlage, namens_kennungen, kurztext, status)
             VALUES (?, '2026-10-01', ?, ?, ?, '[\"abc\"]', 'Unterrichtsstörung', 'freigegeben')"
        )->execute([$id, str_repeat('0', 64), $tresor->verschliessen('Text', 'x'), $tresor->verschliessen('Vorlage', 'y')]);
        $this->db()->prepare('INSERT INTO namensliste (klasse_id, kennung) VALUES (?, ?)')->execute([$id, str_repeat('1', 64)]);

        $this->klassen->archivieren($id);

        self::assertNull($this->klassen->nachLink($link));
        $archiviert = $this->klassen->nachId($id);
        self::assertNotNull($archiviert);
        self::assertTrue($archiviert->istArchiviert());
        self::assertFalse($archiviert->hatLink);

        $vorfall = $this->db()->query("SELECT * FROM vorfaelle WHERE klasse_id = $id")->fetch();
        self::assertIsArray($vorfall);
        self::assertNull($vorfall['originaltext']);
        self::assertNull($vorfall['text_vorlage']);
        self::assertNull($vorfall['namens_kennungen']);
        // Was ohnehin oeffentlich war, bleibt.
        self::assertSame('Unterrichtsstörung', $vorfall['kurztext']);

        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM namensliste WHERE klasse_id = $id")->fetchColumn());
    }

    public function testSchluesselpruefungErkenntEinenNeuenSchluessel(): void
    {
        $pruefung = new Schluesselpruefung($this->db());

        self::assertTrue($pruefung->stimmt($this->schluessel()), 'Beim ersten Mal wird hinterlegt.');
        self::assertTrue($pruefung->stimmt($this->schluessel()));
        self::assertFalse($pruefung->stimmt(Schluessel::ausWert(base64_encode(random_bytes(32)))));
    }

    public function testZweiterMigrationslaufBleibtFolgenlos(): void
    {
        $vermerk = sys_get_temp_dir() . '/klassenziel-test-zweiter-lauf-' . getmypid();
        @unlink($vermerk);

        $ergebnis = (new \Klassenziel\Support\Migrator(null, $vermerk))->einspielen($this->db());

        self::assertSame([], $ergebnis['ausgefuehrt']);
        self::assertSame([], $ergebnis['fehler']);
        self::assertSame(count(\Klassenziel\Support\Migrator::dateien()), $ergebnis['uebersprungen']);
        @unlink($vermerk);
    }
}
