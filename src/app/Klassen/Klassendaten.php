<?php

declare(strict_types=1);

namespace Klassenziel\Klassen;

use DateTimeImmutable;

/**
 * Die Angaben aus dem Formular einer Klasse, geprueft.
 *
 * Fehler werden je Feld gesammelt, damit das Formular alle auf einmal zeigen
 * kann - statt einer Meldung je Absenden.
 */
final class Klassendaten
{
    /** @var array<string,string> */
    public array $fehler = [];

    public string $anzeigename = '';
    public string $exportKuerzel = '';
    public string $schuljahr = '';
    public string $belohnung = '';
    public string $startdatum = '';
    public string $startwert = '50';
    public string $zielwert = '70';
    public string $stichtag = '';

    /** Vorgaben fuer eine neue Klasse. */
    public static function neu(DateTimeImmutable $heute): self
    {
        $daten = new self();
        $daten->schuljahr = self::schuljahrZu($heute);
        $daten->startdatum = $heute->format('Y-m-d');

        return $daten;
    }

    public static function ausKlasse(Klasse $klasse): self
    {
        $daten = new self();
        $daten->anzeigename = $klasse->anzeigename;
        $daten->exportKuerzel = $klasse->exportKuerzel;
        $daten->schuljahr = $klasse->schuljahr;
        $daten->belohnung = $klasse->belohnung;
        $daten->startdatum = $klasse->startdatum->format('Y-m-d');
        $daten->startwert = (string) $klasse->startwert;
        $daten->zielwert = (string) $klasse->zielwert;
        $daten->stichtag = $klasse->stichtag?->format('Y-m-d') ?? '';

        return $daten;
    }

    /** @param array<string,mixed> $eingabe ueblicherweise $_POST */
    public static function ausEingabe(array $eingabe): self
    {
        $lies = static fn (string $feld): string => is_string($eingabe[$feld] ?? null) ? trim($eingabe[$feld]) : '';

        $daten = new self();
        $daten->anzeigename = $lies('anzeigename');
        $daten->exportKuerzel = $lies('export_kuerzel');
        $daten->schuljahr = $lies('schuljahr');
        $daten->belohnung = $lies('belohnung');
        $daten->startdatum = $lies('startdatum');
        $daten->startwert = $lies('startwert');
        $daten->zielwert = $lies('zielwert');
        $daten->stichtag = $lies('stichtag');
        $daten->pruefe();

        return $daten;
    }

    public function istGueltig(): bool
    {
        return $this->fehler === [];
    }

    /**
     * Das Schuljahr, in dem ein Tag liegt. Es beginnt hier am 1. August, wie
     * in Baden-Wuerttemberg.
     */
    public static function schuljahrZu(DateTimeImmutable $tag): string
    {
        $jahr = (int) $tag->format('Y');
        $beginn = (int) $tag->format('n') >= 8 ? $jahr : $jahr - 1;

        return sprintf('%d/%02d', $beginn, ($beginn + 1) % 100);
    }

    public function startdatumAlsDatum(): DateTimeImmutable
    {
        return self::datum($this->startdatum) ?? new DateTimeImmutable('today');
    }

    public function stichtagAlsDatum(): ?DateTimeImmutable
    {
        return self::datum($this->stichtag);
    }

    private function pruefe(): void
    {
        if ($this->anzeigename === '' || mb_strlen($this->anzeigename) > 100) {
            $this->fehler['anzeigename'] = 'Bitte einen Namen mit höchstens 100 Zeichen angeben, z. B. „Klasse 9d“.';
        }

        // Genau so, wie es in der Spalte "Klasse" des Exports steht.
        if (preg_match('/^[A-Za-z0-9._-]{1,20}$/', $this->exportKuerzel) !== 1) {
            $this->fehler['export_kuerzel'] = 'Das Kürzel genau wie im WebUntis-Export angeben, z. B. „R9d“ (Buchstaben, Ziffern, Punkt, Bindestrich).';
        }

        if (preg_match('/^(\d{4})\/(\d{2})$/', $this->schuljahr, $teile) !== 1
            || ((int) $teile[1] + 1) % 100 !== (int) $teile[2]) {
            $this->fehler['schuljahr'] = 'Das Schuljahr in der Form „2026/27“ angeben.';
        }

        if ($this->belohnung === '' || mb_strlen($this->belohnung) > 200) {
            $this->fehler['belohnung'] = 'Bitte die Belohnung angeben, höchstens 200 Zeichen.';
        }

        $start = self::datum($this->startdatum);
        if ($start === null) {
            $this->fehler['startdatum'] = 'Bitte ein gültiges Startdatum angeben.';
        }

        if ($this->stichtag !== '') {
            $stichtag = self::datum($this->stichtag);
            if ($stichtag === null) {
                $this->fehler['stichtag'] = 'Bitte einen gültigen Stichtag angeben.';
            } elseif ($start !== null && $stichtag <= $start) {
                $this->fehler['stichtag'] = 'Der Stichtag muss nach dem Startdatum liegen.';
            }
        }

        foreach (['startwert' => 'Startwert', 'zielwert' => 'Zielwert'] as $feld => $bezeichnung) {
            $wert = $this->{$feld};
            if (preg_match('/^\d{1,3}$/', $wert) !== 1 || (int) $wert > 100) {
                $this->fehler[$feld] = $bezeichnung . ': eine ganze Zahl von 0 bis 100.';
            }
        }

        if (!isset($this->fehler['zielwert']) && (int) $this->zielwert === 0) {
            $this->fehler['zielwert'] = 'Ein Ziel von 0 Punkten wäre schon zu Beginn erreicht.';
        }
    }

    private static function datum(string $wert): ?DateTimeImmutable
    {
        $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

        return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
    }
}
