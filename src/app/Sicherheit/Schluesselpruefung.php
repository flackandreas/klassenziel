<?php

declare(strict_types=1);

namespace Klassenziel\Sicherheit;

use PDO;

/**
 * Ist der Schluessel noch derselbe wie bei der Einrichtung?
 *
 * Der WebUntis-Export ist kumulativ. Bereits bekannte Zeilen erkennt der Import
 * an ihrem Fingerabdruck - und der haengt am Schluessel. Mit einem neuen
 * Schluessel saehe jede Zeile neu aus, und der naechste Import zaehlte jeden
 * Vorfall des Schuljahres ein zweites Mal. Das passiert genau dann, wenn nach
 * einer Ruecksicherung die .env fehlt oder ein Schluessel neu erzeugt wurde.
 *
 * Deshalb steht ein Pruefwert in der Datenbank. Passt er nicht, sperrt
 * Klassenziel den Import und sagt warum.
 */
final class Schluesselpruefung
{
    public function __construct(private readonly PDO $verbindung)
    {
    }

    /** Beim ersten Aufruf wird der Pruefwert hinterlegt. */
    public function stimmt(Schluessel $schluessel): bool
    {
        $abfrage = $this->verbindung->query('SELECT pruefwert FROM schluesselpruefung WHERE id = 1');
        $gespeichert = $abfrage !== false ? $abfrage->fetchColumn() : false;

        if ($gespeichert === false || $gespeichert === null) {
            $this->verbindung->prepare('INSERT IGNORE INTO schluesselpruefung (id, pruefwert) VALUES (1, ?)')
                ->execute([$schluessel->pruefwert()]);

            return true;
        }

        return hash_equals((string) $gespeichert, $schluessel->pruefwert());
    }
}
