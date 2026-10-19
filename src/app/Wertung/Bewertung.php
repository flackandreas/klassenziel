<?php

declare(strict_types=1);

namespace Klassenziel\Wertung;

/**
 * Die wirksame Bewertung eines Vorfalls - und ob er zaehlt.
 *
 * Hat die Lehrkraft den Vorfall eingestuft, gilt ihre Einstufung. Sonst
 * ergibt sie sich bei jeder Berechnung neu aus der Kategorie-Zuordnung; so
 * wirkt eine geaenderte Zuordnung auch rueckwirkend.
 *
 * Es zaehlen: offen (mit bekannter Kategorie), freigegeben, ohne Text. Es
 * zaehlen nicht: ignoriert, "Zuordnung pruefen" (bis entschieden ist, damit
 * nichts doppelt zaehlt), neutral, und alles ohne Einstufung.
 */
final class Bewertung
{
    private const ZAEHLENDE_STATUS = ['offen', 'freigegeben', 'ohne_text'];

    /**
     * @param array<string,mixed> $vorfall eine Zeile aus vorfaelle
     * @return array{richtung: string, stufe: ?string, zaehlt: bool}
     */
    public static function wirksam(array $vorfall, Kategoriezuordnung $zuordnung): array
    {
        if ((int) ($vorfall['manuell_bewertet'] ?? 0) === 1 && $vorfall['richtung'] !== null) {
            $richtung = (string) $vorfall['richtung'];
            $stufe = $vorfall['stufe'] !== null ? (string) $vorfall['stufe'] : null;
        } else {
            $ergebnis = $zuordnung->fuer(
                (int) $vorfall['klasse_id'],
                (string) $vorfall['webuntis_kategorie'],
                (string) $vorfall['kategoriegruppe']
            );
            $richtung = $ergebnis['richtung'];
            $stufe = $ergebnis['stufe'];
        }

        $zaehlt = in_array((string) $vorfall['status'], self::ZAEHLENDE_STATUS, true)
            && in_array($richtung, ['positiv', 'negativ'], true)
            && in_array($stufe, ['klein', 'mittel', 'gross'], true);

        return ['richtung' => $richtung, 'stufe' => $stufe, 'zaehlt' => $zaehlt];
    }

    /**
     * Die oeffentliche Bezeichnung, wenn es keinen freigegebenen Kurztext gibt:
     * "Kleiner negativer Eintrag", "Großer positiver Eintrag".
     */
    public static function bezeichnung(string $richtung, ?string $stufe): string
    {
        if ($richtung === 'neutral') {
            return 'Neutraler Eintrag';
        }

        if (!in_array($richtung, ['positiv', 'negativ'], true) || $stufe === null) {
            return 'Noch nicht eingestuft';
        }

        $groesse = ['klein' => 'Kleiner', 'mittel' => 'Mittlerer', 'gross' => 'Großer'][$stufe] ?? '';

        return trim($groesse . ' ' . $richtung . 'er Eintrag');
    }

    public static function stufenname(?string $stufe): string
    {
        return ['klein' => 'klein', 'mittel' => 'mittel', 'gross' => 'groß'][$stufe ?? ''] ?? '';
    }
}
