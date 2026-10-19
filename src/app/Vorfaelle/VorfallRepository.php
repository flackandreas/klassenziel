<?php

declare(strict_types=1);

namespace Klassenziel\Vorfaelle;

use Klassenziel\Import\Importdienst;
use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\Tresor;
use PDO;

/**
 * Vorfaelle einer Klasse: lesen, entscheiden, teilen, zusammenfuehren.
 *
 * Wer eine Klasse sehen darf, prueft der Aufrufer ueber KlassenRepository;
 * hier geht jede Aenderung zusaetzlich nur innerhalb der angegebenen Klasse.
 * Jede Aenderung zaehlt daten_version der Klasse hoch.
 */
final class VorfallRepository
{
    public const KURZTEXT_LAENGE = 160;
    public const STATUS_ENTSCHEIDUNG = ['freigegeben', 'ohne_text', 'ignoriert'];

    private readonly Tresor $tresor;

    public function __construct(
        private readonly PDO $verbindung,
        Schluessel $schluessel,
    ) {
        $this->tresor = new Tresor($schluessel);
    }

    /**
     * @param 'offen'|'alle' $auswahl offen: was in die Pruefliste gehoert
     * @return list<array<string,mixed>>
     */
    public function fuerKlasse(int $klasseId, string $auswahl = 'offen'): array
    {
        $filter = $auswahl === 'offen'
            ? "AND v.status IN ('offen', 'zuordnung_pruefen')"
            // Die von der Regel ignorierten neutralen Eintraege bleiben auch
            // in "alle" weg - sie haben weder Text noch Wertung.
            : "AND NOT (v.status = 'ignoriert' AND v.entschieden_von IS NULL)";

        $abfrage = $this->verbindung->prepare(
            "SELECT v.*, f.klartext AS fach_klartext
               FROM vorfaelle v LEFT JOIN faecher f ON f.kuerzel = v.fach_kuerzel
              WHERE v.klasse_id = ? $filter
              ORDER BY v.datum, v.id"
        );
        $abfrage->execute([$klasseId]);

        return $abfrage->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function eines(int $id): ?array
    {
        $abfrage = $this->verbindung->prepare(
            'SELECT v.*, f.klartext AS fach_klartext,
                    (SELECT COUNT(*) FROM importzeilen z WHERE z.vorfall_id = v.id) AS zeilen
               FROM vorfaelle v LEFT JOIN faecher f ON f.kuerzel = v.fach_kuerzel
              WHERE v.id = ?'
        );
        $abfrage->execute([$id]);
        $zeile = $abfrage->fetch();

        return is_array($zeile) ? $zeile : null;
    }

    /**
     * Der Originaltext - nur fuer die Lehrkraft, nur zur Anzeige.
     *
     * @param array<string,mixed> $vorfall
     */
    public function originaltext(array $vorfall): ?string
    {
        return $this->tresor->oeffnenOderNull(
            $vorfall['originaltext'] !== null ? (string) $vorfall['originaltext'] : null,
            Importdienst::ZWECK_ORIGINAL
        );
    }

    /**
     * @param array<string,mixed> $vorfall
     * @return list<string>
     */
    public function namensKennungen(array $vorfall): array
    {
        $liste = is_string($vorfall['namens_kennungen'] ?? null) ? json_decode($vorfall['namens_kennungen'], true) : [];

        return is_array($liste) ? array_values(array_filter($liste, 'is_string')) : [];
    }

    public function offeneAnzahl(int $klasseId): int
    {
        $abfrage = $this->verbindung->prepare(
            "SELECT COUNT(*) FROM vorfaelle WHERE klasse_id = ? AND status IN ('offen', 'zuordnung_pruefen')"
        );
        $abfrage->execute([$klasseId]);

        return (int) $abfrage->fetchColumn();
    }

    /** Der naechste offene Vorfall nach diesem, fuer "Speichern und weiter". */
    public function naechsterOffener(int $klasseId, int $nachId): ?int
    {
        $abfrage = $this->verbindung->prepare(
            "SELECT id FROM vorfaelle
              WHERE klasse_id = ? AND status IN ('offen', 'zuordnung_pruefen') AND id <> ?
              ORDER BY datum, id LIMIT 1"
        );
        $abfrage->execute([$klasseId, $nachId]);
        $id = $abfrage->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Entscheidet einen Vorfall - oder aendert eine fruehere Entscheidung.
     *
     * @param 'freigegeben'|'ohne_text'|'ignoriert' $status
     * @param bool $manuell true, wenn die Einstufung von der Kategorie abweicht
     *                      oder es keine gab. Sonst folgt der Vorfall weiter der
     *                      Kategorie-Zuordnung, auch einer spaeter geaenderten.
     */
    public function entscheide(
        int $klasseId,
        int $id,
        string $status,
        ?string $richtung,
        ?string $stufe,
        bool $manuell,
        ?string $kurztext,
        Konto $konto,
    ): void {
        $this->verbindung->prepare(
            'UPDATE vorfaelle
                SET status = ?, richtung = ?, stufe = ?, manuell_bewertet = ?, kurztext = ?,
                    moeglich_zu = NULL, entschieden_am = NOW(), entschieden_von = ?
              WHERE id = ? AND klasse_id = ?'
        )->execute([
            $status,
            $manuell ? $richtung : null,
            $manuell && in_array($richtung, ['positiv', 'negativ'], true) ? $stufe : null,
            $manuell ? 1 : 0,
            $status === 'freigegeben' && $kurztext !== null && $kurztext !== ''
                ? mb_substr($kurztext, 0, self::KURZTEXT_LAENGE)
                : null,
            $konto->id,
            $id,
            $klasseId,
        ]);

        $this->neuBerechnen($klasseId);
    }

    /**
     * "Zuordnung pruefen": doch ein eigener Vorfall. Er zaehlt ab jetzt wie
     * jeder offene.
     */
    public function alsEigenerVorfall(int $klasseId, int $id): void
    {
        $this->verbindung->prepare(
            "UPDATE vorfaelle SET status = 'offen', moeglich_zu = NULL
              WHERE id = ? AND klasse_id = ? AND status = 'zuordnung_pruefen'"
        )->execute([$id, $klasseId]);

        $this->neuBerechnen($klasseId);
    }

    /**
     * Trennt Zeilen in einen eigenen Vorfall ab, wenn die Gruppierung zwei
     * Vorfaelle zusammengelegt hat.
     *
     * Die Zeilen sind namenlos und untereinander nicht zu unterscheiden -
     * abgetrennt wird deshalb eine Anzahl. Fuer die Wertung ist das
     * gleichgueltig: jeder Teil zaehlt einmal.
     *
     * @return int Kennung des neuen Vorfalls
     */
    public function teile(int $klasseId, int $id, int $anzahl): int
    {
        $vorfall = $this->eines($id);
        if ($vorfall === null || (int) $vorfall['klasse_id'] !== $klasseId) {
            throw new \InvalidArgumentException('Vorfall nicht gefunden.');
        }

        $zeilen = (int) $vorfall['zeilen'];
        if ($anzahl < 1 || $anzahl >= $zeilen) {
            throw new \InvalidArgumentException('Abgetrennt werden kann zwischen 1 und ' . max(1, $zeilen - 1) . ' Zeilen.');
        }

        $this->verbindung->beginTransaction();
        try {
            $this->verbindung->prepare(
                "INSERT INTO vorfaelle
                    (klasse_id, datum, fach_kuerzel, lehrkraft_kennung, webuntis_kategorie, kategoriegruppe,
                     anzahl_beteiligte, klasseneintrag, originaltext, text_vorlage, namens_kennungen, status)
                 SELECT klasse_id, datum, fach_kuerzel, lehrkraft_kennung, webuntis_kategorie, kategoriegruppe,
                        LEAST(anzahl_beteiligte, ?), 0, originaltext, text_vorlage, namens_kennungen, 'offen'
                   FROM vorfaelle WHERE id = ?"
            )->execute([$anzahl, $id]);
            $neu = (int) $this->verbindung->lastInsertId();

            $this->verbindung->prepare(
                "UPDATE importzeilen SET vorfall_id = ? WHERE vorfall_id = ? ORDER BY fingerabdruck LIMIT $anzahl"
            )->execute([$neu, $id]);

            $this->verbindung->prepare(
                'UPDATE vorfaelle SET anzahl_beteiligte = GREATEST(anzahl_beteiligte - ?, 0) WHERE id = ?'
            )->execute([$anzahl, $id]);

            $this->verbindung->commit();
        } catch (\Throwable $e) {
            $this->verbindung->rollBack();
            throw $e;
        }

        $this->neuBerechnen($klasseId);

        return $neu;
    }

    /**
     * Fuehrt einen Vorfall in einen anderen derselben Klasse. Danach zaehlt
     * nur noch der Zielvorfall - einmal, mit allen Beteiligten.
     */
    public function fuehreZusammen(int $klasseId, int $id, int $zielId): void
    {
        if ($id === $zielId) {
            throw new \InvalidArgumentException('Ein Vorfall laesst sich nicht mit sich selbst zusammenfuehren.');
        }

        $quelle = $this->eines($id);
        $ziel = $this->eines($zielId);
        if ($quelle === null || $ziel === null
            || (int) $quelle['klasse_id'] !== $klasseId || (int) $ziel['klasse_id'] !== $klasseId) {
            throw new \InvalidArgumentException('Vorfall nicht gefunden.');
        }

        $kennungen = array_values(array_unique([...$this->namensKennungen($ziel), ...$this->namensKennungen($quelle)]));
        sort($kennungen);

        $this->verbindung->beginTransaction();
        try {
            $this->verbindung->prepare('UPDATE importzeilen SET vorfall_id = ? WHERE vorfall_id = ?')->execute([$zielId, $id]);
            $this->verbindung->prepare(
                'UPDATE vorfaelle
                    SET anzahl_beteiligte = anzahl_beteiligte + ?, klasseneintrag = GREATEST(klasseneintrag, ?),
                        namens_kennungen = ?
                  WHERE id = ?'
            )->execute([
                (int) $quelle['anzahl_beteiligte'],
                (int) $quelle['klasseneintrag'],
                $kennungen !== [] ? json_encode($kennungen) : null,
                $zielId,
            ]);
            $this->verbindung->prepare('UPDATE vorfaelle SET moeglich_zu = NULL WHERE moeglich_zu = ?')->execute([$id]);
            $this->verbindung->prepare('DELETE FROM vorfaelle WHERE id = ?')->execute([$id]);
            $this->verbindung->commit();
        } catch (\Throwable $e) {
            $this->verbindung->rollBack();
            throw $e;
        }

        $this->neuBerechnen($klasseId);
    }

    /**
     * Andere Vorfaelle desselben Tages, als Ziel fuer das Zusammenfuehren.
     *
     * @return list<array<string,mixed>>
     */
    public function amSelbenTag(int $klasseId, int $id, string $datum): array
    {
        $abfrage = $this->verbindung->prepare(
            "SELECT v.id, v.fach_kuerzel, f.klartext AS fach_klartext, v.webuntis_kategorie, v.anzahl_beteiligte, v.status, v.kurztext
               FROM vorfaelle v LEFT JOIN faecher f ON f.kuerzel = v.fach_kuerzel
              WHERE v.klasse_id = ? AND v.datum = ? AND v.id <> ?
                AND NOT (v.status = 'ignoriert' AND v.entschieden_von IS NULL)
              ORDER BY v.id"
        );
        $abfrage->execute([$klasseId, $datum, $id]);

        return $abfrage->fetchAll() ?: [];
    }

    private function neuBerechnen(int $klasseId): void
    {
        $this->verbindung->prepare('UPDATE klassen SET daten_version = daten_version + 1 WHERE id = ?')->execute([$klasseId]);
    }
}
