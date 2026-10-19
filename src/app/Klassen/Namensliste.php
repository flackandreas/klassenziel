<?php

declare(strict_types=1);

namespace Klassenziel\Klassen;

use Klassenziel\Import\Namensbestandteile;
use Klassenziel\Sicherheit\Schluessel;
use PDO;

/**
 * Die Vornamen einer Klasse fuer die Namenswarnung beim Kurztext - nur als
 * Kennungen.
 *
 * Die Liste laesst sich ersetzen, aber nicht wieder anzeigen. Wer sie
 * pflegen will, gibt sie neu ein. So liegen auch hier keine Schuelernamen
 * in der Datenbank.
 */
final class Namensliste
{
    public function __construct(
        private readonly PDO $verbindung,
        private readonly Schluessel $schluessel,
    ) {
    }

    /**
     * Ersetzt die Liste. Erlaubt ist alles von "eine je Zeile" bis
     * "durch Komma getrennt"; Doppelnamen zaehlen auch einzeln.
     *
     * @return int Anzahl der hinterlegten Kennungen
     */
    public function ersetze(int $klasseId, string $eingabe): int
    {
        $kennungen = [];
        foreach (preg_split('/[\n,;]+/u', $eingabe) ?: [] as $eintrag) {
            foreach (Namensbestandteile::aus($eintrag) as $teil) {
                if (preg_match('/\s/u', $teil) !== 1) {
                    $kennungen[Namensbestandteile::kennung($this->schluessel, $teil)] = true;
                }
            }
        }

        $this->verbindung->beginTransaction();
        try {
            $this->verbindung->prepare('DELETE FROM namensliste WHERE klasse_id = ?')->execute([$klasseId]);
            $einfuegen = $this->verbindung->prepare('INSERT INTO namensliste (klasse_id, kennung) VALUES (?, ?)');
            foreach (array_keys($kennungen) as $kennung) {
                $einfuegen->execute([$klasseId, $kennung]);
            }
            $this->verbindung->commit();
        } catch (\Throwable $e) {
            $this->verbindung->rollBack();
            throw $e;
        }

        return count($kennungen);
    }

    public function anzahl(int $klasseId): int
    {
        $abfrage = $this->verbindung->prepare('SELECT COUNT(*) FROM namensliste WHERE klasse_id = ?');
        $abfrage->execute([$klasseId]);

        return (int) $abfrage->fetchColumn();
    }

    /** @return list<string> */
    public function kennungen(int $klasseId): array
    {
        $abfrage = $this->verbindung->prepare('SELECT kennung FROM namensliste WHERE klasse_id = ?');
        $abfrage->execute([$klasseId]);

        return array_values(array_map('strval', $abfrage->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }
}
