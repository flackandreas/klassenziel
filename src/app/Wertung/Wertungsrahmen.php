<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use DateTimeImmutable;
use Klassenziel\Klassen\Klasse;

/**
 * Was eine Wertung ausser den Vorfaellen braucht: Start, Stichtag,
 * Datenstand und den heutigen Tag.
 *
 * "heute" ist ein Parameter und nicht die Uhr: so laesst sich jede Wertung
 * nachrechnen - in Tests und in der Simulation.
 */
final class Wertungsrahmen
{
    public readonly DateTimeImmutable $startdatum;
    public readonly ?DateTimeImmutable $stichtag;
    public readonly ?DateTimeImmutable $datenstand;
    public readonly DateTimeImmutable $heute;

    public function __construct(
        public readonly int $startwert,
        DateTimeImmutable $startdatum,
        ?DateTimeImmutable $stichtag,
        ?DateTimeImmutable $datenstand,
        DateTimeImmutable $heute,
    ) {
        $this->startdatum = $startdatum->setTime(0, 0);
        $this->stichtag = $stichtag?->setTime(0, 0);
        // Der Datenstand behaelt seine Uhrzeit: ein Export vom Montag, 13:31,
        // deckt den Sonntag davor vollstaendig ab.
        $this->datenstand = $datenstand;
        $this->heute = $heute->setTime(0, 0);
    }

    public static function fuer(Klasse $klasse, DateTimeImmutable $heute): self
    {
        return new self($klasse->startwert, $klasse->startdatum, $klasse->stichtag, $klasse->datenstand, $heute);
    }

    /** Letzter Tag, der zaehlt: heute, aber nie nach dem Stichtag. */
    public function ende(): DateTimeImmutable
    {
        return $this->stichtag !== null && $this->stichtag < $this->heute ? $this->stichtag : $this->heute;
    }

    public static function montag(DateTimeImmutable $tag): DateTimeImmutable
    {
        return $tag->setTime(0, 0)->modify('-' . ((int) $tag->format('N') - 1) . ' days');
    }

    public static function sonntag(DateTimeImmutable $tag): DateTimeImmutable
    {
        return self::montag($tag)->modify('+6 days');
    }
}
