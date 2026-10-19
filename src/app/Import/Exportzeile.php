<?php

declare(strict_types=1);

namespace Klassenziel\Import;

use DateTimeImmutable;

/**
 * Eine Zeile aus dem WebUntis-Export - nur im Arbeitsspeicher.
 *
 * Name und Text tragen Schuelernamen, Benutzer das Kuerzel der Lehrkraft.
 * Keines davon wird so gespeichert: der Import macht daraus Fingerabdruck,
 * Kennungen und den verschluesselten Originaltext. __debugInfo sorgt dafuer,
 * dass auch ein var_dump() bei der Fehlersuche nichts davon ausgibt.
 */
final class Exportzeile
{
    public function __construct(
        public readonly int $zeilennummer,
        public readonly string $klasse,
        public readonly DateTimeImmutable $datum,
        public readonly string $fach,
        public readonly string $name,
        public readonly string $benutzer,
        public readonly string $kategorie,
        public readonly string $kategoriegruppe,
        public readonly string $text,
    ) {
    }

    /** Ein Eintrag fuer die ganze Klasse: in der Spalte Name steht das Klassenkuerzel. */
    public function istKlasseneintrag(): bool
    {
        return $this->name === '' || mb_strtolower($this->name) === mb_strtolower($this->klasse);
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'zeilennummer' => $this->zeilennummer,
            'klasse' => $this->klasse,
            'datum' => $this->datum->format('Y-m-d'),
            'fach' => $this->fach,
            'name' => '[verborgen]',
            'benutzer' => '[verborgen]',
            'kategorie' => $this->kategorie,
            'kategoriegruppe' => $this->kategoriegruppe,
            'text' => '[verborgen]',
        ];
    }
}
