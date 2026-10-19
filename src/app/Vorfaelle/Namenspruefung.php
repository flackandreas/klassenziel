<?php

declare(strict_types=1);

namespace Klassenziel\Vorfaelle;

use Klassenziel\Import\Namensbestandteile;
use Klassenziel\Sicherheit\Schluessel;

/**
 * Enthaelt ein Kurztext einen Namen?
 *
 * Geprueft wird jedes Wort des Kurztexts gegen die Kennungen der
 * Namensbestandteile des Vorfalls und gegen die Namensliste der Klasse. Die
 * Namen selbst kennt Klassenziel dabei nicht - nur ihre Kennungen.
 *
 * Eine Hilfe, kein Garant: "Benni" statt "Ben" oder ein Name, der in keiner
 * Liste steht, faellt nicht auf. Deshalb fragt die Seite bei einem Treffer
 * nach, und ohne Treffer bleibt die Verantwortung bei der Lehrkraft.
 */
final class Namenspruefung
{
    /**
     * @param list<string> $kennungen
     * @return list<string> die Woerter des Kurztexts, die zu einem Namen passen
     */
    public static function treffer(Schluessel $schluessel, string $kurztext, array $kennungen): array
    {
        if ($kennungen === [] || trim($kurztext) === '') {
            return [];
        }

        $gesucht = array_flip($kennungen);
        $treffer = [];

        foreach (preg_split('/[^\p{L}\p{M}\'-]+/u', $kurztext) ?: [] as $wort) {
            $wort = trim($wort, "'-");

            // Das ganze Wort und seine Teile: "Anna-Lena" steht als Ganzes in
            // der Liste, "Beispiel-Kind" enthaelt einen Namen als Teil.
            foreach (array_unique([$wort, ...explode('-', $wort)]) as $kandidat) {
                if (mb_strlen($kandidat) >= 2 && isset($gesucht[Namensbestandteile::kennung($schluessel, $kandidat)])) {
                    $treffer[] = $kandidat;
                }
            }
        }

        return array_values(array_unique($treffer));
    }
}
