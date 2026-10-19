<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use DateTimeImmutable;
use Klassenziel\Klassen\Klassendaten;
use PHPUnit\Framework\TestCase;

final class KlassendatenTest extends TestCase
{
    /** @return array<string,string> */
    private function gueltig(): array
    {
        return [
            'anzeigename' => 'Klasse 9d',
            'export_kuerzel' => 'R9d',
            'schuljahr' => '2026/27',
            'belohnung' => 'Kanutour mit Übernachtung',
            'startdatum' => '2026-09-14',
            'stichtag' => '2027-06-30',
            'startwert' => '50',
            'zielwert' => '70',
        ];
    }

    public function testGueltigeAngaben(): void
    {
        $daten = Klassendaten::ausEingabe($this->gueltig());

        self::assertTrue($daten->istGueltig(), implode(' ', $daten->fehler));
    }

    public function testStichtagDarfFehlen(): void
    {
        $daten = Klassendaten::ausEingabe(['stichtag' => ''] + $this->gueltig());

        self::assertTrue($daten->istGueltig());
        self::assertNull($daten->stichtagAlsDatum());
    }

    public function testFehlerJeFeld(): void
    {
        $daten = Klassendaten::ausEingabe([
            'anzeigename' => '',
            'export_kuerzel' => 'R9d; DROP',
            'schuljahr' => '2026/28',
            'belohnung' => str_repeat('x', 201),
            'startdatum' => '2026-02-30',
            'stichtag' => '2026-09-01',
            'startwert' => '101',
            'zielwert' => '-1',
        ]);

        self::assertFalse($daten->istGueltig());
        self::assertSame(
            ['anzeigename', 'export_kuerzel', 'schuljahr', 'belohnung', 'startdatum', 'startwert', 'zielwert'],
            array_keys($daten->fehler)
        );
    }

    public function testStichtagNachStart(): void
    {
        $daten = Klassendaten::ausEingabe(['stichtag' => '2026-09-14'] + $this->gueltig());

        self::assertArrayHasKey('stichtag', $daten->fehler);
    }

    public function testZielNull(): void
    {
        $daten = Klassendaten::ausEingabe(['zielwert' => '0'] + $this->gueltig());

        self::assertArrayHasKey('zielwert', $daten->fehler);
    }

    public function testFremdeTypenWerdenLeer(): void
    {
        $daten = Klassendaten::ausEingabe(['anzeigename' => ['x']] + $this->gueltig());

        self::assertArrayHasKey('anzeigename', $daten->fehler);
    }

    public function testSchuljahrBeginntAmErstenAugust(): void
    {
        self::assertSame('2026/27', Klassendaten::schuljahrZu(new DateTimeImmutable('2026-08-01')));
        self::assertSame('2025/26', Klassendaten::schuljahrZu(new DateTimeImmutable('2026-07-31')));
        self::assertSame('2099/00', Klassendaten::schuljahrZu(new DateTimeImmutable('2099-10-19')));
    }
}
