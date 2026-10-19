<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use DateTimeImmutable;
use Klassenziel\Klassen\Klasse;
use PDO;

/**
 * Der Punktestand einer Klasse: Daten laden, rechnen, zwischenspeichern.
 *
 * Der Stand wird nicht gespeichert, sondern bei jedem Abruf aus Vorfaellen,
 * Gewichtung und Wochenboni berechnet - so wirkt eine geaenderte Gewichtung
 * sofort und rueckwirkend. Weil Pi und Mac mini nicht bei jedem Abruf der
 * Klassenseite rechnen sollen, liegt das Ergebnis in storage/cache/stand.
 * Der Schluessel des Zwischenspeichers enthaelt alles, wovon es abhaengt:
 * daten_version der Klasse, den Stand der Schulvorgaben, den heutigen Tag
 * und den Datenstand. Aendert sich eines davon, passt der alte Eintrag nicht
 * mehr - eine eigene Invalidierung braucht es nicht.
 */
final class Standdienst
{
    public function __construct(
        private readonly PDO $verbindung,
        private readonly string $ablage,
    ) {
    }

    public function wertung(Klasse $klasse, DateTimeImmutable $heute): Wertung
    {
        $datei = $this->cacheDatei($klasse, $heute);

        if ($datei !== null && is_readable($datei)) {
            $feld = json_decode((string) @file_get_contents($datei), true);
            if (is_array($feld)) {
                return Wertung::ausFeld($feld);
            }
        }

        $wertung = $this->rechne($klasse, $heute, null, null);

        if ($datei !== null) {
            $this->schreibe($datei, $klasse->id, $wertung);
        }

        return $wertung;
    }

    /**
     * Was waere, wenn: dieselbe Klasse mit anderer Gewichtung und anderer
     * Kategorie-Zuordnung. Nie zwischengespeichert.
     *
     * @param array<string, array{richtung: string, stufe: ?string}>|null $zuordnungen
     */
    public function simuliere(Klasse $klasse, DateTimeImmutable $heute, Gewichtung $gewichtung, ?array $zuordnungen): Wertung
    {
        return $this->rechne($klasse, $heute, $gewichtung, $zuordnungen);
    }

    /**
     * Die zaehlenden Vorfaelle mit ihrer wirksamen Bewertung.
     *
     * @param array<string, array{richtung: string, stufe: ?string}>|null $zuordnungen
     * @return list<array{id: int, datum: string, richtung: string, stufe: string}>
     */
    public function zaehlendeVorfaelle(Klasse $klasse, ?array $zuordnungen = null): array
    {
        $kategorien = new Kategoriezuordnung($this->verbindung);
        if ($zuordnungen !== null) {
            $kategorien = $kategorien->mitKlasse($klasse->id, $zuordnungen);
        }

        $abfrage = $this->verbindung->prepare(
            'SELECT id, klasse_id, datum, webuntis_kategorie, kategoriegruppe, richtung, stufe, manuell_bewertet, status
               FROM vorfaelle WHERE klasse_id = ? ORDER BY datum, id'
        );
        $abfrage->execute([$klasse->id]);

        $ergebnis = [];
        foreach ($abfrage->fetchAll() ?: [] as $zeile) {
            $bewertung = Bewertung::wirksam($zeile, $kategorien);
            if ($bewertung['zaehlt']) {
                $ergebnis[] = [
                    'id' => (int) $zeile['id'],
                    'datum' => (string) $zeile['datum'],
                    'richtung' => $bewertung['richtung'],
                    'stufe' => (string) $bewertung['stufe'],
                ];
            }
        }

        return $ergebnis;
    }

    public function ferien(): Ferienkalender
    {
        $abfrage = $this->verbindung->query('SELECT von, bis FROM ferien ORDER BY von');
        $zeilen = $abfrage !== false ? ($abfrage->fetchAll() ?: []) : [];

        return new Ferienkalender(array_map(
            static fn (array $z): array => ['von' => (string) $z['von'], 'bis' => (string) $z['bis']],
            $zeilen
        ));
    }

    /**
     * @param array<string, array{richtung: string, stufe: ?string}>|null $zuordnungen
     */
    private function rechne(Klasse $klasse, DateTimeImmutable $heute, ?Gewichtung $gewichtung, ?array $zuordnungen): Wertung
    {
        return Punkterechner::rechne(
            Wertungsrahmen::fuer($klasse, $heute),
            $gewichtung ?? (new Einstellungen($this->verbindung))->gewichtung($klasse->id),
            $this->zaehlendeVorfaelle($klasse, $zuordnungen),
            $this->ferien()
        );
    }

    private function cacheDatei(Klasse $klasse, DateTimeImmutable $heute): ?string
    {
        $verzeichnis = $this->ablage . '/cache/stand';
        if (!is_dir($verzeichnis)) {
            @mkdir($verzeichnis, 0o750, true);
        }
        if (!is_writable($verzeichnis)) {
            return null;
        }

        $abfrage = $this->verbindung->query('SELECT version FROM vorgaben WHERE id = 1');
        $schulstand = $abfrage !== false ? (int) $abfrage->fetchColumn() : 0;

        $schluessel = hash('sha256', implode('|', [
            $klasse->id,
            $klasse->datenVersion,
            $schulstand,
            $heute->format('Y-m-d'),
            $klasse->datenstand?->format('Y-m-d H:i:s') ?? '-',
        ]));

        return $verzeichnis . '/' . $klasse->id . '-' . substr($schluessel, 0, 24) . '.json';
    }

    private function schreibe(string $datei, int $klasseId, Wertung $wertung): void
    {
        // Alte Eintraege dieser Klasse weg: es gilt immer nur der neueste.
        foreach (glob(dirname($datei) . '/' . $klasseId . '-*.json') ?: [] as $alt) {
            if ($alt !== $datei) {
                @unlink($alt);
            }
        }

        // Erst eine eigene Datei, dann umbenennen: ein paralleler Abruf liest
        // sonst womoeglich eine halb geschriebene.
        $zwischen = $datei . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($zwischen, (string) json_encode($wertung->alsFeld())) !== false) {
            @rename($zwischen, $datei);
        }
    }
}
