<?php

declare(strict_types=1);

namespace Klassenziel\Import;

use DateTimeImmutable;
use Klassenziel\Klassen\Klasse;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\Schluesselpruefung;
use Klassenziel\Sicherheit\Tresor;
use Klassenziel\Wertung\Kategoriezuordnung;
use PDO;
use PDOException;

/**
 * Der Import eines WebUntis-Exports: Hochladen, Vorschau, Bestaetigen.
 *
 * Die Schuelernamen gibt es nur waehrend der Vorschau im Arbeitsspeicher.
 * Zwischen Vorschau und Bestaetigen liegt ein Importplan in der Datenbank,
 * und der ist namensfrei: Fingerabdruecke, Gruppierung, Kennungen der
 * Namensteile und die schon verschluesselten Texte. Er ist als Ganzes
 * noch einmal verschluesselt und laeuft nach einer Stunde ab. Die Sitzung kam
 * dafuer nicht in Frage, weil Sitzungsdateien auf der Platte liegen.
 *
 * Beim Bestaetigen gilt die Loeschregel fuer Originaltexte (Planung,
 * Abschnitt 5.2, mit der am 19.10.2026 abgestimmten Auslegung): Ist ein
 * Vorfall entschieden und fuegt ihm ein spaeterer Import keine Zeile hinzu,
 * werden Originaltext und Textvorlage geloescht. Spaetestens geschieht das
 * beim Archivieren der Klasse.
 */
final class Importdienst
{
    public const GUELTIGKEIT_SEKUNDEN = 3600;
    public const ZWECK_PLAN = 'importe.plan';
    public const ZWECK_ORIGINAL = 'vorfaelle.originaltext';
    public const ZWECK_VORLAGE = 'vorfaelle.text_vorlage';

    private const DATEINAME = '/^ClassregEventPerKlasse_(\d{8})_(\d{4})\.csv$/i';
    private const KURZFORM = 90;

    private readonly Tresor $tresor;
    private readonly Kategoriezuordnung $kategorien;

    public function __construct(
        private readonly PDO $db,
        private readonly Schluessel $schluessel,
        private readonly KlassenRepository $klassen,
    ) {
        $this->tresor = new Tresor($schluessel);
        $this->kategorien = new Kategoriezuordnung($db);
    }

    /**
     * Liest die Datei, gleicht sie mit dem Bestand ab und legt die Vorschau an.
     *
     * @return int Kennung des Imports
     *
     * @throws ImportFehler
     */
    public function vorschau(string $inhalt, string $dateiname, Konto $konto, DateTimeImmutable $jetzt): int
    {
        $this->pruefeSchluessel();
        $this->verwirfAbgelaufene($jetzt);

        $gelesen = ExportParser::lies($inhalt);
        if ($gelesen['zeilen'] === []) {
            throw new ImportFehler('Die Datei enthält keine Einträge.');
        }

        [$name, $exportzeitpunkt] = self::dateiname($dateiname, $jetzt);

        $plan = $this->plane($gelesen['zeilen'], $konto);
        $plan['unlesbar'] = $gelesen['unlesbar'];
        $plan['exportzeitpunkt'] = $exportzeitpunkt->format('Y-m-d H:i:s');

        $zaehler = $plan['zaehler'];

        $this->db->prepare(
            'INSERT INTO importe
                (dateiname, exportzeitpunkt, importiert_von, status, plan, laeuft_ab,
                 zeilen_gesamt, zeilen_neu, zeilen_duplikat, zeilen_fremd, vorfaelle_neu, vorfaelle_zugeordnet, pruefliste)
             VALUES (?, ?, ?, \'vorschau\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $name,
            $exportzeitpunkt->format('Y-m-d H:i:s'),
            $konto->id,
            $this->tresor->verschliessen((string) json_encode($plan, JSON_THROW_ON_ERROR), self::ZWECK_PLAN),
            $jetzt->modify('+' . self::GUELTIGKEIT_SEKUNDEN . ' seconds')->format('Y-m-d H:i:s'),
            count($gelesen['zeilen']) + count($gelesen['unlesbar']),
            $zaehler['zeilen_neu'],
            $zaehler['zeilen_duplikat'],
            $zaehler['zeilen_fremd'],
            $zaehler['vorfaelle_neu'],
            $zaehler['vorfaelle_zugeordnet'],
            $zaehler['pruefliste'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Ein Import, wenn dieses Konto ihn sehen darf. Den Plan bekommt nur, wer
     * die Vorschau angelegt hat, und nur solange sie offen ist.
     *
     * @return array{import: array<string,mixed>, plan: ?array<string,mixed>}|null
     */
    public function lade(int $id, Konto $konto): ?array
    {
        $abfrage = $this->db->prepare(
            'SELECT i.*, k.name AS importiert_von_name FROM importe i
               LEFT JOIN konten k ON k.id = i.importiert_von
              WHERE i.id = ?'
        );
        $abfrage->execute([$id]);
        $import = $abfrage->fetch();

        if (!is_array($import)) {
            return null;
        }

        $eigener = (int) $import['importiert_von'] === $konto->id;
        if (!$eigener && !($konto->istVerwaltung && $import['status'] === 'bestaetigt')) {
            return null;
        }

        $plan = null;
        if ($eigener && $import['status'] === 'vorschau' && $import['plan'] !== null) {
            $plan = $this->planAus((string) $import['plan']);
        }

        unset($import['plan']);

        return ['import' => $import, 'plan' => $plan];
    }

    /**
     * Uebernimmt die Vorschau in den Bestand - ganz oder gar nicht.
     *
     * @return array{vorfaelle_neu: int, vorfaelle_zugeordnet: int, zeilen_neu: int, texte_geloescht: int}
     *
     * @throws ImportFehler
     */
    public function bestaetigen(int $id, Konto $konto, DateTimeImmutable $jetzt): array
    {
        $this->pruefeSchluessel();

        $abfrage = $this->db->prepare(
            "SELECT * FROM importe WHERE id = ? AND importiert_von = ? AND status = 'vorschau'"
        );
        $abfrage->execute([$id, $konto->id]);
        $import = $abfrage->fetch();

        if (!is_array($import) || $import['plan'] === null) {
            throw new ImportFehler('Diese Vorschau gibt es nicht mehr. Bitte die Datei erneut hochladen.');
        }

        if (new DateTimeImmutable((string) $import['laeuft_ab']) < $jetzt) {
            $this->verwerfen($id, $konto);
            throw new ImportFehler('Die Vorschau ist abgelaufen. Bitte die Datei erneut hochladen.');
        }

        $plan = $this->planAus((string) $import['plan']);
        if ($plan === null) {
            throw new ImportFehler('Die Vorschau lässt sich nicht mehr lesen. Bitte die Datei erneut hochladen.');
        }

        /** @var list<array{f: string, k: int, ziel: array{0: string, 1: int}}> $zeilen */
        $zeilen = $plan['zeilen'];
        /** @var list<array<string,mixed>> $neue */
        $neue = $plan['neue_vorfaelle'];
        /** @var array<string, array{klasse: int, beteiligte: int, klasseneintrag: bool, namens_kennungen: list<string>}> $zuordnungen */
        $zuordnungen = $plan['zuordnungen'];
        /** @var array<string, array<string,mixed>> $klassen */
        $klassen = $plan['klassen'];

        $this->db->beginTransaction();

        try {
            if ($this->einerSchonBekannt(array_column($zeilen, 'f'))) {
                throw new ImportFehler(
                    'Inzwischen ist ein anderer Import mit denselben Einträgen bestätigt worden. Bitte die Datei erneut hochladen.'
                );
            }

            foreach (array_keys($klassen) as $klasseId) {
                $klasse = $this->klassen->nachId((int) $klasseId);
                if ($klasse === null || $klasse->istArchiviert()) {
                    throw new ImportFehler('Eine der Klassen ist inzwischen archiviert. Bitte die Datei erneut hochladen.');
                }
            }

            foreach ($plan['faecher'] as $fach) {
                $this->db->prepare('INSERT IGNORE INTO faecher (kuerzel) VALUES (?)')->execute([$fach]);
            }
            foreach ($plan['kategorien'] as $kategorie) {
                $this->db->prepare("INSERT IGNORE INTO kategorie_vorgaben (webuntis_kategorie, richtung) VALUES (?, 'pruefen')")
                    ->execute([$kategorie]);
            }

            $neueIds = [];
            $einfuegen = $this->db->prepare(
                'INSERT INTO vorfaelle
                    (klasse_id, datum, fach_kuerzel, lehrkraft_kennung, webuntis_kategorie, kategoriegruppe,
                     anzahl_beteiligte, klasseneintrag, originaltext, text_vorlage, namens_kennungen,
                     status, moeglich_zu, entschieden_am)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($neue as $nummer => $vorfall) {
                $einfuegen->execute([
                    $vorfall['klasse'],
                    $vorfall['datum'],
                    $vorfall['fach'],
                    $vorfall['lehrkraft'],
                    $vorfall['kategorie'],
                    $vorfall['kategoriegruppe'],
                    $vorfall['beteiligte'],
                    $vorfall['klasseneintrag'] ? 1 : 0,
                    $vorfall['originaltext'],
                    $vorfall['text_vorlage'],
                    $vorfall['namens_kennungen'] !== [] ? json_encode($vorfall['namens_kennungen']) : null,
                    $vorfall['status'],
                    $vorfall['moeglich_zu'],
                    // Neutrale Eintraege entscheidet die Regel, nicht die Lehrkraft.
                    $vorfall['status'] === 'ignoriert' ? $jetzt->format('Y-m-d H:i:s') : null,
                ]);
                $neueIds[$nummer] = (int) $this->db->lastInsertId();
            }

            foreach ($zuordnungen as $vorfallId => $zuordnung) {
                $this->ergaenzeVorfall((int) $vorfallId, $zuordnung);
            }

            $zeileEinfuegen = $this->db->prepare(
                'INSERT INTO importzeilen (fingerabdruck, vorfall_id, import_id, klasse_id) VALUES (?, ?, ?, ?)'
            );
            foreach ($zeilen as $zeile) {
                $vorfallId = $zeile['ziel'][0] === 'neu' ? $neueIds[$zeile['ziel'][1]] : $zeile['ziel'][1];
                $zeileEinfuegen->execute([$zeile['f'], $vorfallId, $id, $zeile['k']]);
            }

            $texteGeloescht = $this->loescheErledigteTexte(
                array_map('intval', array_keys($klassen)),
                array_map('intval', array_keys($zuordnungen)),
                $jetzt
            );

            foreach (array_keys($klassen) as $klasseId) {
                $this->db->prepare(
                    'UPDATE klassen
                        SET datenstand = GREATEST(COALESCE(datenstand, ?), ?), daten_version = daten_version + 1
                      WHERE id = ?'
                )->execute([$plan['exportzeitpunkt'], $plan['exportzeitpunkt'], $klasseId]);
            }

            $this->db->prepare(
                "UPDATE importe SET status = 'bestaetigt', plan = NULL, laeuft_ab = NULL, bestaetigt_am = ? WHERE id = ?"
            )->execute([$jetzt->format('Y-m-d H:i:s'), $id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            if ($e instanceof PDOException && $e->getCode() === '23000') {
                throw new ImportFehler(
                    'Inzwischen ist ein anderer Import mit denselben Einträgen bestätigt worden. Bitte die Datei erneut hochladen.'
                );
            }

            throw $e;
        }

        return [
            'vorfaelle_neu' => count($neue),
            'vorfaelle_zugeordnet' => count($zuordnungen),
            'zeilen_neu' => count($zeilen),
            'texte_geloescht' => $texteGeloescht,
        ];
    }

    public function verwerfen(int $id, Konto $konto): void
    {
        $this->db->prepare(
            "UPDATE importe SET status = 'verworfen', plan = NULL, laeuft_ab = NULL
              WHERE id = ? AND importiert_von = ? AND status = 'vorschau'"
        )->execute([$id, $konto->id]);
    }

    /**
     * Die bestaetigten Importe: die eigenen, fuer die Verwaltung alle.
     *
     * @return list<array<string,mixed>>
     */
    public function protokoll(Konto $konto, int $grenze = 20): array
    {
        $grenze = max(1, min(200, $grenze));
        $nurEigene = $konto->istVerwaltung ? '' : 'AND i.importiert_von = :konto';

        $abfrage = $this->db->prepare(
            "SELECT i.id, i.dateiname, i.exportzeitpunkt, i.bestaetigt_am, i.zeilen_gesamt, i.zeilen_neu,
                    i.zeilen_duplikat, i.zeilen_fremd, i.vorfaelle_neu, i.vorfaelle_zugeordnet, i.pruefliste,
                    k.name AS importiert_von_name
               FROM importe i LEFT JOIN konten k ON k.id = i.importiert_von
              WHERE i.status = 'bestaetigt' $nurEigene
              ORDER BY i.bestaetigt_am DESC, i.id DESC
              LIMIT $grenze"
        );
        $abfrage->execute($konto->istVerwaltung ? [] : [':konto' => $konto->id]);

        return $abfrage->fetchAll() ?: [];
    }

    /**
     * Dateiname und Exportzeitpunkt. Ein frei gewaehlter Name kann einen
     * Schuelernamen tragen ("Max_Muster.csv") - gespeichert wird er nur in
     * der Form, die WebUntis vergibt.
     *
     * @return array{0: string, 1: DateTimeImmutable}
     */
    public static function dateiname(string $dateiname, DateTimeImmutable $jetzt): array
    {
        $dateiname = basename(str_replace('\\', '/', $dateiname));

        if (preg_match(self::DATEINAME, $dateiname, $teile) === 1) {
            $zeitpunkt = DateTimeImmutable::createFromFormat('!YmdHi', $teile[1] . $teile[2]);
            if ($zeitpunkt !== false && $zeitpunkt->format('YmdHi') === $teile[1] . $teile[2]) {
                return [$dateiname, $zeitpunkt];
            }
        }

        return ['Export', $jetzt];
    }

    // --- Planung -----------------------------------------------------------

    /**
     * @param list<Exportzeile> $zeilen
     * @return array<string,mixed>
     */
    private function plane(array $zeilen, Konto $konto): array
    {
        $zuordnungKlasse = $this->klassenNachKuerzel($konto);
        $bekannteZeilen = $this->bekannteFingerabdruecke(array_map(
            fn (Exportzeile $z): string => Fingerabdruck::von($this->schluessel, $z),
            $zeilen
        ));

        $fremd = [];
        $klassen = [];
        $gesehen = [];
        $faecher = [];
        $kategorien = [];
        $duplikate = 0;

        /** @var list<array{zeile: Exportzeile, klasse: Klasse, f: string, lehrkraft: string, teile: list<string>, vorlage: string, schluessel: string, neutral: bool, richtung: string}> $kandidaten */
        $kandidaten = [];

        foreach ($zeilen as $zeile) {
            $kuerzel = mb_strtolower($zeile->klasse);
            $treffer = $zuordnungKlasse[$kuerzel] ?? null;

            if ($treffer === null || $treffer['klasse'] === null) {
                $grund = $treffer['grund'] ?? 'unbekannt';
                $fremd[$zeile->klasse . "\x1F" . $grund] ??= ['kuerzel' => $zeile->klasse, 'grund' => $grund, 'zeilen' => 0];
                $fremd[$zeile->klasse . "\x1F" . $grund]['zeilen']++;
                continue;
            }

            $klasse = $treffer['klasse'];
            $klassen[$klasse->id] ??= [
                'name' => $klasse->anzeigename,
                'kuerzel' => $klasse->exportKuerzel,
                'zeilen_neu' => 0,
                'duplikate' => 0,
            ];

            $f = Fingerabdruck::von($this->schluessel, $zeile);
            if (isset($bekannteZeilen[$f]) || isset($gesehen[$f])) {
                $duplikate++;
                $klassen[$klasse->id]['duplikate']++;
                continue;
            }
            $gesehen[$f] = true;
            $klassen[$klasse->id]['zeilen_neu']++;

            if ($zeile->fach !== '') {
                $faecher[$zeile->fach] = true;
            }

            $wertung = $this->kategorien->fuer($klasse->id, $zeile->kategorie, $zeile->kategoriegruppe);
            if ($zeile->kategorie !== '' && $wertung['richtung'] === 'pruefen') {
                $kategorien[$zeile->kategorie] = true;
            }

            $teile = $zeile->istKlasseneintrag() ? [] : Namensbestandteile::aus($zeile->name);
            $lehrkraft = Fingerabdruck::lehrkraft($this->schluessel, $zeile);

            $kandidaten[] = [
                'zeile' => $zeile,
                'klasse' => $klasse,
                'f' => $f,
                'lehrkraft' => $lehrkraft,
                'teile' => $teile,
                'vorlage' => Normalisierung::vergleich(Namensbestandteile::ersetze($zeile->text, $teile)),
                'schluessel' => Gruppierer::schluessel($klasse->id, $zeile->datum->format('Y-m-d'), $zeile->fach, $lehrkraft, $zeile->kategorie),
                'neutral' => $wertung['richtung'] === 'neutral',
                'richtung' => $wertung['richtung'],
            ];
        }

        $planZeilen = [];
        $neue = [];
        $zuordnungen = [];
        $zugeordneteZeilen = 0;

        foreach ([false, true] as $neutral) {
            $teil = array_values(array_filter($kandidaten, static fn (array $k): bool => $k['neutral'] === $neutral));
            if ($teil === []) {
                continue;
            }

            // Neutrale Eintraege werden nie einem bekannten Vorfall zugeordnet:
            // sie zaehlen nicht, und ihr Text wird gar nicht erst gespeichert.
            $bekannte = $neutral ? [] : $this->bekannteVorfaelle($teil);
            $versatz = count($neue);

            $ergebnis = Gruppierer::ordneZu(
                array_map(static fn (array $k): array => ['schluessel' => $k['schluessel'], 'vorlage' => $k['vorlage']], $teil),
                $bekannte
            );

            foreach ($teil as $i => $k) {
                $zuordnung = $ergebnis[$i];
                $zeile = $k['zeile'];
                $kennungen = $neutral ? [] : Namensbestandteile::kennungen($this->schluessel, $k['teile']);

                if ($zuordnung['art'] === 'bekannt') {
                    $vorfallId = $zuordnung['vorfall'];
                    $zuordnungen[(string) $vorfallId] ??= [
                        'klasse' => $k['klasse']->id,
                        'beteiligte' => 0,
                        'klasseneintrag' => false,
                        'namens_kennungen' => [],
                        'datum' => $zeile->datum->format('Y-m-d'),
                        'fach' => $zeile->fach,
                        'kategorie' => $zeile->kategorie,
                        'kurzform' => $this->kurzform($zeile, $k['teile']),
                    ];
                    $zuordnungen[(string) $vorfallId]['beteiligte'] += $zeile->istKlasseneintrag() ? 0 : 1;
                    $zuordnungen[(string) $vorfallId]['klasseneintrag'] = $zuordnungen[(string) $vorfallId]['klasseneintrag'] || $zeile->istKlasseneintrag();
                    $zuordnungen[(string) $vorfallId]['namens_kennungen'] = self::vereinige($zuordnungen[(string) $vorfallId]['namens_kennungen'], $kennungen);
                    $planZeilen[] = ['f' => $k['f'], 'k' => $k['klasse']->id, 'ziel' => ['bekannt', $vorfallId]];
                    $zugeordneteZeilen++;
                    continue;
                }

                $nummer = $versatz + $zuordnung['gruppe'];

                if (!isset($neue[$nummer])) {
                    $status = $neutral ? 'ignoriert' : ($zuordnung['moeglich_zu'] !== null ? 'zuordnung_pruefen' : 'offen');

                    $neue[$nummer] = [
                        'klasse' => $k['klasse']->id,
                        'datum' => $zeile->datum->format('Y-m-d'),
                        'fach' => $zeile->fach,
                        'lehrkraft' => $k['lehrkraft'],
                        'kategorie' => $zeile->kategorie,
                        'kategoriegruppe' => $zeile->kategoriegruppe,
                        'richtung' => $k['richtung'],
                        'beteiligte' => 0,
                        'klasseneintrag' => false,
                        // Ein Originaltext je Vorfall, der der ersten Zeile.
                        'originaltext' => $neutral ? null : $this->tresor->verschliessen($zeile->text, self::ZWECK_ORIGINAL),
                        'text_vorlage' => $neutral ? null : $this->tresor->verschliessen($k['vorlage'], self::ZWECK_VORLAGE),
                        'namens_kennungen' => [],
                        'status' => $status,
                        'moeglich_zu' => $neutral ? null : $zuordnung['moeglich_zu'],
                        'kurzform' => $neutral ? '' : $this->kurzform($zeile, $k['teile']),
                    ];
                }

                $neue[$nummer]['beteiligte'] += $zeile->istKlasseneintrag() ? 0 : 1;
                $neue[$nummer]['klasseneintrag'] = $neue[$nummer]['klasseneintrag'] || $zeile->istKlasseneintrag();
                $neue[$nummer]['namens_kennungen'] = self::vereinige($neue[$nummer]['namens_kennungen'], $kennungen);
                $planZeilen[] = ['f' => $k['f'], 'k' => $k['klasse']->id, 'ziel' => ['neu', $nummer]];
            }
        }

        ksort($neue);
        $neue = array_values($neue);
        // Die Nummern in den Planzeilen zeigen auf die Reihenfolge in $neue.
        // ksort/array_values aendert sie nicht, weil sie lueckenlos ab 0 vergeben sind.

        $pruefliste = count(array_filter($neue, static fn (array $v): bool => $v['status'] !== 'ignoriert'));

        return [
            'version' => 1,
            'klassen' => $klassen,
            'fremd' => array_values($fremd),
            'zeilen' => $planZeilen,
            'neue_vorfaelle' => $neue,
            'zuordnungen' => $zuordnungen,
            'faecher' => array_keys($faecher),
            'kategorien' => array_keys($kategorien),
            'zaehler' => [
                'zeilen_neu' => count($planZeilen),
                'zeilen_duplikat' => $duplikate,
                'zeilen_fremd' => array_sum(array_column($fremd, 'zeilen')),
                'vorfaelle_neu' => count($neue),
                'vorfaelle_zugeordnet' => $zugeordneteZeilen,
                'pruefliste' => $pruefliste,
                'ohne_einstufung' => count(array_filter($neue, static fn (array $v): bool => $v['richtung'] === 'pruefen')),
                'neutral' => count($neue) - $pruefliste,
            ],
        ];
    }

    /**
     * Die Klassen des Exports, nach Kuerzel. Fuer jede Zeile ist so klar, ob
     * sie hierher gehoert, zu einer fremden Klasse oder zu keiner.
     *
     * @return array<string, array{klasse: ?Klasse, grund: string}>
     */
    private function klassenNachKuerzel(Konto $konto): array
    {
        $sichtbar = [];
        foreach ($this->klassen->sichtbarFuer($konto) as $klasse) {
            $sichtbar[mb_strtolower($klasse->exportKuerzel)][] = $klasse;
        }

        $ergebnis = [];
        foreach ($sichtbar as $kuerzel => $liste) {
            // Zwei laufende Klassen mit demselben Kuerzel (altes und neues
            // Schuljahr, noch nicht archiviert): lieber nichts zuordnen als
            // das Falsche.
            $ergebnis[$kuerzel] = count($liste) === 1
                ? ['klasse' => $liste[0], 'grund' => '']
                : ['klasse' => null, 'grund' => 'mehrdeutig'];
        }

        $abfrage = $this->db->query('SELECT DISTINCT export_kuerzel FROM klassen WHERE archiviert_am IS NULL');
        foreach ($abfrage !== false ? ($abfrage->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $kuerzel) {
            $kuerzel = mb_strtolower((string) $kuerzel);
            $ergebnis[$kuerzel] ??= ['klasse' => null, 'grund' => 'nicht_zustaendig'];
        }

        return $ergebnis;
    }

    /**
     * @param list<string> $fingerabdruecke
     * @return array<string, true>
     */
    private function bekannteFingerabdruecke(array $fingerabdruecke): array
    {
        $bekannt = [];
        foreach (array_chunk(array_values(array_unique($fingerabdruecke)), 500) as $teil) {
            $abfrage = $this->db->prepare(
                'SELECT fingerabdruck FROM importzeilen WHERE fingerabdruck IN (' . implode(',', array_fill(0, count($teil), '?')) . ')'
            );
            $abfrage->execute($teil);
            foreach ($abfrage->fetchAll(PDO::FETCH_COLUMN) ?: [] as $f) {
                $bekannt[(string) $f] = true;
            }
        }

        return $bekannt;
    }

    /** @param list<string> $fingerabdruecke */
    private function einerSchonBekannt(array $fingerabdruecke): bool
    {
        return $this->bekannteFingerabdruecke($fingerabdruecke) !== [];
    }

    /**
     * Bekannte Vorfaelle mit denselben Schluesseln wie die neuen Zeilen.
     *
     * Ohne die Vorfaelle, die die Regel fuer neutrale Eintraege von selbst
     * ignoriert hat: an sie soll keine Zeile angehaengt werden.
     *
     * @param list<array{klasse: Klasse, zeile: Exportzeile, schluessel: string}> $kandidaten
     * @return list<array{id: int, schluessel: string, vorlage: ?string}>
     */
    private function bekannteVorfaelle(array $kandidaten): array
    {
        $gesucht = [];
        foreach ($kandidaten as $k) {
            $gesucht[$k['schluessel']] = [$k['klasse']->id, $k['zeile']->datum->format('Y-m-d'), $k['zeile']->fach];
        }

        $abfrage = $this->db->prepare(
            "SELECT id, klasse_id, datum, fach_kuerzel, lehrkraft_kennung, webuntis_kategorie, text_vorlage
               FROM vorfaelle
              WHERE klasse_id = ? AND datum = ? AND fach_kuerzel = ?
                AND NOT (status = 'ignoriert' AND entschieden_von IS NULL)"
        );

        $bekannte = [];
        $geladen = [];
        foreach ($gesucht as [$klasseId, $datum, $fach]) {
            $merker = $klasseId . "\x1F" . $datum . "\x1F" . $fach;
            if (isset($geladen[$merker])) {
                continue;
            }
            $geladen[$merker] = true;

            $abfrage->execute([$klasseId, $datum, $fach]);
            foreach ($abfrage->fetchAll() ?: [] as $zeile) {
                $schluessel = Gruppierer::schluessel(
                    (int) $zeile['klasse_id'],
                    (string) $zeile['datum'],
                    (string) $zeile['fach_kuerzel'],
                    (string) $zeile['lehrkraft_kennung'],
                    (string) $zeile['webuntis_kategorie']
                );

                if (!isset($gesucht[$schluessel])) {
                    continue;
                }

                $bekannte[] = [
                    'id' => (int) $zeile['id'],
                    'schluessel' => $schluessel,
                    'vorlage' => $this->tresor->oeffnenOderNull(
                        $zeile['text_vorlage'] !== null ? (string) $zeile['text_vorlage'] : null,
                        self::ZWECK_VORLAGE
                    ),
                ];
            }
        }

        return $bekannte;
    }

    /**
     * Ein bekannter Vorfall bekommt weitere Beteiligte.
     *
     * @param array{klasse: int, beteiligte: int, klasseneintrag: bool, namens_kennungen: list<string>} $zuordnung
     */
    private function ergaenzeVorfall(int $vorfallId, array $zuordnung): void
    {
        $abfrage = $this->db->prepare('SELECT namens_kennungen FROM vorfaelle WHERE id = ? AND klasse_id = ? FOR UPDATE');
        $abfrage->execute([$vorfallId, $zuordnung['klasse']]);
        $bisher = $abfrage->fetchColumn();

        if ($bisher === false) {
            throw new ImportFehler('Ein Vorfall aus der Vorschau existiert nicht mehr. Bitte die Datei erneut hochladen.');
        }

        $vorhanden = is_string($bisher) ? json_decode($bisher, true) : [];
        $kennungen = self::vereinige(is_array($vorhanden) ? array_values(array_filter($vorhanden, 'is_string')) : [], $zuordnung['namens_kennungen']);

        $this->db->prepare(
            'UPDATE vorfaelle
                SET anzahl_beteiligte = anzahl_beteiligte + ?, klasseneintrag = GREATEST(klasseneintrag, ?), namens_kennungen = ?
              WHERE id = ?'
        )->execute([
            $zuordnung['beteiligte'],
            $zuordnung['klasseneintrag'] ? 1 : 0,
            $kennungen !== [] ? json_encode($kennungen) : null,
            $vorfallId,
        ]);
    }

    /**
     * Die Loeschregel fuer Originaltexte: entschieden, und dieser Import - der
     * die Klasse enthielt - hat dem Vorfall nichts hinzugefuegt.
     *
     * @param list<int> $klassenIds
     * @param list<int> $beruehrt
     */
    private function loescheErledigteTexte(array $klassenIds, array $beruehrt, DateTimeImmutable $jetzt): int
    {
        if ($klassenIds === []) {
            return 0;
        }

        $parameter = [...$klassenIds, $jetzt->format('Y-m-d H:i:s'), ...$beruehrt];
        $ausnahme = $beruehrt !== [] ? 'AND id NOT IN (' . implode(',', array_fill(0, count($beruehrt), '?')) . ')' : '';

        $anweisung = $this->db->prepare(
            'UPDATE vorfaelle SET originaltext = NULL, text_vorlage = NULL
              WHERE klasse_id IN (' . implode(',', array_fill(0, count($klassenIds), '?')) . ")
                AND status IN ('freigegeben', 'ohne_text', 'ignoriert')
                AND entschieden_am IS NOT NULL AND entschieden_am <= ?
                AND (originaltext IS NOT NULL OR text_vorlage IS NOT NULL)
                $ausnahme"
        );
        $anweisung->execute($parameter);

        return $anweisung->rowCount();
    }

    // --- Hilfen ------------------------------------------------------------

    /** @throws ImportFehler */
    private function pruefeSchluessel(): void
    {
        if (!(new Schluesselpruefung($this->db))->stimmt($this->schluessel)) {
            throw new ImportFehler(
                'Der Schlüssel (KLASSENZIEL_SCHLUESSEL) passt nicht zu den gespeicherten Daten. '
                . 'Ein Import würde jeden Eintrag doppelt zählen und ist deshalb gesperrt. '
                . 'Bitte den ursprünglichen Schlüssel aus der Sicherung einsetzen.'
            );
        }
    }

    /**
     * Abgelaufene Vorschauen mitsamt ihrem Plan verwerfen. Gemessen an
     * derselben Uhr wie ihr Ablauf, nicht an NOW() der Datenbank.
     */
    private function verwirfAbgelaufene(DateTimeImmutable $jetzt): void
    {
        $this->db->prepare(
            "UPDATE importe SET status = 'verworfen', plan = NULL, laeuft_ab = NULL
              WHERE status = 'vorschau' AND laeuft_ab < ?"
        )->execute([$jetzt->format('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed>|null */
    private function planAus(string $verschlossen): ?array
    {
        $json = $this->tresor->oeffnenOderNull($verschlossen, self::ZWECK_PLAN);
        $plan = $json !== null ? json_decode($json, true) : null;

        return is_array($plan) ? $plan : null;
    }

    /**
     * Die Kurzform fuer die Vorschau: die Namen dieser Zeile durch den
     * Platzhalter ersetzt, auf eine Zeile gekuerzt.
     *
     * @param list<string> $teile
     */
    private function kurzform(Exportzeile $zeile, array $teile): string
    {
        $text = preg_replace('/\s+/u', ' ', Namensbestandteile::ersetze($zeile->text, $teile)) ?? '';

        return mb_strimwidth(trim($text), 0, self::KURZFORM, '…');
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function vereinige(array $a, array $b): array
    {
        $alle = array_values(array_unique([...$a, ...$b]));
        sort($alle);

        return $alle;
    }
}
