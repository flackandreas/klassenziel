<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

use Klassenziel\Klassen\Klasse;
use Klassenziel\Support\Anfrage;
use Klassenziel\Support\Kern;

/**
 * Der Weg auf die oeffentliche Klassenseite - fuer die Seite und fuer ihre
 * Daten (stand.json) derselbe.
 *
 * Keine Sitzung, kein Cookie. Wer die Seite oeffnet, hinterlaesst nur einen
 * Zaehler in der Ratenbremse, unter dem Hash seiner Adresse.
 */
final class Zugang
{
    /** Grosszuegig: im Schul-WLAN teilt sich eine ganze Klasse eine Adresse. */
    private const ABRUFE = 600;
    private const ABRUFE_FENSTER = 600;

    /** Wer dreissigmal mit einem unbekannten Link kommt, sucht. */
    private const FEHLVERSUCHE = 30;
    private const FEHLVERSUCHE_FENSTER = 3600;

    /**
     * Setzt die Schutzkoepfe, prueft Ratenbremse und Link und liefert die
     * Klasse - oder beendet die Anfrage mit 429 oder dem gemeinsamen 404.
     */
    public static function klasse(Kern $kern): Klasse
    {
        header('X-Robots-Tag: noindex, nofollow');
        // Der Link soll nicht als Referrer an eine andere Seite gehen - im
        // Zugriffsprotokoll dort stuende er im Klartext.
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        $bremse = $kern->ratenbremse();
        $absender = Anfrage::absender();

        // Die Zaehler sollen nach einem Tag weg sein (so steht es in den
        // Datenschutzhinweisen). Ohne eigenen Zeitplan: gelegentlich hier.
        if (random_int(1, 200) === 1) {
            $bremse->raeumeAuf();
        }

        if ($bremse->ueberschritten('fehlversuch', $absender, self::FEHLVERSUCHE, self::FEHLVERSUCHE_FENSTER)
            || !$bremse->erlaubt('anzeige', $absender, self::ABRUFE, self::ABRUFE_FENSTER)) {
            http_response_code(429);
            header('Retry-After: 600');
            header('Content-Type: text/plain; charset=utf-8');
            echo "Zu viele Anfragen. Bitte in einigen Minuten noch einmal versuchen.\n";
            exit;
        }

        $klasse = $kern->klassen()->nachLink($kern->parameter('token'));

        if ($klasse === null) {
            $bremse->erlaubt('fehlversuch', $absender, self::FEHLVERSUCHE, self::FEHLVERSUCHE_FENSTER);
            Kern::nichtGefunden();
        }

        return $klasse;
    }

    /** Ganzzahl aus der Adresszeile, begrenzt. */
    public static function zahl(string $name, int $vorgabe, int $min, int $max): int
    {
        $wert = $_GET[$name] ?? null;
        if (!is_string($wert) || preg_match('/^\d{1,4}$/', $wert) !== 1) {
            return $vorgabe;
        }

        return max($min, min($max, (int) $wert));
    }
}
