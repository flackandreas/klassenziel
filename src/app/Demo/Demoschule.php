<?php

declare(strict_types=1);

namespace Klassenziel\Demo;

use DateInterval;
use DateTimeImmutable;
use Klassenziel\Klassen\Klassendaten;
use Klassenziel\Konten\Konto;
use Klassenziel\Support\Kern;
use RuntimeException;

/**
 * Legt die Demo-Schule der offenen SchulOS-Demo an (demo.schulos.de).
 *
 * Grundlage ist welt.json aus schulos/deploy/demo-seed: dieselben erfundenen
 * Lehrkraefte und Kinder wie in allen anderen Modulen. Daraus entstehen:
 *
 *  - Konten mit dem `sub` des Portals, damit die Rollenwahl dort sofort die
 *    richtigen Klassen zeigt,
 *  - die Ferien der Zeitachse,
 *  - die Klassen aus module.klassenziel mit ihrer Klassenleitung,
 *  - je Klasse ein Klassenbuch-Export seit Schuljahresbeginn, eingelesen
 *    ueber denselben Importdienst wie ein echter WebUntis-Export.
 *
 * Alle Daten haengen am Tag des Aufrufs: Der naechtliche Reset legt die
 * Schule jeden Tag neu an, und die Demo steht immer gleich weit im Schuljahr.
 * Der Zufall ist fest verdrahtet (je Klasse und Tag), deshalb sieht der
 * Verlauf jeden Morgen gleich aus.
 */
final class Demoschule
{
    /** Belohnung, Zielwert und Temperament je Klasse. */
    private const KLASSEN = [
        '7b' => ['belohnung' => 'Klassenausflug in den Kletterpark', 'ziel' => 85, 'unruhe' => 0.5, 'lob' => 1.7],
        '8a' => ['belohnung' => 'Grillfest am Baggersee', 'ziel' => 84, 'unruhe' => 0.35, 'lob' => 1.6],
        '6b' => ['belohnung' => 'Kinonachmittag in der Aula', 'ziel' => 85, 'unruhe' => 1.45, 'lob' => 0.85],
        '10c' => ['belohnung' => 'Abschlussfrühstück im Schulgarten', 'ziel' => 85, 'unruhe' => 0.8, 'lob' => 1.1],
    ];

    private const TEXTE = [
        'klein_kind' => [
            '{V} stört wiederholt den Unterricht.',
            '{V} hat keine Hausaufgaben.',
            '{V} hat kein Material dabei.',
            '{V} kommt zu spät aus der Pause.',
            '{V} isst im Unterricht.',
            '{V} redet trotz Ermahnung weiter.',
        ],
        'klein_klasse' => [
            'Unruhige Arbeitsphase, Ermahnung der ganzen Klasse.',
            'Klassenraum nach der Pause unordentlich verlassen.',
            'Viele ohne Hausaufgaben.',
        ],
        'gross_kind' => [
            '{V} verlässt ohne Erlaubnis den Raum.',
            '{V} beleidigt einen Mitschüler.',
            '{V} beschädigt Material der Schule.',
        ],
        'lob_klasse' => [
            'Ruhige, konzentrierte Arbeitsphase.',
            'Alle Hausaufgaben vollständig.',
            'Klassenraum vorbildlich aufgeräumt.',
            'Pünktlich und vollständig umgezogen.',
            'Gruppenarbeit sehr gut organisiert.',
            'Vokabeltest: die ganze Klasse war vorbereitet.',
        ],
        'gross_lob' => [
            'Lesewettbewerb gemeinsam organisiert.',
            'Lerngang ohne einen einzigen Zwischenfall.',
            'Hervorragende Präsentationen in allen Gruppen.',
        ],
        'info' => [
            'Fluchtwege besprochen.',
            'Elternbrief ausgeteilt.',
            'Klassenregeln gemeinsam wiederholt.',
        ],
    ];

    public function __construct(private readonly Kern $kern)
    {
    }

    /**
     * @param array<string,mixed> $welt
     * @return list<string> Meldungen fuer die Kommandozeile
     */
    public function anlegen(array $welt, DateTimeImmutable $heute): array
    {
        if (($welt['format'] ?? null) !== 1) {
            throw new RuntimeException('welt.json hat ein unbekanntes Format.');
        }

        $db = $this->kern->db();
        if ((int) $db->query('SELECT COUNT(*) FROM klassen')->fetchColumn() > 0) {
            throw new RuntimeException('Es gibt schon Klassen. Die Demo legt nur in einer leeren Datenbank an.');
        }

        // Zwischengespeicherte Punktestaende gehoeren zu den alten Klassen-Ids
        // und waeren am selben Tag noch gueltig - also weg damit.
        $ablage = (string) umgebung('KLASSENZIEL_ABLAGE', dirname(__DIR__, 2) . '/storage');
        foreach (glob($ablage . '/cache/stand/*.json') ?: [] as $datei) {
            @unlink($datei);
        }

        $heute = $heute->setTime(0, 0);
        $meldungen = [];
        $personen = [];
        foreach ($welt['personen'] as $p) {
            $personen[$p['kuerzel']] = $p;
        }

        $einstellungen = $welt['module']['klassenziel'];
        $start = self::tag($heute, (int) $einstellungen['verlauf_ab']['tage']);
        $ferien = $this->legeFerienAn($welt['zeitachse'] ?? [], $heute);
        $meldungen[] = sprintf('Ferien: %d Zeiträume', count($ferien));

        foreach ($einstellungen['klassen'] as $name) {
            $klasse = self::finde($welt['klassen'], $name);
            $vorgabe = self::KLASSEN[$name] ?? ['belohnung' => 'Ausflug', 'ziel' => 85, 'unruhe' => 1.0, 'lob' => 1.0];
            $leitung = $this->konto($personen[$klasse['klassenleitung']]);

            // Alle, die in der Klasse unterrichten, bekommen ein Konto - so
            // sieht jede Rolle im Portal dieselbe Schule.
            $lehrkraefte = self::lehrkraefte($welt['unterricht'], $name);
            foreach (array_keys($lehrkraefte) as $kuerzel) {
                $this->konto($personen[$kuerzel]);
            }

            $daten = Klassendaten::ausEingabe([
                'anzeigename' => $name,
                'export_kuerzel' => $name,
                'schuljahr' => (string) $welt['schule']['schuljahr'],
                'belohnung' => $vorgabe['belohnung'],
                'startdatum' => $start->format('Y-m-d'),
                'startwert' => '50',
                'zielwert' => (string) $vorgabe['ziel'],
                'stichtag' => self::tag($heute, 250)->format('Y-m-d'),
            ]);
            if (!$daten->istGueltig()) {
                throw new RuntimeException('Klasse ' . $name . ': ' . implode(' ', $daten->fehler));
            }
            $id = $this->kern->klassen()->anlegen($daten, $leitung);

            $kinder = array_values(array_filter($welt['kinder'], static fn (array $k): bool => $k['klasse'] === $name));
            $zeilen = $this->verlauf($name, $start, $heute, $ferien, $kinder, $lehrkraefte, $vorgabe);
            $tsv = self::alsExport($zeilen);
            $jetzt = $heute->setTime(6, 0);
            $dateiname = 'ClassregEventPerKlasse_' . $heute->format('Ymd') . '_0600.csv';

            $import = $this->kern->importdienst()->vorschau($tsv, $dateiname, $leitung, $jetzt);
            $this->kern->importdienst()->bestaetigen($import, $leitung, $jetzt);

            $meldungen[] = sprintf('Klasse %s (Id %d): %d Einträge, Klassenleitung %s', $name, $id, count($zeilen), $leitung->name);
        }

        return $meldungen;
    }

    /** @param array<string,mixed> $person */
    private function konto(array $person): Konto
    {
        $istVerwaltung = in_array('Schulleitung', $person['gruppen'] ?? [], true);
        $this->kern->db()->prepare(
            'INSERT INTO konten (sso_sub, name, ist_verwaltung, zuletzt_am)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), ist_verwaltung = VALUES(ist_verwaltung)'
        )->execute([$person['sub'], $person['name'], $istVerwaltung ? 1 : 0]);

        $konto = $this->kern->konten()->nachSub((string) $person['sub']);
        if ($konto === null) {
            throw new RuntimeException('Konto für ' . $person['kuerzel'] . ' liess sich nicht anlegen.');
        }

        return $konto;
    }

    /**
     * @param list<array<string,mixed>> $zeitachse
     * @return list<array{0:DateTimeImmutable,1:DateTimeImmutable}>
     */
    private function legeFerienAn(array $zeitachse, DateTimeImmutable $heute): array
    {
        $ferien = [];
        foreach ($zeitachse as $eintrag) {
            if (($eintrag['art'] ?? '') !== 'ferien') {
                continue;
            }
            $von = self::tag($heute, (int) $eintrag['von']['tage']);
            $bis = self::tag($heute, (int) $eintrag['bis']['tage']);
            $fehler = $this->kern->stammdaten()->fuegeFerienHinzu($von->format('Y-m-d'), $bis->format('Y-m-d'), (string) $eintrag['name']);
            if ($fehler !== null) {
                throw new RuntimeException('Ferien „' . $eintrag['name'] . '“: ' . $fehler);
            }
            $ferien[] = [$von, $bis];
        }

        return $ferien;
    }

    /**
     * Ein erfundener Klassenbuch-Verlauf von $start bis gestern.
     *
     * @param list<array{0:DateTimeImmutable,1:DateTimeImmutable}> $ferien
     * @param list<array<string,mixed>> $kinder
     * @param array<string, list<string>> $lehrkraefte Kuerzel => Faecher
     * @param array{unruhe: float, lob: float} $vorgabe
     * @return list<list<string>>
     */
    private function verlauf(string $klasse, DateTimeImmutable $start, DateTimeImmutable $heute, array $ferien, array $kinder, array $lehrkraefte, array $vorgabe): array
    {
        $zeilen = [];
        $kuerzel = array_keys($lehrkraefte);
        $einTag = new DateInterval('P1D');

        for ($tag = $start; $tag < $heute; $tag = $tag->add($einTag)) {
            if ((int) $tag->format('N') >= 6 || self::inFerien($tag, $ferien)) {
                continue;
            }
            // Fest verdrahteter Zufall: je Klasse und Abstand zu heute.
            $abstand = (int) $heute->diff($tag)->days;
            $zufall = new Zufall($klasse . '|' . $abstand);

            $chancen = [
                'klein_kind' => 0.55 * $vorgabe['unruhe'],
                'klein_klasse' => 0.06 * $vorgabe['unruhe'],
                'gross_kind' => 0.035 * $vorgabe['unruhe'],
                'lob_klasse' => 0.22 * $vorgabe['lob'],
                'gross_lob' => 0.02 * $vorgabe['lob'],
                'info' => 0.08,
            ];
            foreach ($chancen as $art => $chance) {
                // Mehr als ein kleiner Eintrag am Tag ist moeglich.
                $anzahl = $art === 'klein_kind' ? ($zufall->zahl() < $chance ? 1 : 0) + ($zufall->zahl() < $chance / 3 ? 1 : 0)
                    : ($zufall->zahl() < $chance ? 1 : 0);
                for ($i = 0; $i < $anzahl; $i++) {
                    $lehrer = $kuerzel[$zufall->index(count($kuerzel))];
                    $faecher = $lehrkraefte[$lehrer];
                    $fach = $faecher[$zufall->index(count($faecher))];
                    $kind = $kinder[$zufall->index(count($kinder))];
                    $zeilen[] = self::zeile($klasse, $tag, $fach, $lehrer, $art, $kind, $zufall);
                }
            }
        }

        return $zeilen;
    }

    /**
     * @param array<string,mixed> $kind
     * @return list<string>
     */
    private static function zeile(string $klasse, DateTimeImmutable $tag, string $fach, string $lehrer, string $art, array $kind, Zufall $zufall): array
    {
        $texte = self::TEXTE[$art];
        $text = str_replace('{V}', (string) $kind['vorname'], $texte[$zufall->index(count($texte))]);
        $zuKind = in_array($art, ['klein_kind', 'gross_kind'], true);
        [$kategorie, $gruppe] = match ($art) {
            'klein_kind' => ['klein/Schüler*in', 'negativ'],
            'klein_klasse' => ['klein/Klasse', 'negativ'],
            'gross_kind' => ['groß/Schüler*in', 'negativ'],
            'lob_klasse' => ['Lob/Klasse', 'positiv'],
            'gross_lob' => ['groß/Lob', 'positiv'],
            default => ['Info/Klasse', 'Inf. / neutral'],
        };

        return [
            $klasse,
            $tag->format('d.m.y'),
            $fach,
            $zuKind ? $kind['nachname'] . ' ' . $kind['vorname'] : $klasse,
            $lehrer,
            $kategorie,
            $gruppe,
            $text . ' (' . $lehrer . ')',
        ];
    }

    /** @param list<list<string>> $zeilen */
    private static function alsExport(array $zeilen): string
    {
        $kopf = ['Klasse', 'Datum', 'Fach', 'Name', 'Benutzer', 'Eintragskategorie', 'Kategoriegruppe', 'Text'];
        $text = implode("\t", $kopf) . "\n";
        foreach ($zeilen as $zeile) {
            $text .= implode("\t", $zeile) . "\n";
        }

        return $text;
    }

    /**
     * Lehrkraefte der Klasse mit ihren Faechern dort. Gekoppelter Unterricht
     * (Religion, Wahlpflicht) steht in der a-Klasse und nennt die anderen in
     * "angekoppelt".
     *
     * @param list<array<string,mixed>> $unterricht
     * @return array<string, list<string>>
     */
    private static function lehrkraefte(array $unterricht, string $klasse): array
    {
        $ergebnis = [];
        foreach ($unterricht as $u) {
            if ($u['klasse'] === $klasse || in_array($klasse, $u['angekoppelt'] ?? [], true)) {
                $ergebnis[$u['kuerzel']][] = (string) $u['fach'];
            }
        }
        if ($ergebnis === []) {
            throw new RuntimeException('Für die Klasse ' . $klasse . ' steht kein Unterricht in welt.json.');
        }
        ksort($ergebnis);

        return array_map(static fn (array $f): array => array_values(array_unique($f)), $ergebnis);
    }

    /**
     * @param list<array<string,mixed>> $klassen
     * @return array<string,mixed>
     */
    private static function finde(array $klassen, string $name): array
    {
        foreach ($klassen as $klasse) {
            if ($klasse['name'] === $name) {
                return $klasse;
            }
        }
        throw new RuntimeException('Die Klasse ' . $name . ' fehlt in welt.json.');
    }

    /** @param list<array{0:DateTimeImmutable,1:DateTimeImmutable}> $ferien */
    private static function inFerien(DateTimeImmutable $tag, array $ferien): bool
    {
        foreach ($ferien as [$von, $bis]) {
            if ($tag >= $von && $tag <= $bis) {
                return true;
            }
        }

        return false;
    }

    private static function tag(DateTimeImmutable $heute, int $tage): DateTimeImmutable
    {
        return $heute->modify(($tage >= 0 ? '+' : '') . $tage . ' days');
    }
}
