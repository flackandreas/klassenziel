<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

/**
 * Ergebnis des Punkterechners.
 */
final class Wertung
{
    /**
     * @param list<array{art: string, id: ?int, datum: string, richtung: string, stufe: string, punkte: int, stand: int}> $ereignisse
     *        chronologisch, mit dem Stand nach jedem Schritt
     * @param list<array{datum: string, stand: int}> $verlauf Stand am Ende jeder Woche
     */
    public function __construct(
        public readonly int $stand,
        public readonly array $ereignisse,
        public readonly array $verlauf,
        public readonly int $wochenbilanz,
        public readonly int $wochePositiv,
        public readonly int $wocheNegativ,
    ) {
    }

    /** @return array<string,mixed> */
    public function alsFeld(): array
    {
        return [
            'stand' => $this->stand,
            'ereignisse' => $this->ereignisse,
            'verlauf' => $this->verlauf,
            'wochenbilanz' => $this->wochenbilanz,
            'woche_positiv' => $this->wochePositiv,
            'woche_negativ' => $this->wocheNegativ,
        ];
    }

    /** @param array<string,mixed> $feld */
    public static function ausFeld(array $feld): self
    {
        /** @var list<array{art: string, id: ?int, datum: string, richtung: string, stufe: string, punkte: int, stand: int}> $ereignisse */
        $ereignisse = $feld['ereignisse'];
        /** @var list<array{datum: string, stand: int}> $verlauf */
        $verlauf = $feld['verlauf'];

        return new self(
            (int) $feld['stand'],
            $ereignisse,
            $verlauf,
            (int) $feld['wochenbilanz'],
            (int) $feld['woche_positiv'],
            (int) $feld['woche_negativ'],
        );
    }
}
