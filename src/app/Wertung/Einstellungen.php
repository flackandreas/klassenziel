<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use PDO;

/**
 * Gewichtung und Kategorie-Zuordnung einer Klasse.
 *
 * Jede Aenderung zaehlt daten_version der Klasse hoch: der Punktestand wird
 * nicht gespeichert, sondern berechnet, und der Zwischenspeicher muss davon
 * erfahren.
 */
final class Einstellungen
{
    public function __construct(private readonly PDO $verbindung)
    {
    }

    public function gewichtung(int $klasseId): Gewichtung
    {
        $abfrage = $this->verbindung->prepare('SELECT * FROM einstellungen WHERE klasse_id = ?');
        $abfrage->execute([$klasseId]);
        $zeile = $abfrage->fetch();

        if (is_array($zeile)) {
            return Gewichtung::ausZeile($zeile);
        }

        $vorgabe = $this->verbindung->query('SELECT * FROM vorgaben WHERE id = 1');
        $zeile = $vorgabe !== false ? $vorgabe->fetch() : false;

        return is_array($zeile) ? Gewichtung::ausZeile($zeile) : Gewichtung::vorgabe();
    }

    public function speichereGewichtung(int $klasseId, Gewichtung $gewichtung): void
    {
        $this->verbindung->prepare(
            'INSERT INTO einstellungen (klasse_id, punkte_klein, punkte_mittel, punkte_gross, wochenbonus)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE punkte_klein = VALUES(punkte_klein), punkte_mittel = VALUES(punkte_mittel),
                                     punkte_gross = VALUES(punkte_gross), wochenbonus = VALUES(wochenbonus)'
        )->execute([$klasseId, $gewichtung->klein, $gewichtung->mittel, $gewichtung->gross, $gewichtung->wochenbonus]);

        $this->neuBerechnen($klasseId);
    }

    /** @return array<string, array{richtung: string, stufe: ?string}> */
    public function zuordnungen(int $klasseId): array
    {
        $abfrage = $this->verbindung->prepare(
            'SELECT webuntis_kategorie, richtung, stufe FROM kategorie_zuordnungen WHERE klasse_id = ?'
        );
        $abfrage->execute([$klasseId]);

        $karte = [];
        foreach ($abfrage->fetchAll() ?: [] as $zeile) {
            $karte[(string) $zeile['webuntis_kategorie']] = [
                'richtung' => (string) $zeile['richtung'],
                'stufe' => $zeile['stufe'] !== null ? (string) $zeile['stufe'] : null,
            ];
        }

        return $karte;
    }

    /**
     * Die Kategorien, die fuer diese Klasse eine Rolle spielen: alle
     * Vorgaben der Schule und alle, die in ihren Vorfaellen vorkommen.
     *
     * @return list<array{kategorie: string, vorgabe_richtung: string, vorgabe_stufe: ?string, vorfaelle: int}>
     */
    public function kategorien(int $klasseId): array
    {
        $abfrage = $this->verbindung->prepare(
            "SELECT k.kategorie, v.richtung AS vorgabe_richtung, v.stufe AS vorgabe_stufe,
                    (SELECT COUNT(*) FROM vorfaelle f WHERE f.klasse_id = ? AND f.webuntis_kategorie = k.kategorie) AS vorfaelle
               FROM (
                    SELECT webuntis_kategorie AS kategorie FROM kategorie_vorgaben
                    UNION
                    SELECT webuntis_kategorie FROM vorfaelle WHERE klasse_id = ? AND webuntis_kategorie <> ''
               ) k
               LEFT JOIN kategorie_vorgaben v ON v.webuntis_kategorie = k.kategorie
              ORDER BY k.kategorie"
        );
        $abfrage->execute([$klasseId, $klasseId]);

        return array_map(static fn (array $z): array => [
            'kategorie' => (string) $z['kategorie'],
            'vorgabe_richtung' => $z['vorgabe_richtung'] !== null ? (string) $z['vorgabe_richtung'] : 'pruefen',
            'vorgabe_stufe' => $z['vorgabe_stufe'] !== null ? (string) $z['vorgabe_stufe'] : null,
            'vorfaelle' => (int) $z['vorfaelle'],
        ], $abfrage->fetchAll() ?: []);
    }

    /**
     * Setzt die Zuordnung einer Kategorie fuer die Klasse. Ohne Richtung gilt
     * wieder die Vorgabe der Schule.
     */
    public function setzeZuordnung(int $klasseId, string $kategorie, ?string $richtung, ?string $stufe): void
    {
        if ($richtung === null) {
            $this->verbindung->prepare('DELETE FROM kategorie_zuordnungen WHERE klasse_id = ? AND webuntis_kategorie = ?')
                ->execute([$klasseId, $kategorie]);
        } else {
            $this->verbindung->prepare(
                'INSERT INTO kategorie_zuordnungen (klasse_id, webuntis_kategorie, richtung, stufe) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE richtung = VALUES(richtung), stufe = VALUES(stufe)'
            )->execute([$klasseId, $kategorie, $richtung, in_array($richtung, ['positiv', 'negativ'], true) ? $stufe : null]);
        }

        $this->neuBerechnen($klasseId);
    }

    private function neuBerechnen(int $klasseId): void
    {
        $this->verbindung->prepare('UPDATE klassen SET daten_version = daten_version + 1 WHERE id = ?')->execute([$klasseId]);
    }
}
