<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

use DateTimeImmutable;

/**
 * Die Ferien der Schule, fuer den Wochenbonus.
 *
 * Eine Ferienwoche ist eine Woche, deren Montag bis Freitag vollstaendig in
 * Ferien liegen. Eine Woche mit zwei Ferientagen ist eine Schulwoche - sonst
 * verloere die Klasse den Bonus fuer jede Woche mit einem beweglichen Ferientag.
 */
final class Ferienkalender
{
    /** @var list<array{von: string, bis: string}> */
    private array $zeitraeume = [];

    /**
     * @param list<array{von: string, bis: string}> $zeitraeume Datumswerte Y-m-d
     */
    public function __construct(array $zeitraeume = [])
    {
        foreach ($zeitraeume as $zeitraum) {
            if ($zeitraum['von'] <= $zeitraum['bis']) {
                $this->zeitraeume[] = $zeitraum;
            }
        }
    }

    public function istFerientag(DateTimeImmutable $tag): bool
    {
        $datum = $tag->format('Y-m-d');
        foreach ($this->zeitraeume as $zeitraum) {
            if ($datum >= $zeitraum['von'] && $datum <= $zeitraum['bis']) {
                return true;
            }
        }

        return false;
    }

    public function istFerienwoche(DateTimeImmutable $montag): bool
    {
        for ($i = 0; $i < 5; $i++) {
            if (!$this->istFerientag($montag->modify('+' . $i . ' days'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Die Zeitraeume selbst - fuer die Ferienstreifen hinter der Linie auf der
     * oeffentlichen Seite. Ferientermine der Schule, nichts ueber die Klasse.
     *
     * @return list<array{von: string, bis: string}>
     */
    public function zeitraeume(): array
    {
        return $this->zeitraeume;
    }
}
