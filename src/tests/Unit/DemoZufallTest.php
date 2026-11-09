<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Demo\Zufall;
use PHPUnit\Framework\TestCase;

/**
 * Die Demo-Schule soll jeden Morgen gleich aussehen: derselbe Startwert
 * muss dieselbe Folge ergeben.
 */
final class DemoZufallTest extends TestCase
{
    public function testGleicherStartwertGleicheFolge(): void
    {
        $a = new Zufall('7b|12');
        $b = new Zufall('7b|12');
        for ($i = 0; $i < 50; $i++) {
            self::assertSame($a->zahl(), $b->zahl());
        }
    }

    public function testAndererStartwertAndereFolge(): void
    {
        self::assertNotSame((new Zufall('7b|12'))->zahl(), (new Zufall('7b|13'))->zahl());
    }

    public function testWerteImBereich(): void
    {
        $zufall = new Zufall('8a|3');
        for ($i = 0; $i < 1000; $i++) {
            $zahl = $zufall->zahl();
            self::assertGreaterThanOrEqual(0.0, $zahl);
            self::assertLessThan(1.0, $zahl);
            $index = $zufall->index(7);
            self::assertGreaterThanOrEqual(0, $index);
            self::assertLessThan(7, $index);
        }
    }
}
