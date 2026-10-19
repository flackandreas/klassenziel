<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

/**
 * Wie viel ein Vorfall je Stufe zaehlt, und der Wochenbonus.
 *
 * Positive Vorfaelle addieren, negative ziehen ab - mit denselben
 * Stufenpunkten. Ein Wochenbonus von 0 schaltet ihn ab.
 */
final class Gewichtung
{
    public const HOECHSTWERT = 20;

    public function __construct(
        public readonly int $klein,
        public readonly int $mittel,
        public readonly int $gross,
        public readonly int $wochenbonus,
    ) {
    }

    /** @param array<string,mixed> $zeile */
    public static function ausZeile(array $zeile): self
    {
        return new self(
            (int) $zeile['punkte_klein'],
            (int) $zeile['punkte_mittel'],
            (int) $zeile['punkte_gross'],
            (int) $zeile['wochenbonus'],
        );
    }

    public static function vorgabe(): self
    {
        return new self(1, 3, 5, 3);
    }

    public function punkte(string $stufe): int
    {
        return match ($stufe) {
            'klein' => $this->klein,
            'mittel' => $this->mittel,
            'gross' => $this->gross,
            default => 0,
        };
    }

    /** Punkte mit Vorzeichen fuer einen Vorfall. */
    public function fuer(string $richtung, string $stufe): int
    {
        return match ($richtung) {
            'positiv' => $this->punkte($stufe),
            'negativ' => -$this->punkte($stufe),
            default => 0,
        };
    }

    public function gleich(self $andere): bool
    {
        return $this->klein === $andere->klein
            && $this->mittel === $andere->mittel
            && $this->gross === $andere->gross
            && $this->wochenbonus === $andere->wochenbonus;
    }

    /**
     * Aus dem Formular, mit Fehlern je Feld.
     *
     * @param array<string,mixed> $eingabe
     * @return array{0: ?self, 1: array<string,string>}
     */
    public static function ausEingabe(array $eingabe): array
    {
        $werte = [];
        $fehler = [];

        foreach (['klein', 'mittel', 'gross', 'wochenbonus'] as $feld) {
            $roh = is_string($eingabe[$feld] ?? null) ? trim($eingabe[$feld]) : '';
            if (preg_match('/^\d{1,2}$/', $roh) !== 1 || (int) $roh > self::HOECHSTWERT) {
                $fehler[$feld] = 'Eine ganze Zahl von 0 bis ' . self::HOECHSTWERT . '.';
                continue;
            }
            $werte[$feld] = (int) $roh;
        }

        if ($fehler !== []) {
            return [null, $fehler];
        }

        return [new self($werte['klein'], $werte['mittel'], $werte['gross'], $werte['wochenbonus']), []];
    }
}
