<?php

declare(strict_types=1);

namespace Klassenziel\Support;

/**
 * Ordnet einem Pfad das Skript unter seiten/ zu.
 *
 * Feste Wege zuerst, dann Muster mit benannten Teilen. Die Muster sind eng
 * gefasst: eine Kennung ist eine Zahl, ein Link nur aus den Zeichen, die
 * Zugangslink erzeugt. Alles andere ist 404, bevor ein Skript laeuft.
 */
final class Wegweiser
{
    /** @var array<string,string> */
    private const FEST = [
        '/' => 'start.php',
        '/anmelden' => 'anmelden.php',
        '/abmelden' => 'abmelden.php',
        '/klassen/neu' => 'klasse_neu.php',
        '/datenschutz' => 'datenschutz.php',
        '/sso/start' => 'sso_start.php',
        '/sso/rueckweg' => 'sso_rueckweg.php',
        '/sso/abmelden' => 'sso_abmelden.php',
        '/healthz' => 'healthz.php',
    ];

    /** @var array<string,string> */
    private const MUSTER = [
        '#^/klassen/(?<id>[1-9][0-9]{0,8})$#' => 'klasse.php',
        '#^/klassenziel/(?<token>[A-Za-z0-9_-]{1,64})$#' => 'anzeige.php',
    ];

    /**
     * @return array{seite: string, parameter: array<string,string>}|null
     */
    public static function finde(string $pfad): ?array
    {
        $pfad = '/' . trim($pfad, '/');

        if (isset(self::FEST[$pfad])) {
            return ['seite' => self::FEST[$pfad], 'parameter' => []];
        }

        foreach (self::MUSTER as $muster => $seite) {
            if (preg_match($muster, $pfad, $treffer) === 1) {
                $parameter = [];
                foreach ($treffer as $name => $wert) {
                    if (is_string($name)) {
                        $parameter[$name] = $wert;
                    }
                }

                return ['seite' => $seite, 'parameter' => $parameter];
            }
        }

        return null;
    }

    /** @return list<string> alle Skripte, die der Wegweiser kennt */
    public static function seiten(): array
    {
        return array_values(array_unique(array_merge(array_values(self::FEST), array_values(self::MUSTER))));
    }
}
