<?php

declare(strict_types=1);

namespace Klassenziel\Konten;

/**
 * Eine Lehrkraft, wie Klassenziel sie kennt.
 *
 * Bewusst ohne Kuerzel: Klassenziel braucht es nicht, und so bleibt die Regel
 * ohne Ausnahme pruefbar, dass kein Lehrkraftkuerzel im Klartext in der
 * Datenbank steht. Verknuepft wird ueber die dauerhafte Kennung des Portals.
 */
final class Konto
{
    public function __construct(
        public readonly int $id,
        public readonly string $ssoSub,
        public readonly string $name,
        public readonly bool $istVerwaltung,
    ) {
    }

    /** @param array<string,mixed> $zeile */
    public static function ausZeile(array $zeile): self
    {
        return new self(
            id: (int) $zeile['id'],
            ssoSub: (string) $zeile['sso_sub'],
            name: (string) $zeile['name'],
            istVerwaltung: (bool) $zeile['ist_verwaltung'],
        );
    }
}
