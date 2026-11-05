<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Wertung\Kategoriezuordnung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Was die Kategoriegruppe aus WebUntis ueber eine noch nicht zugeordnete
 * Kategorie sagt. Die Kategorienamen stehen so in den Exporten der Schule.
 */
final class KategoriegruppeTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string}> */
    public static function faelle(): iterable
    {
        yield 'positiv ohne Stufe' => ['positiv/Schüler*in', 'positiv', 'positiv', 'klein'];
        yield 'positiver Klasseneintrag' => ['positiver/Klasse', 'positiv', 'positiv', 'klein'];
        yield 'klein negativ' => ['klein/Schüler*in', 'negativ', 'negativ', 'klein'];
        yield 'groß negativ' => ['groß/Klasse', 'negativ', 'negativ', 'gross'];
        yield 'gross ohne ß' => ['Gross/Klasse', 'Negativ', 'negativ', 'gross'];
        yield 'mittel' => ['mittel/Schüler*in', 'positiv', 'positiv', 'mittel'];
        yield 'groß positiv' => ['groß/Lob', 'positiv', 'positiv', 'gross'];
        yield 'neutral' => ['Info/Klasse', 'Inf. / neutral', 'neutral', null];
        yield 'Gruppe ohne Richtung' => ['Sonstiges', 'Allgemein', 'pruefen', null];
        yield 'ohne Gruppe' => ['Sonstiges', '', 'pruefen', null];
        yield 'ohne Kategorie' => ['', 'positiv', 'pruefen', null];
    }

    #[DataProvider('faelle')]
    public function testAusGruppe(string $kategorie, string $gruppe, string $richtung, ?string $stufe): void
    {
        self::assertSame(['richtung' => $richtung, 'stufe' => $stufe], Kategoriezuordnung::ausGruppe($kategorie, $gruppe));
    }
}
