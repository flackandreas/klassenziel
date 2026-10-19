<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFunction;

/**
 * Uebersetzt alle Vorlagen, ohne sie auszufuehren. Faengt Syntaxfehler, die
 * sonst erst im Browser auffallen - und prueft die Regel, an der die
 * Content-Security-Policy haengt: keine style- und keine on*-Attribute.
 */
final class VorlagenTest extends TestCase
{
    /** @return list<string> */
    private static function vorlagen(): array
    {
        $verzeichnis = dirname(__DIR__, 2) . '/templates';
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($verzeichnis, \FilesystemIterator::SKIP_DOTS));

        $namen = [];
        foreach ($dateien as $datei) {
            if ($datei instanceof \SplFileInfo && $datei->getExtension() === 'twig') {
                $namen[] = ltrim(substr((string) $datei->getPathname(), strlen($verzeichnis)), '/');
            }
        }
        sort($namen);

        return $namen;
    }

    public function testAlleVorlagenLassenSichUebersetzen(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false]);
        // Die zur Laufzeit registrierten Erweiterungen (Kern::twig) als Attrappen.
        $twig->addFunction(new TwigFunction('datei', static fn (string $p): string => $p));
        $twig->addFunction(new TwigFunction('bewertung_text', static fn (string $r, ?string $s): string => ''));
        $twig->addFunction(new TwigFunction('stufenname', static fn (?string $s): string => ''));
        $twig->addFilter(new \Twig\TwigFilter('punkte', static fn (int $p): string => (string) $p));

        $vorlagen = self::vorlagen();
        self::assertNotEmpty($vorlagen);

        foreach ($vorlagen as $name) {
            $quelle = $twig->getLoader()->getSourceContext($name);
            $twig->compileSource(new Source($quelle->getCode(), $name));
        }
    }

    public function testKeineInlineStileUndKeineInlineSkripte(): void
    {
        foreach (self::vorlagen() as $name) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $name);

            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $code, $name);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $code, $name);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $code, $name);
            self::assertDoesNotMatchRegularExpression('/<style\b/i', $code, $name);
        }
    }

    public function testKeineFremdenQuellen(): void
    {
        foreach (self::vorlagen() as $name) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $name);

            self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#i', $code, $name);
        }
    }
}
