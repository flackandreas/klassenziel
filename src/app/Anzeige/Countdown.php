<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

use DateTimeImmutable;

/**
 * Tage bis zum Stichtag - Kalendertage, nie negativ.
 *
 * "heute" kommt von aussen und ist ein Datum in Europe/Berlin (der Container
 * stellt PHP darauf). Wer um 23:30 Uhr auf die Seite schaut, soll denselben
 * Tag sehen wie die Klasse am Morgen.
 */
final class Countdown
{
    /**
     * @return array{zustand: 'offen'|'laeuft'|'heute'|'erreicht', tage: ?int, stichtag: ?string}
     */
    public static function fuer(?DateTimeImmutable $stichtag, DateTimeImmutable $heute): array
    {
        if ($stichtag === null) {
            return ['zustand' => 'offen', 'tage' => null, 'stichtag' => null];
        }

        $heute = $heute->setTime(0, 0);
        $stichtag = $stichtag->setTime(0, 0);
        $text = $stichtag->format('d.m.Y');

        if ($stichtag < $heute) {
            return ['zustand' => 'erreicht', 'tage' => 0, 'stichtag' => $text];
        }

        $tage = (int) $heute->diff($stichtag)->days;

        return ['zustand' => $tage === 0 ? 'heute' : 'laeuft', 'tage' => $tage, 'stichtag' => $text];
    }
}
