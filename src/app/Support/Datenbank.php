<?php

declare(strict_types=1);

namespace Klassenziel\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Eine Verbindung je Anfrage.
 *
 * Wartet beim Start kurz auf die Datenbank: im Compose-Verbund startet das
 * Modul regelmaessig eine Sekunde vor MariaDB.
 */
final class Datenbank
{
    private static ?PDO $verbindung = null;

    public static function verbindung(): PDO
    {
        if (self::$verbindung instanceof PDO) {
            return self::$verbindung;
        }

        return self::$verbindung = self::oeffne(
            (string) umgebung('DB_HOST', 'db'),
            (string) umgebung('DB_PORT', '3306'),
            (string) umgebung('DB_NAME', 'db_klassenziel'),
            (string) umgebung('DB_USER', 'klassenziel'),
            (string) umgebung('DB_PASS', ''),
            (int) umgebung('DB_STARTVERSUCHE', '10'),
        );
    }

    public static function oeffne(
        string $host,
        string $port,
        string $name,
        string $benutzer,
        string $passwort,
        int $versuche = 1,
    ): PDO {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $letzter = null;

        for ($i = 1; $i <= max(1, $versuche); $i++) {
            try {
                $verbindung = new PDO($dsn, $benutzer, $passwort, [
                    // Echte Prepared Statements statt der Emulation im Treiber.
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);

                // Datumswerte der Datenbank in derselben Zeitzone wie PHP.
                // Sonst stuende in angelegt_am die Uhrzeit von Greenwich.
                $verbindung->exec("SET time_zone = '" . (new \DateTimeImmutable())->format('P') . "'");

                return $verbindung;
            } catch (PDOException $e) {
                $letzter = $e;
                if ($i < $versuche) {
                    sleep(1);
                }
            }
        }

        // Die Meldung eines Verbindungsfehlers enthaelt Host und Benutzer, nie
        // Werte aus den Tabellen - sie darf ins Protokoll.
        error_log('Klassenziel: Datenbank nicht erreichbar: ' . $letzter->getMessage());

        throw new RuntimeException('Die Datenbank ist nicht erreichbar.', 0, $letzter);
    }

    /** Nur fuer Tests. */
    public static function setzeVerbindung(?PDO $verbindung): void
    {
        self::$verbindung = $verbindung;
    }
}
