<?php

declare(strict_types=1);

namespace Klassenziel\Support;

use PDO;
use PDOException;

/**
 * Spielt die SQL-Dateien aus migrationen/ ein.
 *
 * Gleiche Bauart wie im Portal: ein Vermerk haelt fest, welcher Satz zuletzt
 * vollstaendig durchlief, damit der Normalbetrieb keine Datenbankabfrage
 * kostet.
 *
 * Der Vermerk liegt im temporaeren Verzeichnis des Containers, nicht in
 * storage/. Im Unterrichtsmodul lag er in einem eingehaengten Verzeichnis, das
 * sich zwei Container mit verschiedenen Datenbanken teilten - der zweite hielt
 * sich daraufhin fuer fertig und lief gegen ein Schema ohne die neue Tabelle.
 */
final class Migrator
{
    /** @var list<string> Reihenfolge ist bedeutsam. */
    private const DATEIEN = [
        '001_grundlagen.sql',
        '002_konten.sql',
        '003_klassen.sql',
        '004_wertung.sql',
        '005_vorfaelle.sql',
        '006_import.sql',
        '007_stammdatenstand.sql',
    ];

    private readonly string $verzeichnis;
    private readonly string $vermerkDatei;

    public function __construct(?string $verzeichnis = null, ?string $vermerkDatei = null)
    {
        $this->verzeichnis = $verzeichnis ?? dirname(__DIR__, 2) . '/migrationen';
        $this->vermerkDatei = $vermerkDatei ?? self::vorgabeVermerk();
    }

    /** @return list<string> */
    public static function dateien(): array
    {
        return self::DATEIEN;
    }

    public function kennung(): string
    {
        return substr(hash('sha256', implode('|', self::DATEIEN)), 0, 32);
    }

    public function istAktuell(): bool
    {
        return is_file($this->vermerkDatei)
            && trim((string) @file_get_contents($this->vermerkDatei)) === $this->kennung();
    }

    /**
     * @return array{ausgefuehrt: list<string>, uebersprungen: int, fehler: list<string>}
     */
    public function einspielen(PDO $verbindung): array
    {
        $ausgefuehrt = [];
        $uebersprungen = 0;
        $fehler = [];

        $verbindung->exec('CREATE TABLE IF NOT EXISTS migrationsprotokoll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datei VARCHAR(255) NOT NULL UNIQUE,
            zeitpunkt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $erledigt = $verbindung->query('SELECT datei FROM migrationsprotokoll')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $erledigt = array_flip($erledigt);

        $vermerken = $verbindung->prepare('INSERT INTO migrationsprotokoll (datei) VALUES (?)');

        foreach (self::DATEIEN as $datei) {
            if (isset($erledigt[$datei])) {
                $uebersprungen++;
                continue;
            }

            $pfad = $this->verzeichnis . '/' . $datei;
            if (!is_file($pfad)) {
                $fehler[] = $datei . ': Datei fehlt';
                continue;
            }

            $erfolgreich = true;
            foreach (self::anweisungen((string) file_get_contents($pfad)) as $anweisung) {
                try {
                    $verbindung->exec($anweisung);
                } catch (PDOException $e) {
                    $erfolgreich = false;
                    $fehler[] = $datei . ': ' . $e->getMessage();
                    error_log("Klassenziel: Migration $datei fehlgeschlagen: " . $e->getMessage());
                    break;
                }
            }

            if (!$erfolgreich) {
                // Spaetere Dateien bauen auf frueheren auf.
                break;
            }

            $vermerken->execute([$datei]);
            $ausgefuehrt[] = $datei;
        }

        if ($fehler === []) {
            $this->vermerkeAbschluss();
        }

        return ['ausgefuehrt' => $ausgefuehrt, 'uebersprungen' => $uebersprungen, 'fehler' => $fehler];
    }

    /**
     * Zerlegt eine Datei in einzelne Anweisungen. Kommentarzeilen fliegen
     * vorher raus, damit ein Semikolon im Kommentar nichts zerreisst.
     *
     * @return list<string>
     */
    public static function anweisungen(string $sql): array
    {
        $ohneKommentare = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map('trim', explode(';', $ohneKommentare)),
            static fn (string $s): bool => $s !== ''
        ));
    }

    private static function vorgabeVermerk(): string
    {
        $kennung = substr(hash(
            'sha256',
            (string) umgebung('DB_HOST', 'db') . '|' . (string) umgebung('DB_NAME', 'db_klassenziel')
        ), 0, 12);

        return sys_get_temp_dir() . '/klassenziel-migrationen-' . $kennung;
    }

    private function vermerkeAbschluss(): void
    {
        @file_put_contents($this->vermerkDatei, $this->kennung());
    }
}
