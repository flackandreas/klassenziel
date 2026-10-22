<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Support\Anfrage;
use Klassenziel\Support\Kern;
use PHPUnit\Framework\TestCase;

final class AnfrageTest extends TestCase
{
    public function testUrsprungEinerAdresse(): void
    {
        self::assertSame('https://portal.rstn.de', Anfrage::ursprung('https://portal.rstn.de'));
        self::assertSame('https://portal.rstn.de', Anfrage::ursprung('https://portal.rstn.de/'));
        self::assertSame('https://portal.rstn.de', Anfrage::ursprung('HTTPS://Portal.RSTN.de/oidc/autorisieren?x=1'));
        self::assertSame('http://schulos.localhost:8090', Anfrage::ursprung('http://schulos.localhost:8090'));
    }

    public function testUrsprungNimmtNurWasInEineKopfzeileDarf(): void
    {
        self::assertNull(Anfrage::ursprung(''));
        self::assertNull(Anfrage::ursprung('portal.rstn.de'));
        self::assertNull(Anfrage::ursprung('javascript:alert(1)'));
        self::assertNull(Anfrage::ursprung('ftp://portal.rstn.de'));
        // Nichts, was die Content-Security-Policy erweitern koennte.
        self::assertNull(Anfrage::ursprung("https://portal.rstn.de; script-src *"));
        self::assertNull(Anfrage::ursprung('https://por tal.rstn.de'));
    }

    public function testHinweisNurNachEinemVerlorenenFormular(): void
    {
        self::assertNull(Kern::hinweisAbgelaufen([]));
        self::assertNull(Kern::hinweisAbgelaufen([Kern::ABGELAUFEN => '0']));
        self::assertNull(Kern::hinweisAbgelaufen([Kern::ABGELAUFEN => ['1']]));
        self::assertStringContainsString('nicht gespeichert', (string) Kern::hinweisAbgelaufen([Kern::ABGELAUFEN => '1']));
    }
}
