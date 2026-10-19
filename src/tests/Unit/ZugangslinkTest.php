<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\Zugangslink;
use PHPUnit\Framework\TestCase;

final class ZugangslinkTest extends TestCase
{
    public function testLinkHatMindestens128BitUndPasstInDieAdresse(): void
    {
        $token = Zugangslink::erzeugen();

        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);

        // 32 Zeichen base64url tragen 6 Bit je Zeichen.
        self::assertGreaterThanOrEqual(128, strlen($token) * 6);
        self::assertSame(24, strlen(base64_decode(strtr($token, '-_', '+/'), true) ?: ''));
    }

    public function testLinksWiederholenSichNicht(): void
    {
        $links = [];
        for ($i = 0; $i < 2000; $i++) {
            $links[] = Zugangslink::erzeugen();
        }

        self::assertCount(2000, array_unique($links));
    }

    public function testFormpruefung(): void
    {
        self::assertTrue(Zugangslink::formGueltig(Zugangslink::erzeugen()));
        self::assertFalse(Zugangslink::formGueltig(''));
        self::assertFalse(Zugangslink::formGueltig('zu-kurz'));
        self::assertFalse(Zugangslink::formGueltig(str_repeat('a', 33)));
        self::assertFalse(Zugangslink::formGueltig(str_repeat('a', 31) . '/'));
        self::assertFalse(Zugangslink::formGueltig(str_repeat('a', 31) . "\n"));
    }

    public function testKennungVerraetDenLinkNicht(): void
    {
        $schluessel = Schluessel::ausWert(base64_encode(str_repeat('s', 32)));
        $token = Zugangslink::erzeugen();
        $kennung = Zugangslink::kennung($schluessel, $token);

        self::assertStringNotContainsString($token, $kennung);
        self::assertSame($kennung, Zugangslink::kennung($schluessel, $token));
        self::assertNotSame($kennung, Zugangslink::kennung($schluessel, Zugangslink::erzeugen()));
    }
}
