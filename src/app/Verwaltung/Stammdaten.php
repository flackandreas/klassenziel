<?php

declare(strict_types=1);

namespace Klassenziel\Verwaltung;

use DateTimeImmutable;
use Klassenziel\Wertung\Gewichtung;
use PDO;

/**
 * Was fuer die ganze Schule gilt: Ferien, Fachkuerzel, Textbausteine,
 * Vorgaben fuer neue Klassen und die Kategorie-Vorgaben.
 *
 * Jede Aenderung zaehlt vorgaben.version hoch - der Punktestand aller
 * Klassen wird dann beim naechsten Abruf neu berechnet.
 */
final class Stammdaten
{
    public const RICHTUNGEN = ['positiv', 'negativ', 'neutral'];
    public const STUFEN = ['klein', 'mittel', 'gross'];

    public function __construct(private readonly PDO $verbindung)
    {
    }

    // --- Ferien -------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function ferien(): array
    {
        $abfrage = $this->verbindung->query('SELECT * FROM ferien ORDER BY von');

        return $abfrage !== false ? ($abfrage->fetchAll() ?: []) : [];
    }

    /** @return string|null Fehlermeldung */
    public function fuegeFerienHinzu(string $von, string $bis, string $bezeichnung): ?string
    {
        $vonDatum = self::datum($von);
        $bisDatum = self::datum($bis);
        $bezeichnung = trim($bezeichnung);

        if ($vonDatum === null || $bisDatum === null) {
            return 'Bitte Beginn und Ende als Datum angeben.';
        }
        if ($bisDatum < $vonDatum) {
            return 'Das Ende liegt vor dem Beginn.';
        }
        if ($bezeichnung === '' || mb_strlen($bezeichnung) > 100) {
            return 'Bitte eine Bezeichnung angeben, etwa „Herbstferien“.';
        }

        $this->verbindung->prepare('INSERT INTO ferien (von, bis, bezeichnung) VALUES (?, ?, ?)')
            ->execute([$vonDatum->format('Y-m-d'), $bisDatum->format('Y-m-d'), $bezeichnung]);
        $this->neuBerechnen();

        return null;
    }

    public function entferneFerien(int $id): void
    {
        $this->verbindung->prepare('DELETE FROM ferien WHERE id = ?')->execute([$id]);
        $this->neuBerechnen();
    }

    // --- Faecher ------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function faecher(): array
    {
        $abfrage = $this->verbindung->query('SELECT * FROM faecher ORDER BY kuerzel');

        return $abfrage !== false ? ($abfrage->fetchAll() ?: []) : [];
    }

    /** @param array<string,string> $klartexte Kuerzel => Klartext */
    public function setzeFaecher(array $klartexte): void
    {
        $anweisung = $this->verbindung->prepare('UPDATE faecher SET klartext = ? WHERE kuerzel = ?');
        foreach ($klartexte as $kuerzel => $klartext) {
            $klartext = trim($klartext);
            $anweisung->execute([$klartext !== '' ? mb_substr($klartext, 0, 100) : null, (string) $kuerzel]);
        }
        $this->neuBerechnen();
    }

    // --- Textbausteine --------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function textbausteine(?string $richtung = null): array
    {
        if ($richtung !== null) {
            $abfrage = $this->verbindung->prepare('SELECT * FROM textbausteine WHERE richtung = ? ORDER BY sortierung, text');
            $abfrage->execute([$richtung]);

            return $abfrage->fetchAll() ?: [];
        }

        $abfrage = $this->verbindung->query('SELECT * FROM textbausteine ORDER BY richtung, sortierung, text');

        return $abfrage !== false ? ($abfrage->fetchAll() ?: []) : [];
    }

    /** @return string|null Fehlermeldung */
    public function fuegeTextbausteinHinzu(string $richtung, string $text): ?string
    {
        $text = trim($text);
        if (!in_array($richtung, self::RICHTUNGEN, true)) {
            return 'Bitte eine Richtung wählen.';
        }
        if ($text === '' || mb_strlen($text) > 120) {
            return 'Ein Textbaustein hat 1 bis 120 Zeichen.';
        }

        $this->verbindung->prepare(
            'INSERT INTO textbausteine (richtung, text, sortierung)
             SELECT ?, ?, COALESCE(MAX(sortierung), 0) + 10 FROM textbausteine WHERE richtung = ?'
        )->execute([$richtung, $text, $richtung]);

        return null;
    }

    public function entferneTextbaustein(int $id): void
    {
        $this->verbindung->prepare('DELETE FROM textbausteine WHERE id = ?')->execute([$id]);
    }

    // --- Vorgaben -------------------------------------------------------------

    public function vorgaben(): Gewichtung
    {
        $abfrage = $this->verbindung->query('SELECT * FROM vorgaben WHERE id = 1');
        $zeile = $abfrage !== false ? $abfrage->fetch() : false;

        return is_array($zeile) ? Gewichtung::ausZeile($zeile) : Gewichtung::vorgabe();
    }

    /** Gilt fuer Klassen, die danach angelegt werden. */
    public function setzeVorgaben(Gewichtung $gewichtung): void
    {
        $this->verbindung->prepare(
            'UPDATE vorgaben SET punkte_klein = ?, punkte_mittel = ?, punkte_gross = ?, wochenbonus = ? WHERE id = 1'
        )->execute([$gewichtung->klein, $gewichtung->mittel, $gewichtung->gross, $gewichtung->wochenbonus]);
    }

    /** @return list<array<string,mixed>> */
    public function kategorieVorgaben(): array
    {
        $abfrage = $this->verbindung->query('SELECT * FROM kategorie_vorgaben ORDER BY richtung = \'pruefen\' DESC, webuntis_kategorie');

        return $abfrage !== false ? ($abfrage->fetchAll() ?: []) : [];
    }

    /** Gilt fuer alle Klassen, die fuer diese Kategorie keine eigene Zuordnung haben - auch rueckwirkend. */
    public function setzeKategorieVorgabe(string $kategorie, string $richtung, ?string $stufe): void
    {
        if (!in_array($richtung, [...self::RICHTUNGEN, 'pruefen'], true)) {
            return;
        }

        $this->verbindung->prepare('UPDATE kategorie_vorgaben SET richtung = ?, stufe = ? WHERE webuntis_kategorie = ?')
            ->execute([$richtung, in_array($richtung, ['positiv', 'negativ'], true) && in_array($stufe, self::STUFEN, true) ? $stufe : null, $kategorie]);
        $this->neuBerechnen();
    }

    private function neuBerechnen(): void
    {
        $this->verbindung->exec('UPDATE vorgaben SET version = version + 1 WHERE id = 1');
    }

    private static function datum(string $wert): ?DateTimeImmutable
    {
        $datum = DateTimeImmutable::createFromFormat('!Y-m-d', trim($wert));

        return $datum !== false && $datum->format('Y-m-d') === trim($wert) ? $datum : null;
    }
}
