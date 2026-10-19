<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use PDO;

/**
 * Welche Richtung und Stufe hat eine WebUntis-Kategorie?
 *
 * Reihenfolge: die Zuordnung der Klasse, sonst die Vorgabe der Schule, sonst
 * "pruefen" - der Vorfall landet in der Pruefliste. Unabhaengig davon gilt:
 * Eintraege der Kategoriegruppe "Inf. / neutral" sind neutral. Sie zaehlen
 * nie und erscheinen nie oeffentlich (Planung, Abschnitt 5.5).
 */
final class Kategoriezuordnung
{
    /** @var array<int, array<string, array{richtung: string, stufe: ?string}>> */
    private array $klassen = [];

    /** @var array<string, array{richtung: string, stufe: ?string}>|null */
    private ?array $vorgaben = null;

    public function __construct(private readonly PDO $verbindung)
    {
    }

    /**
     * @return array{richtung: 'positiv'|'negativ'|'neutral'|'pruefen', stufe: ?string}
     */
    public function fuer(int $klasseId, string $kategorie, string $kategoriegruppe): array
    {
        if (self::istNeutraleGruppe($kategoriegruppe)) {
            return ['richtung' => 'neutral', 'stufe' => null];
        }

        if ($kategorie === '') {
            return ['richtung' => 'pruefen', 'stufe' => null];
        }

        $eintrag = $this->klasse($klasseId)[$kategorie] ?? $this->vorgaben()[$kategorie] ?? null;
        if ($eintrag === null) {
            return ['richtung' => 'pruefen', 'stufe' => null];
        }

        /** @var 'positiv'|'negativ'|'neutral'|'pruefen' $richtung */
        $richtung = in_array($eintrag['richtung'], ['positiv', 'negativ', 'neutral', 'pruefen'], true)
            ? $eintrag['richtung']
            : 'pruefen';

        // Positiv und negativ brauchen eine Stufe, sonst ist nichts entschieden.
        if (in_array($richtung, ['positiv', 'negativ'], true) && $eintrag['stufe'] === null) {
            return ['richtung' => 'pruefen', 'stufe' => null];
        }

        return ['richtung' => $richtung, 'stufe' => $richtung === 'neutral' ? null : $eintrag['stufe']];
    }

    public static function istNeutraleGruppe(string $kategoriegruppe): bool
    {
        return str_contains(mb_strtolower($kategoriegruppe), 'neutral');
    }

    /** @return array<string, array{richtung: string, stufe: ?string}> */
    private function klasse(int $klasseId): array
    {
        if (!isset($this->klassen[$klasseId])) {
            $abfrage = $this->verbindung->prepare(
                'SELECT webuntis_kategorie, richtung, stufe FROM kategorie_zuordnungen WHERE klasse_id = ?'
            );
            $abfrage->execute([$klasseId]);
            $this->klassen[$klasseId] = self::alsKarte($abfrage->fetchAll() ?: []);
        }

        return $this->klassen[$klasseId];
    }

    /** @return array<string, array{richtung: string, stufe: ?string}> */
    private function vorgaben(): array
    {
        if ($this->vorgaben === null) {
            $abfrage = $this->verbindung->query('SELECT webuntis_kategorie, richtung, stufe FROM kategorie_vorgaben');
            $this->vorgaben = self::alsKarte($abfrage !== false ? ($abfrage->fetchAll() ?: []) : []);
        }

        return $this->vorgaben;
    }

    /**
     * @param list<array<string,mixed>> $zeilen
     * @return array<string, array{richtung: string, stufe: ?string}>
     */
    private static function alsKarte(array $zeilen): array
    {
        $karte = [];
        foreach ($zeilen as $zeile) {
            $karte[(string) $zeile['webuntis_kategorie']] = [
                'richtung' => (string) $zeile['richtung'],
                'stufe' => $zeile['stufe'] !== null ? (string) $zeile['stufe'] : null,
            ];
        }

        return $karte;
    }
}
