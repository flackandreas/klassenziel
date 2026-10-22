<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

/**
 * Ein Formular, das ohne Sitzung ankommt - etwa nach einem Neustart des
 * Containers oder nach einer Pause am offenen Tab.
 *
 * Frueher leitete Klassenziel still zur Anmeldung, der Browser blockierte den
 * Sprung zum Portal (form-action), und das Formular stand mit der neuen
 * Eingabe da, als waere es gespeichert. Gespeichert war nichts.
 */
final class AbgelaufeneAnmeldungTest extends Datenbankfall
{
    use Testserver;

    private const PORTAL = 'https://portal.beispiel.test';

    /** @return array<string,string> */
    protected static function testumgebung(): array
    {
        return ['PORTAL_ISSUER' => self::PORTAL . '/'] + parent::testumgebung();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::starteServer();
    }

    public function testFormularOhneSitzungFuehrtZurAnmeldungMitHinweis(): void
    {
        $antwort = self::hole('/klassen/1', ['aktion' => 'speichern', 'stichtag' => '2027-07-15']);

        self::assertSame(303, $antwort['status']);
        self::assertSame(
            '/anmelden?weiter=' . rawurlencode('/klassen/1?abgelaufen=1'),
            $antwort['koepfe']['location'] ?? null
        );
    }

    public function testSeitenaufrufOhneSitzungOhneHinweis(): void
    {
        $antwort = self::hole('/klassen/1');

        self::assertSame(303, $antwort['status']);
        self::assertSame('/anmelden?weiter=' . rawurlencode('/klassen/1'), $antwort['koepfe']['location'] ?? null);
    }

    public function testPortalDarfZielEinerFormularketteSein(): void
    {
        $csp = self::hole('/anmelden')['koepfe']['content-security-policy'] ?? '';

        self::assertStringContainsString("form-action 'self' " . self::PORTAL . ';', $csp);
        // Sonst nichts Neues: kein unsafe-inline, keine anderen Herkuenfte.
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringContainsString("script-src 'self';", $csp);
    }
}
