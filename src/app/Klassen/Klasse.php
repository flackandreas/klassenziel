<?php

declare(strict_types=1);

namespace Klassenziel\Klassen;

use DateTimeImmutable;

/** Eine Klasse mit ihrem Ziel. */
final class Klasse
{
    public function __construct(
        public readonly int $id,
        public readonly string $anzeigename,
        public readonly string $exportKuerzel,
        public readonly string $schuljahr,
        public readonly string $belohnung,
        public readonly DateTimeImmutable $startdatum,
        public readonly int $startwert,
        public readonly int $zielwert,
        public readonly ?DateTimeImmutable $stichtag,
        public readonly ?DateTimeImmutable $archiviertAm,
        public readonly int $datenVersion,
        public readonly bool $hatLink,
        /** Zeitpunkt des juengsten eingelesenen Exports mit dieser Klasse. */
        public readonly ?DateTimeImmutable $datenstand = null,
    ) {
    }

    /** @param array<string,mixed> $zeile */
    public static function ausZeile(array $zeile): self
    {
        return new self(
            id: (int) $zeile['id'],
            anzeigename: (string) $zeile['anzeigename'],
            exportKuerzel: (string) $zeile['export_kuerzel'],
            schuljahr: (string) $zeile['schuljahr'],
            belohnung: (string) $zeile['belohnung'],
            startdatum: new DateTimeImmutable((string) $zeile['startdatum']),
            startwert: (int) $zeile['startwert'],
            zielwert: (int) $zeile['zielwert'],
            stichtag: $zeile['stichtag'] !== null ? new DateTimeImmutable((string) $zeile['stichtag']) : null,
            archiviertAm: $zeile['archiviert_am'] !== null ? new DateTimeImmutable((string) $zeile['archiviert_am']) : null,
            datenVersion: (int) $zeile['daten_version'],
            hatLink: $zeile['link_token_kennung'] !== null,
            datenstand: ($zeile['datenstand'] ?? null) !== null ? new DateTimeImmutable((string) $zeile['datenstand']) : null,
        );
    }

    public function istArchiviert(): bool
    {
        return $this->archiviertAm !== null;
    }
}
