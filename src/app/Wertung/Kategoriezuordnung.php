<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use PDO;

/**
 * Welche Richtung und Stufe hat eine WebUntis-Kategorie?
 *
 * Reihenfolge: die Zuordnung der Klasse, sonst die Vorgabe der Schule. Fuer
 * eine Kategorie, die noch gar keine Vorgabe hat, gilt, was ihre
 * Kategoriegruppe sagt (ausGruppe); der Import traegt genau das als Vorgabe
 * ein. Sonst "pruefen" - der Vorfall landet in der Pruefliste. Unabhaengig
 * davon gilt: Eintraege der Kategoriegruppe "Inf. / neutral" sind neutral. Sie
 * zaehlen nie und erscheinen nie oeffentlich (Planung, Abschnitt 5.5).
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
            return self::ausGruppe($kategorie, $kategoriegruppe);
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

    /**
     * Fuer die Simulation: dieselbe Zuordnung, aber mit den Werten aus dem
     * Formular statt der gespeicherten fuer diese Klasse.
     *
     * @param array<string, array{richtung: string, stufe: ?string}> $zuordnungen
     */
    public function mitKlasse(int $klasseId, array $zuordnungen): self
    {
        $kopie = clone $this;
        $kopie->klassen[$klasseId] = $zuordnungen;

        return $kopie;
    }

    public static function istNeutraleGruppe(string $kategoriegruppe): bool
    {
        return str_contains(mb_strtolower($kategoriegruppe), 'neutral');
    }

    /** Gibt es fuer diese Kategorie schon eine Vorgabe der Schule? */
    public function hatVorgabe(string $kategorie): bool
    {
        return isset($this->vorgaben()[$kategorie]);
    }

    /**
     * Was die Kategoriegruppe aus WebUntis ueber eine Kategorie sagt, die
     * noch niemand zugeordnet hat.
     *
     * Die Gruppe "positiv" oder "negativ" gibt die Richtung, der Teil vor dem
     * Schraegstrich die Stufe: "groß/Klasse" ist gross, "klein/Schüler*in"
     * klein. Fehlt die Stufe ("positiv/Schüler*in"), ist es klein - der
     * kleinste Ausschlag, bis die Verwaltung es anders einstellt. Ohne Gruppe
     * und ohne Kategorie bleibt es bei "pruefen".
     *
     * @return array{richtung: 'positiv'|'negativ'|'neutral'|'pruefen', stufe: ?string}
     */
    public static function ausGruppe(string $kategorie, string $kategoriegruppe): array
    {
        if (self::istNeutraleGruppe($kategoriegruppe)) {
            return ['richtung' => 'neutral', 'stufe' => null];
        }

        $gruppe = mb_strtolower($kategoriegruppe);
        $richtung = match (true) {
            str_contains($gruppe, 'positiv') => 'positiv',
            str_contains($gruppe, 'negativ') => 'negativ',
            default => null,
        };

        if ($richtung === null || $kategorie === '') {
            return ['richtung' => 'pruefen', 'stufe' => null];
        }

        $stufe = match (mb_strtolower(trim(explode('/', $kategorie)[0]))) {
            'groß', 'gross' => 'gross',
            'mittel' => 'mittel',
            default => 'klein',
        };

        return ['richtung' => $richtung, 'stufe' => $stufe];
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
