<?php

declare(strict_types=1);

namespace Klassenziel\Import;

use DateTimeImmutable;

/**
 * Liest den WebUntis-Export "ClassregEventPerKlasse".
 *
 * Am echten Export geprueft: tabulatorgetrennt trotz der Endung .csv, UTF-8
 * ohne BOM, eine Kopfzeile, Felder teils in doppelten Anfuehrungszeichen mit
 * "" fuer ein Anfuehrungszeichen, Datum dd.mm.yy, am Ende eine Leerzeile.
 * Toleriert wird mehr, falls WebUntis das Format aendert: Semikolon und
 * Komma als Trennzeichen, ein BOM, Windows-Zeilenenden, Zeilenumbrueche in
 * einem Feld, die Kodierung aus Excel.
 *
 * Fehlermeldungen nennen Zeilennummern, nie Inhalte.
 */
final class ExportParser
{
    /** Spalte im Export => Schluessel hier. Alle sind Pflicht. */
    private const SPALTEN = [
        'klasse' => 'klasse',
        'datum' => 'datum',
        'fach' => 'fach',
        'name' => 'name',
        'benutzer' => 'benutzer',
        'eintragskategorie' => 'kategorie',
        'kategoriegruppe' => 'kategoriegruppe',
        'text' => 'text',
    ];

    /**
     * @return array{zeilen: list<Exportzeile>, unlesbar: list<int>, trennzeichen: string}
     *
     * @throws ImportFehler wenn die Datei als Ganzes nicht passt
     */
    public static function lies(string $inhalt): array
    {
        $inhalt = self::alsUtf8($inhalt);

        if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
            $inhalt = substr($inhalt, 3);
        }

        $inhalt = str_replace(["\r\n", "\r"], "\n", $inhalt);

        if (trim($inhalt) === '') {
            throw new ImportFehler('Die Datei ist leer.');
        }

        $trennzeichen = self::trennzeichen($inhalt);
        $saetze = self::zerlege($inhalt, $trennzeichen);

        $kopf = array_shift($saetze);
        if ($kopf === null) {
            throw new ImportFehler('Die Datei hat keine Kopfzeile.');
        }
        $spalten = self::spalten($kopf['felder']);

        $zeilen = [];
        $unlesbar = [];

        foreach ($saetze as $satz) {
            $felder = $satz['felder'];

            // Leerzeilen, etwa am Dateiende.
            if (implode('', array_map('trim', $felder)) === '') {
                continue;
            }

            $werte = [];
            foreach ($spalten as $schluessel => $index) {
                $werte[$schluessel] = Normalisierung::feld($felder[$index] ?? '');
            }

            $datum = self::datum($werte['datum']);
            if ($datum === null || $werte['klasse'] === '') {
                $unlesbar[] = $satz['zeile'];
                continue;
            }

            $zeilen[] = new Exportzeile(
                zeilennummer: $satz['zeile'],
                klasse: $werte['klasse'],
                datum: $datum,
                fach: $werte['fach'],
                name: $werte['name'],
                benutzer: $werte['benutzer'],
                kategorie: $werte['kategorie'],
                kategoriegruppe: $werte['kategoriegruppe'],
                text: $werte['text'],
            );
        }

        return ['zeilen' => $zeilen, 'unlesbar' => $unlesbar, 'trennzeichen' => $trennzeichen];
    }

    /**
     * Datum aus dd.mm.yy, wie im Export, oder dd.mm.yyyy.
     */
    public static function datum(string $wert): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $wert, $teile) !== 1) {
            return null;
        }

        $tag = (int) $teile[1];
        $monat = (int) $teile[2];
        $jahr = (int) $teile[3];
        if ($jahr < 100) {
            $jahr += 2000;
        }

        if (!checkdate($monat, $tag, $jahr)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
    }

    /**
     * Aus Excel kommt eine CSV-Datei gern in Windows-1252. Alles, was kein
     * gueltiges UTF-8 ist, wird so gelesen - sonst wuerden aus Umlauten
     * Fragezeichen, und der Fingerabdruck stimmte beim naechsten Mal nicht.
     */
    private static function alsUtf8(string $inhalt): string
    {
        if (mb_check_encoding($inhalt, 'UTF-8')) {
            return $inhalt;
        }

        return mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    }

    /**
     * Das Trennzeichen aus der Kopfzeile: das haeufigste von Tabulator,
     * Semikolon und Komma. Bei Gleichstand gewinnt der Tabulator - so sieht
     * der echte Export aus.
     */
    private static function trennzeichen(string $inhalt): string
    {
        $kopf = strstr($inhalt, "\n", true);
        $kopf = $kopf === false ? $inhalt : $kopf;

        $beste = '';
        $anzahl = 0;
        foreach (["\t", ';', ','] as $kandidat) {
            $n = substr_count($kopf, $kandidat);
            if ($n > $anzahl) {
                $beste = $kandidat;
                $anzahl = $n;
            }
        }

        if ($beste === '') {
            throw new ImportFehler('In der Kopfzeile ist kein Trennzeichen zu erkennen (Tabulator, Semikolon oder Komma).');
        }

        return $beste;
    }

    /**
     * Zerlegt die Datei in Datensaetze. Ein Datensatz kann ueber mehrere
     * Zeilen gehen, wenn ein Feld in Anfuehrungszeichen einen Zeilenumbruch
     * enthaelt - str_getcsv() je Zeile wuerde ihn dort zerreissen.
     *
     * @return list<array{zeile: int, felder: list<string>}>
     */
    private static function zerlege(string $inhalt, string $trennzeichen): array
    {
        $saetze = [];
        $felder = [];
        $feld = '';
        $inAnfuehrung = false;
        $zeile = 1;
        $beginn = 1;
        $laenge = strlen($inhalt);

        for ($i = 0; $i < $laenge; $i++) {
            $zeichen = $inhalt[$i];

            if ($inAnfuehrung) {
                if ($zeichen === '"') {
                    if ($i + 1 < $laenge && $inhalt[$i + 1] === '"') {
                        $feld .= '"';
                        $i++;
                    } else {
                        $inAnfuehrung = false;
                    }
                    continue;
                }

                if ($zeichen === "\n") {
                    $zeile++;
                }
                $feld .= $zeichen;
                continue;
            }

            if ($zeichen === '"' && trim($feld) === '') {
                $feld = '';
                $inAnfuehrung = true;
                continue;
            }

            if ($zeichen === $trennzeichen) {
                $felder[] = $feld;
                $feld = '';
                continue;
            }

            if ($zeichen === "\n") {
                $felder[] = $feld;
                $saetze[] = ['zeile' => $beginn, 'felder' => $felder];
                $felder = [];
                $feld = '';
                $zeile++;
                $beginn = $zeile;
                continue;
            }

            $feld .= $zeichen;
        }

        if ($feld !== '' || $felder !== []) {
            $felder[] = $feld;
            $saetze[] = ['zeile' => $beginn, 'felder' => $felder];
        }

        return $saetze;
    }

    /**
     * @param list<string> $kopf
     * @return array<string,int> Schluessel => Spaltennummer
     */
    private static function spalten(array $kopf): array
    {
        $gefunden = [];
        foreach ($kopf as $index => $bezeichnung) {
            $name = mb_strtolower(Normalisierung::feld($bezeichnung));
            if (isset(self::SPALTEN[$name]) && !isset($gefunden[self::SPALTEN[$name]])) {
                $gefunden[self::SPALTEN[$name]] = $index;
            }
        }

        $fehlen = [];
        foreach (self::SPALTEN as $spalte => $schluessel) {
            if (!isset($gefunden[$schluessel])) {
                $fehlen[] = ucfirst($spalte);
            }
        }

        if ($fehlen !== []) {
            throw new ImportFehler(
                'Das sieht nicht nach dem WebUntis-Export „ClassregEventPerKlasse“ aus. Es fehlen die Spalten: '
                . implode(', ', $fehlen) . '.'
            );
        }

        return $gefunden;
    }
}
