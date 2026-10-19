<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Support\Wegweiser;
use PHPUnit\Framework\TestCase;

final class WegweiserTest extends TestCase
{
    public function testFesteWege(): void
    {
        self::assertSame('start.php', Wegweiser::finde('/')['seite'] ?? null);
        self::assertSame('start.php', Wegweiser::finde('')['seite'] ?? null);
        self::assertSame('klasse_neu.php', Wegweiser::finde('/klassen/neu')['seite'] ?? null);
        self::assertSame('healthz.php', Wegweiser::finde('/healthz/')['seite'] ?? null);
    }

    public function testKlasseNurMitZahl(): void
    {
        self::assertSame(['seite' => 'klasse.php', 'parameter' => ['id' => '12']], Wegweiser::finde('/klassen/12'));
        self::assertNull(Wegweiser::finde('/klassen/0'));
        self::assertNull(Wegweiser::finde('/klassen/12a'));
        self::assertNull(Wegweiser::finde('/klassen/../config'));
        self::assertNull(Wegweiser::finde('/klassen/1234567890'));
    }

    public function testOeffentlicheSeite(): void
    {
        $token = str_repeat('aB3_-', 6) . 'xy';
        self::assertSame(['seite' => 'anzeige.php', 'parameter' => ['token' => $token]], Wegweiser::finde('/klassenziel/' . $token));
        self::assertNull(Wegweiser::finde('/klassenziel/'));
        self::assertNull(Wegweiser::finde('/klassenziel/abc%2F..'));
        self::assertNull(Wegweiser::finde('/klassenziel/' . str_repeat('a', 65)));
    }

    public function testUnbekanntesIstNull(): void
    {
        self::assertNull(Wegweiser::finde('/vendor/autoload.php'));
        self::assertNull(Wegweiser::finde('/seiten/start.php'));
        self::assertNull(Wegweiser::finde('/.env'));
    }

    public function testJedeSeiteGibtEs(): void
    {
        foreach (Wegweiser::seiten() as $seite) {
            self::assertFileExists(dirname(__DIR__, 2) . '/seiten/' . $seite);
        }
    }
}
