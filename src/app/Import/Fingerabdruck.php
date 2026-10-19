<?php

declare(strict_types=1);

namespace Klassenziel\Import;

use Klassenziel\Sicherheit\Schluessel;

/**
 * Fingerabdruck einer Exportzeile: HMAC ueber Klasse, Datum, Fach, Name,
 * Benutzer, Kategorie und Text, nach der Normalisierung.
 *
 * Der Export ist kumulativ - jeder enthaelt alle Eintraege seit
 * Schuljahresbeginn. Eine Zeile, deren Fingerabdruck schon bekannt ist, wird
 * uebersprungen; so laesst sich derselbe Export beliebig oft einlesen.
 *
 * Aendert eine Lehrkraft in WebUntis nachtraeglich den Text, ist das eine neue
 * Zeile. Der Gruppierer ordnet sie dann meist dem bisherigen Vorfall zu.
 */
final class Fingerabdruck
{
    private const ZWECK = 'zeile';

    public static function von(Schluessel $schluessel, Exportzeile $zeile): string
    {
        return $schluessel->kennung(self::ZWECK, implode("\x1F", [
            $zeile->klasse,
            $zeile->datum->format('Y-m-d'),
            $zeile->fach,
            $zeile->name,
            $zeile->benutzer,
            $zeile->kategorie,
            $zeile->text,
        ]));
    }

    /** Kennung der eintragenden Lehrkraft, nur fuer die Gruppierung. */
    public static function lehrkraft(Schluessel $schluessel, Exportzeile $zeile): string
    {
        return $schluessel->kennung('lehrkraft', mb_strtolower($zeile->benutzer));
    }
}
