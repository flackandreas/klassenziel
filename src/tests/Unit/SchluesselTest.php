<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\SchluesselFehlt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchluesselTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function untauglicheWerte(): iterable
    {
        yield 'leer' => [''];
        yield 'kein base64' => ['das ist kein schluessel!'];
        yield 'zu kurz' => [base64_encode(random_bytes(16))];
        yield 'mit Leerzeichen' => ['abcd efgh ijkl mnop qrst uvwx yzAB CDEF GHIJ'];
    }

    #[DataProvider('untauglicheWerte')]
    public function testUntauglicherSchluesselWirdAbgewiesen(string $wert): void
    {
        $this->expectException(SchluesselFehlt::class);
        Schluessel::ausWert($wert);
    }

    public function testKennungIstStabilUndHaengtAmZweck(): void
    {
        $schluessel = Schluessel::ausWert(base64_encode(str_repeat('a', 32)));

        self::assertSame($schluessel->kennung('name', 'Max'), $schluessel->kennung('name', 'Max'));
        self::assertNotSame($schluessel->kennung('name', 'Max'), $schluessel->kennung('lehrkraft', 'Max'));
        self::assertNotSame($schluessel->kennung('name', 'Max'), $schluessel->kennung('name', 'max'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $schluessel->kennung('name', 'Max'));
    }

    public function testKennungIstKeinEinfacherHash(): void
    {
        $schluessel = Schluessel::ausWert(base64_encode(str_repeat('a', 32)));

        // Ohne Schluessel liesse sich ein Vorname in Sekunden zurueckrechnen.
        self::assertNotSame(hash('sha256', "name\x1FMax"), $schluessel->kennung('name', 'Max'));
        self::assertNotSame(hash('sha256', 'Max'), $schluessel->kennung('name', 'Max'));
    }

    public function testAndererSchluesselErgibtAndereKennungen(): void
    {
        $eins = Schluessel::ausWert(base64_encode(str_repeat('a', 32)));
        $zwei = Schluessel::ausWert(base64_encode(str_repeat('b', 32)));

        self::assertNotSame($eins->kennung('zeile', 'x'), $zwei->kennung('zeile', 'x'));
        self::assertNotSame($eins->pruefwert(), $zwei->pruefwert());
    }

    public function testTeilschluesselSindGetrennt(): void
    {
        $schluessel = Schluessel::ausWert(base64_encode(str_repeat('a', 32)));

        self::assertSame(32, strlen($schluessel->verschluesselung()));
        self::assertNotSame($schluessel->verschluesselung(), str_repeat('a', 32));
    }

    public function testSchluesselErscheintNichtInEinerAusgabe(): void
    {
        $schluessel = Schluessel::ausWert(base64_encode(str_repeat('geheim!!', 4)));

        self::assertStringNotContainsString('geheim', print_r($schluessel, true));
    }
}
