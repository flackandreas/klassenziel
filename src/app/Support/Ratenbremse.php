<?php

declare(strict_types=1);

namespace Klassenziel\Support;

use PDO;
use PDOException;

/**
 * Datenbankgestuetzte Begrenzung von Zugriffen, wie im Portal.
 *
 * Auf den oeffentlichen Seiten sind es zwei Toepfe je Absenderadresse: alle
 * Abrufe und die Fehlversuche mit unbekanntem Link. Die Grenzen sind bewusst
 * grosszuegig - im Schul-WLAN teilt sich eine ganze Klasse eine Adresse, und
 * dreissig Handys auf einmal sind dort der Normalfall, kein Angriff.
 */
final class Ratenbremse
{
    public function __construct(private readonly PDO $verbindung)
    {
    }

    /** Zaehlt einen Zugriff und meldet, ob die Grenze noch eingehalten ist. */
    public function erlaubt(string $vorgang, string $gegenstand, int $grenze, int $fenster): bool
    {
        $topf = self::topf($vorgang, $gegenstand);
        $fenster = max(1, $fenster);

        try {
            $this->verbindung->prepare("
                INSERT INTO ratenbremse (topf, fenster_start, treffer)
                VALUES (:topf, NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    treffer = IF(fenster_start < NOW() - INTERVAL {$fenster} SECOND, 1, treffer + 1),
                    fenster_start = IF(fenster_start < NOW() - INTERVAL {$fenster} SECOND, NOW(), fenster_start)
            ")->execute([':topf' => $topf]);

            return $this->treffer($topf) <= $grenze;
        } catch (PDOException $e) {
            // Eine kaputte Zaehltabelle darf die Anzeige nicht blockieren.
            error_log('Klassenziel: Ratenbremse nicht pruefbar: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Ist die Grenze schon ausgeschoepft? Zaehlt selbst nicht mit - gedacht
     * fuer Toepfe, die erst nach der Pruefung gefuellt werden, etwa die
     * Fehlversuche: nach dem dreissigsten ist Schluss, nicht erst nach dem
     * einunddreissigsten.
     */
    public function ueberschritten(string $vorgang, string $gegenstand, int $grenze, int $fenster): bool
    {
        $fenster = max(1, $fenster);

        try {
            $abfrage = $this->verbindung->prepare(
                "SELECT treffer FROM ratenbremse
                  WHERE topf = ? AND fenster_start >= NOW() - INTERVAL {$fenster} SECOND"
            );
            $abfrage->execute([self::topf($vorgang, $gegenstand)]);

            return (int) $abfrage->fetchColumn() >= $grenze;
        } catch (PDOException $e) {
            error_log('Klassenziel: Ratenbremse nicht pruefbar: ' . $e->getMessage());

            return false;
        }
    }

    public function raeumeAuf(): void
    {
        try {
            $this->verbindung->exec('DELETE FROM ratenbremse WHERE fenster_start < NOW() - INTERVAL 1 DAY');
        } catch (PDOException $e) {
            error_log('Klassenziel: Ratenbremse aufraeumen fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function treffer(string $topf): int
    {
        $abfrage = $this->verbindung->prepare('SELECT treffer FROM ratenbremse WHERE topf = ?');
        $abfrage->execute([$topf]);

        return (int) $abfrage->fetchColumn();
    }

    /** Die Adresse steht nur als Hash in der Tabelle. */
    private static function topf(string $vorgang, string $gegenstand): string
    {
        return $vorgang . ':' . hash('sha256', $gegenstand);
    }
}
