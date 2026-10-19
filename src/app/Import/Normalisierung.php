<?php

declare(strict_types=1);

namespace Klassenziel\Import;

/**
 * Normalisierung der Felder aus dem Export.
 *
 * Dasselbe Feld soll immer dieselbe Zeichenkette ergeben, sonst aendert sich
 * der Fingerabdruck einer Zeile, und der naechste kumulative Export zaehlt sie
 * als neu. Zwei Stolpersteine: Leerzeichen am Rand (WebUntis haengt gern eines
 * an) und Umlaute, die mal als ein Zeichen, mal als Buchstabe mit
 * kombinierendem Zeichen kommen. Unicode-NFC macht daraus eine Form.
 */
final class Normalisierung
{
    /** Fuer Fingerabdruck und Speicherung: getrimmt, NFC. */
    public static function feld(string $wert): string
    {
        $wert = preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $wert) ?? trim($wert);
        $nfc = \Normalizer::normalize($wert, \Normalizer::FORM_C);

        return is_string($nfc) ? $nfc : $wert;
    }

    /**
     * Fuer den Textvergleich der Gruppierung: zusaetzlich klein und mit
     * einfachen Leerzeichen. Nie fuer den Fingerabdruck - dort zaehlt jedes
     * Zeichen.
     */
    public static function vergleich(string $wert): string
    {
        $wert = mb_strtolower(self::feld($wert));

        return preg_replace('/\s+/u', ' ', $wert) ?? $wert;
    }
}
