<?php

declare(strict_types=1);

/**
 * Vorschau eines Imports, Bestaetigen oder Verwerfen - und danach das
 * Ergebnis.
 */

use Klassenziel\Import\ImportFehler;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$dienst = $kern->importdienst();
$id = (int) $kern->parameter('id');

$geladen = $dienst->lade($id, $konto);
if ($geladen === null) {
    Kern::nichtGefunden();
}

if (Kern::istPost()) {
    $kern->verlangeFormular();

    switch ((string) ($_POST['aktion'] ?? '')) {
        case 'bestaetigen':
            try {
                $ergebnis = $dienst->bestaetigen($id, $konto, new DateTimeImmutable());
                Sitzung::meldung('erfolg', sprintf(
                    'Eingelesen: %d %s, %d %s um weitere Beteiligte ergänzt.',
                    $ergebnis['vorfaelle_neu'],
                    $ergebnis['vorfaelle_neu'] === 1 ? 'neuer Vorfall' : 'neue Vorfälle',
                    $ergebnis['vorfaelle_zugeordnet'],
                    $ergebnis['vorfaelle_zugeordnet'] === 1 ? 'bekannter Vorfall' : 'bekannte Vorfälle'
                ));
            } catch (ImportFehler $e) {
                Sitzung::meldung('fehler', $e->getMessage());
            }
            Kern::weiterleiten('/import/' . $id);

        case 'verwerfen':
            $dienst->verwerfen($id, $konto);
            Sitzung::meldung('erfolg', 'Die Vorschau ist verworfen. Es wurde nichts eingelesen.');
            Kern::weiterleiten('/import');

        default:
            $kern->fehlerseite('Unbekannte Aktion', 'Diese Aktion gibt es nicht.', 400, '/import/' . $id);
    }
}

$kern->zeichne('import_vorschau.twig', [
    'konto' => $konto,
    'import' => $geladen['import'],
    'plan' => $geladen['plan'],
]);
