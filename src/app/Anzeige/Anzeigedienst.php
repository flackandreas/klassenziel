<?php

declare(strict_types=1);

namespace Klassenziel\Anzeige;

use DateTimeImmutable;
use Klassenziel\Klassen\Klasse;
use Klassenziel\Wertung\Einstellungen;
use Klassenziel\Wertung\Standdienst;
use PDO;

/**
 * Holt zusammen, was die oeffentliche Seite braucht: Punktestand (aus dem
 * Zwischenspeicher), Gewichtung und je Vorfall nur die oeffentlichen Angaben.
 */
final class Anzeigedienst
{
    public function __construct(
        private readonly PDO $verbindung,
        private readonly Standdienst $standdienst,
    ) {
    }

    /** @return array<string,mixed> */
    public function fuer(Klasse $klasse, DateTimeImmutable $heute, int $anzahl, int $versatz = 0): array
    {
        $wertung = $this->standdienst->wertung($klasse, $heute);

        return Anzeigedaten::baue(
            $klasse,
            $wertung,
            (new Einstellungen($this->verbindung))->gewichtung($klasse->id),
            $this->oeffentlicheAngaben($klasse->id, array_values(array_filter(
                array_column($wertung->ereignisse, 'id'),
                static fn ($id): bool => $id !== null
            ))),
            $heute,
            $anzahl,
            $versatz,
            $this->standdienst->ferien()
        );
    }

    /**
     * Status, Kurztext und Fach - und sonst nichts. Der Originaltext wird
     * hier gar nicht erst gelesen.
     *
     * @param list<int> $ids
     * @return array<int, array{status: string, kurztext: ?string, fach: string}>
     */
    private function oeffentlicheAngaben(int $klasseId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $angaben = [];
        foreach (array_chunk($ids, 500) as $teil) {
            $abfrage = $this->verbindung->prepare(
                'SELECT v.id, v.status, v.kurztext, v.fach_kuerzel, f.klartext
                   FROM vorfaelle v LEFT JOIN faecher f ON f.kuerzel = v.fach_kuerzel
                  WHERE v.klasse_id = ? AND v.id IN (' . implode(',', array_fill(0, count($teil), '?')) . ')'
            );
            $abfrage->execute([$klasseId, ...$teil]);

            foreach ($abfrage->fetchAll() ?: [] as $zeile) {
                $angaben[(int) $zeile['id']] = [
                    'status' => (string) $zeile['status'],
                    'kurztext' => $zeile['kurztext'] !== null ? (string) $zeile['kurztext'] : null,
                    'fach' => $zeile['klartext'] !== null && $zeile['klartext'] !== ''
                        ? (string) $zeile['klartext']
                        : (string) $zeile['fach_kuerzel'],
                ];
            }
        }

        return $angaben;
    }
}
