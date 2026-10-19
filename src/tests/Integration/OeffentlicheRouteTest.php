<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Sicherheit\Zugangslink;
use Klassenziel\Support\Kern;

/**
 * Die oeffentliche Klassenseite ueber echtes HTTP.
 *
 * Kopfzeilen lassen sich in PHP auf der Kommandozeile nicht abfragen; deshalb
 * startet der Test den eingebauten Server von PHP mit dem Front Controller und
 * derselben Testdatenbank.
 */
final class OeffentlicheRouteTest extends Datenbankfall
{
    use Testserver;

    private KlassenRepository $klassen;
    private string $link = '';
    private int $klasseId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::starteServer();

        $this->klassen = new KlassenRepository($this->db(), $this->schluessel());
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
        $this->klasseId = $this->klassen->anlegen($daten, $this->konto());
        $klasse = $this->klassen->nachId($this->klasseId);
        self::assertNotNull($klasse);
        $this->link = (string) $this->klassen->link($klasse);
    }

    public function testGueltigerLinkZeigtDieSeiteOhneCookieUndOhneIndex(): void
    {
        $antwort = self::hole('/klassenziel/' . $this->link);

        self::assertSame(200, $antwort['status']);
        self::assertStringContainsString('Unser Ziel: Kanutour mit Übernachtung', $antwort['inhalt']);
        self::assertSame('noindex, nofollow', $antwort['koepfe']['x-robots-tag'] ?? null);
        self::assertSame('no-referrer', $antwort['koepfe']['referrer-policy'] ?? null);
        self::assertArrayNotHasKey('set-cookie', $antwort['koepfe']);
        self::assertStringNotContainsString('unsafe-inline', $antwort['koepfe']['content-security-policy'] ?? 'fehlt');
    }

    public function testUnbekannterLinkIstDasGleiche404WieJedeAndereAdresse(): void
    {
        $erfunden = self::hole('/klassenziel/' . Zugangslink::erzeugen());
        $kaputt = self::hole('/klassenziel/abc');
        $irgendwas = self::hole('/gibt/es/nicht');

        self::assertSame(404, $erfunden['status']);
        self::assertSame(404, $kaputt['status']);
        self::assertSame(404, $irgendwas['status']);
        self::assertSame(Kern::nichtGefundenHtml(), $erfunden['inhalt']);
        self::assertSame($erfunden['inhalt'], $irgendwas['inhalt']);
        self::assertSame($erfunden['inhalt'], $kaputt['inhalt']);
        self::assertStringNotContainsString('9d', $erfunden['inhalt']);
        self::assertSame('noindex, nofollow', $erfunden['koepfe']['x-robots-tag'] ?? null);
    }

    public function testNachNeuerzeugungGiltDerAlteLinkNicht(): void
    {
        $alt = $this->link;
        $neu = $this->klassen->neuerLink($this->klasseId);

        self::assertSame(404, self::hole('/klassenziel/' . $alt)['status']);
        self::assertSame(200, self::hole('/klassenziel/' . $neu)['status']);
    }

    public function testArchivierteKlasseIst404(): void
    {
        $this->klassen->archivieren($this->klasseId);

        self::assertSame(404, self::hole('/klassenziel/' . $this->link)['status']);
    }

    public function testRatenbremseNachVielenFehlversuchen(): void
    {
        for ($i = 0; $i < 30; $i++) {
            self::assertSame(404, self::hole('/klassenziel/' . Zugangslink::erzeugen())['status']);
        }

        self::assertSame(429, self::hole('/klassenziel/' . Zugangslink::erzeugen())['status']);
        // Gesperrt ist dann auch der richtige Link - sonst liesse sich die
        // Sperre zum Pruefen von Kandidaten benutzen.
        self::assertSame(429, self::hole('/klassenziel/' . $this->link)['status']);
    }

    public function testLehrerbereichVerlangtAnmeldung(): void
    {
        $antwort = self::hole('/');

        self::assertSame(303, $antwort['status']);
        self::assertStringStartsWith('/anmelden', $antwort['koepfe']['location'] ?? '');
    }

    public function testLebenszeichen(): void
    {
        $antwort = self::hole('/healthz');

        self::assertSame(200, $antwort['status']);
        self::assertSame(['status' => 'ok', 'modul' => 'klassenziel'], json_decode($antwort['inhalt'], true));
    }

    public function testInterneDateienSindNichtErreichbar(): void
    {
        foreach (['/../bootstrap.php', '/vendor/autoload.php', '/seiten/anzeige.php', '/migrationen/003_klassen.sql'] as $pfad) {
            self::assertSame(404, self::hole($pfad)['status'], $pfad);
        }
    }
}
