<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

use DateTimeImmutable;

/**
 * Der Punkteverlauf als Linie fuer die oeffentliche Seite - die Geometrie;
 * gezeichnet wird in _verlaufslinie.twig.
 *
 * Ein Punkt je Woche (Stand am Sonntag, zuletzt der heutige), gerade
 * verbunden. Die Zeitachse reicht vom Start bis zum Stichtag: Der leere
 * rechte Teil zeigt, wie viel Schuljahr noch bleibt. Ferien liegen als
 * helle Streifen dahinter und erklaeren, warum die Linie dort flach bleibt -
 * in Ferienwochen gibt es keinen Wochenbonus. Rechts neben der Flaeche
 * sitzen die Smileys der Etappen auf ihrer Hoehe, das letzte auf der
 * Ziellinie.
 *
 * Zwei Formate mit eigenem Seitenverhaeltnis und eigener Schriftgroesse:
 * Ein SVG skaliert seine Schrift mit, und auf dem Handy waere die
 * Beschriftung der breiten Beamer-Fassung zu klein.
 *
 * Vom Server gezeichnet wie die Simulation in der Verwaltung: Die
 * Content-Security-Policy erlaubt keine style-Attribute, und die Linie
 * steht auch ohne JavaScript.
 */
final class Verlaufsbild
{
    /** Masse in Einheiten der viewBox. */
    public const FORMATE = [
        'beamer' => ['breite' => 960, 'hoehe' => 230, 'links' => 34, 'rechts' => 112, 'oben' => 18, 'unten' => 30, 'schrift' => 15, 'wert' => 19, 'etappe' => 24],
        'handy' => ['breite' => 420, 'hoehe' => 250, 'links' => 30, 'rechts' => 100, 'oben' => 18, 'unten' => 28, 'schrift' => 14, 'wert' => 17, 'etappe' => 22],
    ];

    private const MONATE = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /** Grobe Breite eines Zeichens im Verhaeltnis zur Schriftgroesse - fuer Abstaende. */
    private const ZEICHENBREITE = 0.6;

    /**
     * @param array{start: string, ende: string, stichtag: ?string, punkte: list<array{datum: string, stand: int}>, ferien: list<array{von: string, bis: string}>} $verlauf
     * @param list<array{stufe: int, bei: int, erreicht: bool}> $etappen
     * @return array<string,mixed>
     */
    public static function fuer(array $verlauf, int $ziel, array $etappen, string $format): array
    {
        $f = self::FORMATE[$format] ?? self::FORMATE['handy'];

        $start = new DateTimeImmutable($verlauf['start']);
        $ende = new DateTimeImmutable($verlauf['ende']);
        $tage = max(1, self::tage($start, $ende));

        $links = $f['links'];
        $rechts = $f['breite'] - $f['rechts'];
        $oben = $f['oben'];
        $unten = $f['hoehe'] - $f['unten'];

        $x = static function (DateTimeImmutable $datum) use ($start, $tage, $links, $rechts): float {
            $anteil = min(1.0, max(0.0, self::tage($start, $datum) / $tage));

            return $links + $anteil * ($rechts - $links);
        };
        $y = static fn (int $stand): float => $oben + (100 - max(0, min(100, $stand))) * ($unten - $oben) / 100;

        // Raster: nur 0, 50 und 100 - die Ziellinie traegt die eigentliche Aussage.
        $raster = [];
        foreach ([0, 50, 100] as $wert) {
            $raster[] = ['y' => self::z($y($wert)), 'wert' => $wert];
        }

        // Ferien, auf den Zeitraum der Achse beschnitten. Ein Streifen endet
        // am Tag nach dem letzten Ferientag, damit er die ganze Woche deckt.
        // "Ferien" steht im ersten Streifen, in den das Wort passt - passt es
        // in keinen (Handy), im breitesten und ragt dann ueber ihn hinaus.
        $ferien = [];
        $ferientext = null;
        $breitester = null;
        $wort = self::breite('Ferien', $f['schrift'] - 2);
        foreach ($verlauf['ferien'] as $zeitraum) {
            $von = max($start, new DateTimeImmutable($zeitraum['von']));
            $bis = min($ende, (new DateTimeImmutable($zeitraum['bis']))->modify('+1 day'));
            if ($von >= $bis) {
                continue;
            }
            $breite = $x($bis) - $x($von);
            $ferien[] = ['x' => self::z($x($von)), 'breite' => self::z($breite)];
            if ($ferientext === null && $breite >= $wort + 8) {
                $ferientext = $x($von) + 4;
            }
            if ($x($von) + 4 + $wort <= $rechts && ($breitester === null || $breite > $breitester[1])) {
                $breitester = [$x($von) + 4, $breite];
            }
        }
        $ferientext ??= $breitester[0] ?? null;
        if ($ferientext !== null) {
            $ferientext = ['x' => self::z($ferientext), 'y' => self::z($oben + $f['schrift'])];
        }

        // Stichtag: senkrechte Haarlinie, Beschriftung rechtsbuendig darunter.
        $stichtag = null;
        $stichtagLinks = $rechts + 1;
        if ($verlauf['stichtag'] !== null) {
            $tag = new DateTimeImmutable($verlauf['stichtag']);
            if ($tag >= $start && $tag <= $ende) {
                $text = 'Stichtag ' . $tag->format('j.n.');
                $stichtag = ['x' => self::z($x($tag)), 'text' => $text];
                $stichtagLinks = $x($tag) - self::breite($text, $f['schrift']);
            }
        }

        // Monate: am Ersten jedes Monats, der angebrochene Startmonat am Start.
        // Was zu dicht kaeme oder in die Stichtag-Beschriftung liefe, entfaellt.
        $monate = [];
        $letzterRand = -INF;
        $monat = $start->modify('first day of this month');
        while ($monat <= $ende) {
            $amStart = $monat <= $start;
            $mx = $amStart ? $x($start) : $x($monat);
            $text = self::MONATE[(int) $monat->format('n') - 1];
            $halbe = self::breite($text, $f['schrift']) / 2;
            $linkerRand = $amStart ? $mx : $mx - $halbe;
            $rechterRand = $amStart ? $mx + 2 * $halbe : $mx + $halbe;
            if ($linkerRand >= $letzterRand + $f['schrift'] * 0.5 &&$rechterRand <= $stichtagLinks - 6 && $rechterRand <= $rechts + $halbe) {
                $monate[] = ['x' => self::z($mx), 'text' => $text, 'anker' => $amStart ? 'start' : 'middle'];
                $letzterRand = $rechterRand;
            }
            $monat = $monat->modify('first day of next month');
        }

        // Etappen rechts neben der Flaeche. Liegen sie eng (kleines Ziel),
        // werden die Smileys kleiner statt sich zu ueberdecken.
        $groesse = $f['etappe'];
        $hoehen = array_map(static fn (array $e): float => $y($e['bei']), $etappen);
        for ($i = 1; $i < count($hoehen); $i++) {
            $groesse = min($groesse, max(12, (int) floor(abs($hoehen[$i - 1] - $hoehen[$i])) - 2));
        }
        $mitte = $rechts + 8 + $groesse / 2;
        $etappenLage = [];
        foreach ($etappen as $i => $etappe) {
            $etappenLage[] = [
                'x' => self::z($mitte - $groesse / 2),
                'y' => self::z($hoehen[$i] - $groesse / 2),
                'groesse' => $groesse,
                'stufe' => $etappe['stufe'],
                'bei' => $etappe['bei'],
                'erreicht' => $etappe['erreicht'],
            ];
        }

        // Die Linie: ein Punkt je Woche, gerade verbunden.
        $punkte = [];
        foreach ($verlauf['punkte'] as $punkt) {
            $punkte[] = [self::z($x(new DateTimeImmutable($punkt['datum']))), self::z($y($punkt['stand']))];
        }
        $pfad = count($punkte) >= 2
            ? 'M' . implode(' L', array_map(static fn (array $p): string => $p[0] . ' ' . $p[1], $punkte))
            : null;

        // Der Stand am Linienende, darueber - oder darunter, wenn oben kein Platz
        // ist. Die Zahl darf in den oberen Rand ragen (Ziffern ~3/4 der Schriftgroesse).
        $endstand = null;
        if ($verlauf['punkte'] !== []) {
            $letzter = $verlauf['punkte'][count($verlauf['punkte']) - 1];
            $ex = $x(new DateTimeImmutable($letzter['datum']));
            $ey = $y($letzter['stand']);
            $textY = $ey - 10 - 0.75 * $f['wert'] < 2 ? $ey + 8 + $f['wert'] : $ey - 10;
            $halbe = self::breite((string) $letzter['stand'], $f['wert']) / 2;
            $textX = min($ex, $rechts - $halbe);
            $endstand = [
                'x' => self::z($ex),
                'y' => self::z($ey),
                'wert' => $letzter['stand'],
                'text_x' => self::z(max($links + $halbe, $textX)),
                'text_y' => self::z($textY),
            ];
        }

        return [
            'format' => $format,
            'breite' => $f['breite'],
            'hoehe' => $f['hoehe'],
            'schrift' => $f['schrift'],
            'wertschrift' => $f['wert'],
            'links' => $links,
            'rechts' => $rechts,
            'oben' => $oben,
            'unten' => $unten,
            'achse_y' => self::z($unten + $f['schrift'] + 4),
            'raster' => $raster,
            'ferien' => $ferien,
            'ferientext' => $ferientext,
            'monate' => $monate,
            'stichtag' => $stichtag,
            'ziel' => [
                'y' => self::z($y($ziel)),
                'text' => 'Ziel ' . $ziel,
                'text_x' => self::z($mitte + $groesse / 2 + 6),
            ],
            'etappen' => $etappenLage,
            'pfad' => $pfad,
            'ende' => $endstand,
            'beschreibung' => self::beschreibung($verlauf, $ziel),
            'tabelle' => array_map(static fn (array $p): array => [
                'datum' => (new DateTimeImmutable($p['datum']))->format('d.m.Y'),
                'stand' => $p['stand'],
            ], $verlauf['punkte']),
        ];
    }

    /**
     * Was die Linie zeigt, in einem Satz - fuer Screenreader.
     *
     * @param array{start: string, ende: string, stichtag: ?string, punkte: list<array{datum: string, stand: int}>, ferien: list<array{von: string, bis: string}>} $verlauf
     */
    public static function beschreibung(array $verlauf, int $ziel): string
    {
        $start = new DateTimeImmutable($verlauf['start']);
        $punkte = $verlauf['punkte'];
        $satz = 'Punkteverlauf seit ' . $start->format('d.m.Y');
        if ($punkte !== []) {
            $satz .= ': Start bei ' . $punkte[0]['stand'] . ', jetzt ' . $punkte[count($punkte) - 1]['stand'] . ' von 100 Punkten';
        }
        $satz .= ', Ziel ' . $ziel;
        if ($verlauf['stichtag'] !== null) {
            $satz .= ', Stichtag ' . (new DateTimeImmutable($verlauf['stichtag']))->format('d.m.Y');
        }

        return $satz . '.';
    }

    private static function tage(DateTimeImmutable $von, DateTimeImmutable $bis): int
    {
        return (int) $von->setTime(0, 0)->diff($bis->setTime(0, 0))->format('%r%a');
    }

    private static function breite(string $text, int $schrift): float
    {
        return mb_strlen($text) * $schrift * self::ZEICHENBREITE;
    }

    /** Auf eine Stelle gerundet, ohne Nullen am Ende - kurzes SVG, stabile Tests. */
    private static function z(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 1, '.', ''), '0'), '.');
    }
}
