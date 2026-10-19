<?php

declare(strict_types=1);

namespace Klassenziel\Klassen;

use Klassenziel\Import\Importdienst;
use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\Tresor;
use Klassenziel\Sicherheit\Zugangslink;
use PDO;
use PDOException;

/**
 * Klassen, ihre Zustaendigkeiten und ihr geheimer Link.
 *
 * Die Pruefung, wer eine Klasse sehen darf, steht hier in der Abfrage und
 * nicht erst in der Oberflaeche: eine vergessene Pruefung in einem Formular
 * soll nicht reichen, um an eine fremde Klasse zu kommen.
 */
final class KlassenRepository
{
    public function __construct(
        private readonly PDO $verbindung,
        private readonly Schluessel $schluessel,
    ) {
    }

    /**
     * Die Klassen, die dieses Konto sehen darf: die Verwaltung alle, eine
     * Lehrkraft die, fuer die sie zustaendig ist.
     *
     * @return list<Klasse>
     */
    public function sichtbarFuer(Konto $konto, bool $mitArchiv = false): array
    {
        $archiv = $mitArchiv ? '' : 'AND k.archiviert_am IS NULL';

        if ($konto->istVerwaltung) {
            $abfrage = $this->verbindung->query(
                "SELECT k.* FROM klassen k WHERE 1 = 1 $archiv ORDER BY k.archiviert_am IS NOT NULL, k.schuljahr DESC, k.anzeigename"
            );
        } else {
            $abfrage = $this->verbindung->prepare(
                "SELECT k.* FROM klassen k
                   JOIN klassen_lehrkraefte z ON z.klasse_id = k.id
                  WHERE z.konto_id = ? $archiv
                  ORDER BY k.archiviert_am IS NOT NULL, k.schuljahr DESC, k.anzeigename"
            );
            $abfrage->execute([$konto->id]);
        }

        $zeilen = $abfrage !== false ? ($abfrage->fetchAll() ?: []) : [];

        return array_map(static fn (array $z): Klasse => Klasse::ausZeile($z), $zeilen);
    }

    /** Eine Klasse, wenn dieses Konto sie sehen darf. */
    public function fuer(Konto $konto, int $id): ?Klasse
    {
        $klasse = $this->nachId($id);
        if ($klasse === null) {
            return null;
        }

        return $konto->istVerwaltung || $this->istZustaendig($konto->id, $id) ? $klasse : null;
    }

    public function nachId(int $id): ?Klasse
    {
        $abfrage = $this->verbindung->prepare('SELECT * FROM klassen WHERE id = ?');
        $abfrage->execute([$id]);
        $zeile = $abfrage->fetch();

        return is_array($zeile) ? Klasse::ausZeile($zeile) : null;
    }

    /**
     * Die Klasse zu einem geheimen Link.
     *
     * Archivierte Klassen haben keinen Link mehr; ein alter Link fuehrt ins
     * selbe 404 wie ein erfundener.
     */
    public function nachLink(string $token): ?Klasse
    {
        if (!Zugangslink::formGueltig($token)) {
            return null;
        }

        $abfrage = $this->verbindung->prepare(
            'SELECT * FROM klassen WHERE link_token_kennung = ? AND archiviert_am IS NULL'
        );
        $abfrage->execute([Zugangslink::kennung($this->schluessel, $token)]);
        $zeile = $abfrage->fetch();

        return is_array($zeile) ? Klasse::ausZeile($zeile) : null;
    }

    /**
     * Legt eine Klasse an. Wer sie anlegt, ist fuer sie zustaendig, und sie
     * bekommt sofort einen Link.
     *
     * @throws KlasseExistiert
     */
    public function anlegen(Klassendaten $daten, Konto $angelegtVon): int
    {
        $this->verbindung->beginTransaction();

        try {
            $this->verbindung->prepare(
                'INSERT INTO klassen
                    (anzeigename, export_kuerzel, schuljahr, belohnung, startdatum, startwert, zielwert, stichtag)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($this->werte($daten));

            $id = (int) $this->verbindung->lastInsertId();

            // Punkte und Wochenbonus aus den Vorgaben der Schule. Kopiert,
            // nicht verwiesen: eine spaetere Aenderung der Vorgaben soll keinen
            // laufenden Wettbewerb veraendern.
            $this->verbindung->prepare(
                'INSERT INTO einstellungen (klasse_id, punkte_klein, punkte_mittel, punkte_gross, wochenbonus)
                 SELECT ?, punkte_klein, punkte_mittel, punkte_gross, wochenbonus FROM vorgaben WHERE id = 1'
            )->execute([$id]);

            $this->setzeZustaendig($id, $angelegtVon->id);
            $this->speichereLink($id, Zugangslink::erzeugen());

            $this->verbindung->commit();
        } catch (\Throwable $e) {
            $this->verbindung->rollBack();

            if ($e instanceof PDOException && $e->getCode() === '23000') {
                throw new KlasseExistiert($daten->exportKuerzel, $daten->schuljahr);
            }

            throw $e;
        }

        return $id;
    }

    /**
     * @throws KlasseExistiert
     */
    public function speichern(int $id, Klassendaten $daten): void
    {
        try {
            $this->verbindung->prepare(
                'UPDATE klassen
                    SET anzeigename = ?, export_kuerzel = ?, schuljahr = ?, belohnung = ?,
                        startdatum = ?, startwert = ?, zielwert = ?, stichtag = ?,
                        daten_version = daten_version + 1
                  WHERE id = ? AND archiviert_am IS NULL'
            )->execute([...$this->werte($daten), $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new KlasseExistiert($daten->exportKuerzel, $daten->schuljahr);
            }

            throw $e;
        }
    }

    /**
     * Erzeugt einen neuen Link. Der alte gilt ab diesem Moment nicht mehr: es
     * gibt je Klasse genau eine Kennung, und sie wird ersetzt.
     */
    public function neuerLink(int $id): string
    {
        $token = Zugangslink::erzeugen();
        $this->speichereLink($id, $token);

        return $token;
    }

    /** Der Link im Klartext, fuer die Anzeige im Admin. */
    public function link(Klasse $klasse): ?string
    {
        $abfrage = $this->verbindung->prepare('SELECT link_token FROM klassen WHERE id = ?');
        $abfrage->execute([$klasse->id]);
        $verschlossen = $abfrage->fetchColumn();

        return (new Tresor($this->schluessel))->oeffnenOderNull(
            is_string($verschlossen) ? $verschlossen : null,
            Zugangslink::ZWECK_VERSCHLUESSELUNG
        );
    }

    /**
     * Archivieren am Schuljahresende.
     *
     * Der Link verfaellt, und alles, was aus dem Klassenbuch stammt und nicht
     * fuer die Wertung gebraucht wird, wird geloescht: die Originaltexte, die
     * Textvorlagen der Gruppierung, die Kennungen der Namensbestandteile und
     * die Namensliste - und offene Import-Vorschauen mit Zeilen dieser
     * Klasse. Datum, Fach, Kategorie, Punkte und freigegebene Kurztexte
     * bleiben - sie stehen ohnehin auf der oeffentlichen Seite.
     */
    public function archivieren(int $id): void
    {
        $this->verbindung->beginTransaction();

        try {
            $this->verbindung->prepare(
                'UPDATE klassen
                    SET archiviert_am = NOW(), link_token = NULL, link_token_kennung = NULL,
                        daten_version = daten_version + 1
                  WHERE id = ? AND archiviert_am IS NULL'
            )->execute([$id]);

            $this->verbindung->prepare(
                'UPDATE vorfaelle SET originaltext = NULL, text_vorlage = NULL, namens_kennungen = NULL
                  WHERE klasse_id = ?'
            )->execute([$id]);

            $this->verbindung->prepare('DELETE FROM namensliste WHERE klasse_id = ?')->execute([$id]);

            $this->verwirfVorschauenMit($id);

            $this->verbindung->commit();
        } catch (\Throwable $e) {
            $this->verbindung->rollBack();

            throw $e;
        }
    }

    /**
     * Offene Import-Vorschauen mit Zeilen dieser Klasse verwerfen.
     *
     * Ihr Plan traegt die verschluesselten Originaltexte - nach dem
     * Archivieren soll davon nichts mehr in der Datenbank liegen, auch nicht
     * fuer die Stunde, die eine Vorschau sonst noch gilt. Welche Klassen eine
     * Vorschau betrifft, steht nur im verschluesselten Plan; es sind wenige,
     * also wird jede offene geoeffnet.
     */
    private function verwirfVorschauenMit(int $klasseId): void
    {
        $tresor = new Tresor($this->schluessel);
        $abfrage = $this->verbindung->query("SELECT id, plan FROM importe WHERE status = 'vorschau' AND plan IS NOT NULL");
        $verwerfen = $this->verbindung->prepare(
            "UPDATE importe SET status = 'verworfen', plan = NULL, laeuft_ab = NULL WHERE id = ?"
        );

        foreach ($abfrage !== false ? ($abfrage->fetchAll() ?: []) : [] as $zeile) {
            $json = $tresor->oeffnenOderNull((string) $zeile['plan'], Importdienst::ZWECK_PLAN);
            $plan = $json !== null ? json_decode($json, true) : null;

            // Ein Plan, der sich nicht oeffnen laesst, ist ohnehin unbrauchbar.
            if (!is_array($plan) || !is_array($plan['klassen'] ?? null) || array_key_exists($klasseId, $plan['klassen'])) {
                $verwerfen->execute([(int) $zeile['id']]);
            }
        }
    }

    public function istZustaendig(int $kontoId, int $klasseId): bool
    {
        $abfrage = $this->verbindung->prepare(
            'SELECT 1 FROM klassen_lehrkraefte WHERE konto_id = ? AND klasse_id = ?'
        );
        $abfrage->execute([$kontoId, $klasseId]);

        return $abfrage->fetchColumn() !== false;
    }

    /** @return list<array{id: int, name: string}> */
    public function zustaendige(int $klasseId): array
    {
        $abfrage = $this->verbindung->prepare(
            'SELECT k.id, k.name FROM konten k
               JOIN klassen_lehrkraefte z ON z.konto_id = k.id
              WHERE z.klasse_id = ? ORDER BY k.name'
        );
        $abfrage->execute([$klasseId]);

        return array_map(
            static fn (array $z): array => ['id' => (int) $z['id'], 'name' => (string) $z['name']],
            $abfrage->fetchAll() ?: []
        );
    }

    public function setzeZustaendig(int $klasseId, int $kontoId): void
    {
        $this->verbindung->prepare(
            'INSERT IGNORE INTO klassen_lehrkraefte (klasse_id, konto_id) VALUES (?, ?)'
        )->execute([$klasseId, $kontoId]);
    }

    /**
     * Nimmt eine Zustaendigkeit weg. Die letzte bleibt: eine Klasse ohne
     * zustaendige Lehrkraft koennte nur noch die Verwaltung pflegen, und das
     * faellt niemandem auf, bis der naechste Export liegen bleibt.
     */
    public function entferneZustaendig(int $klasseId, int $kontoId): bool
    {
        if (count($this->zustaendige($klasseId)) <= 1) {
            return false;
        }

        $this->verbindung->prepare(
            'DELETE FROM klassen_lehrkraefte WHERE klasse_id = ? AND konto_id = ?'
        )->execute([$klasseId, $kontoId]);

        return true;
    }

    private function speichereLink(int $id, string $token): void
    {
        $this->verbindung->prepare(
            'UPDATE klassen SET link_token = ?, link_token_kennung = ?, daten_version = daten_version + 1 WHERE id = ?'
        )->execute([
            (new Tresor($this->schluessel))->verschliessen($token, Zugangslink::ZWECK_VERSCHLUESSELUNG),
            Zugangslink::kennung($this->schluessel, $token),
            $id,
        ]);
    }

    /** @return list<string|int|null> */
    private function werte(Klassendaten $daten): array
    {
        return [
            $daten->anzeigename,
            $daten->exportKuerzel,
            $daten->schuljahr,
            $daten->belohnung,
            $daten->startdatum,
            (int) $daten->startwert,
            (int) $daten->zielwert,
            $daten->stichtag !== '' ? $daten->stichtag : null,
        ];
    }
}
