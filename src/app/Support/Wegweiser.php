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
        '/import' => 'import.php',
        '/verwaltung' => 'verwaltung.php',
        '/verwaltung/ferien' => 'verwaltung_ferien.php',
        '/verwaltung/faecher' => 'verwaltung_faecher.php',
        '/verwaltung/textbausteine' => 'verwaltung_textbausteine.php',
        '/verwaltung/vorgaben' => 'verwaltung_vorgaben.php',
        '/datenschutz' => 'datenschutz.php',
        '/sso/start' => 'sso_start.php',
        '/sso/rueckweg' => 'sso_rueckweg.php',
        '/sso/abmelden' => 'sso_abmelden.php',
        '/healthz' => 'healthz.php',
    ];

    /** @var array<string,string> */
    private const MUSTER = [
        '#^/klassen/(?<id>[1-9][0-9]{0,8})$#' => 'klasse.php',
        '#^/klassen/(?<id>[1-9][0-9]{0,8})/pruefliste$#' => 'pruefliste.php',
        '#^/klassen/(?<id>[1-9][0-9]{0,8})/gewichtung$#' => 'gewichtung.php',
        '#^/vorfaelle/(?<id>[1-9][0-9]{0,8})$#' => 'vorfall.php',
        '#^/import/(?<id>[1-9][0-9]{0,8})$#' => 'import_vorschau.php',
        '#^/klassenziel/(?<token>[A-Za-z0-9_-]{1,64})$#' => 'anzeige.php',
        '#^/klassenziel/(?<token>[A-Za-z0-9_-]{1,64})/stand\.json$#' => 'anzeige_daten.php',
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
