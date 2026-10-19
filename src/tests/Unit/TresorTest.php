<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\Tresor;
use Klassenziel\Sicherheit\TresorFehler;
use PHPUnit\Framework\TestCase;

final class TresorTest extends TestCase
{
    private Tresor $tresor;

    protected function setUp(): void
    {
        $this->tresor = new Tresor(Schluessel::ausWert(base64_encode(str_repeat('k', 32))));
    }

    public function testHinUndZurueck(): void
    {
        $text = 'Max verweigert die Auszeit, die Schulleitung wird geholt. (DEF) – äöüß';
        $verschlossen = $this->tresor->verschliessen($text, 'vorfaelle.originaltext');

        self::assertStringNotContainsString('Max', $verschlossen);
        self::assertStringStartsWith('v1:', $verschlossen);
        self::assertSame($text, $this->tresor->oeffnen($verschlossen, 'vorfaelle.originaltext'));
    }

    public function testJederAufrufErgibtEinenAnderenGeheimtext(): void
    {
        self::assertNotSame(
            $this->tresor->verschliessen('gleich', 'zweck'),
            $this->tresor->verschliessen('gleich', 'zweck')
        );
    }

    public function testFalscherZweckLaesstSichNichtOeffnen(): void
    {
        $verschlossen = $this->tresor->verschliessen('Link', 'klassen.link_token');

        $this->expectException(TresorFehler::class);
        $this->tresor->oeffnen($verschlossen, 'vorfaelle.originaltext');
    }

    public function testVeraenderterGeheimtextFaellt(): void
    {
        $verschlossen = $this->tresor->verschliessen('Text', 'zweck');
        $roh = base64_decode(substr($verschlossen, 3), true);
        self::assertIsString($roh);
        $roh[strlen($roh) - 1] = chr(ord($roh[strlen($roh) - 1]) ^ 1);

        $this->expectException(TresorFehler::class);
        $this->tresor->oeffnen('v1:' . base64_encode($roh), 'zweck');
    }

    public function testAndererSchluesselLaesstSichNichtOeffnen(): void
    {
        $verschlossen = $this->tresor->verschliessen('Text', 'zweck');
        $fremd = new Tresor(Schluessel::ausWert(base64_encode(str_repeat('x', 32))));

        self::assertNull($fremd->oeffnenOderNull($verschlossen, 'zweck'));
    }

    public function testUnsinnLiefertNull(): void
    {
        self::assertNull($this->tresor->oeffnenOderNull(null, 'zweck'));
        self::assertNull($this->tresor->oeffnenOderNull('', 'zweck'));
        self::assertNull($this->tresor->oeffnenOderNull('v1:kurz', 'zweck'));
        self::assertNull($this->tresor->oeffnenOderNull('Klartext', 'zweck'));
    }
}
