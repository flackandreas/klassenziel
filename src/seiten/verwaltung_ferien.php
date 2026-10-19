<?php

declare(strict_types=1);

/**
 * Ferien der Schule. Wochen, die vollstaendig in Ferien liegen, bekommen
 * keinen Wochenbonus.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$stammdaten = $kern->stammdaten();
$fehler = null;
$eingabe = ['von' => '', 'bis' => '', 'bezeichnung' => ''];

if (Kern::istPost()) {
    $kern->verlangeFormular();

    if (($_POST['aktion'] ?? '') === 'entfernen') {
        $stammdaten->entferneFerien((int) ($_POST['id'] ?? 0));
        Sitzung::meldung('erfolg', 'Entfernt.');
        Kern::weiterleiten('/verwaltung/ferien');
    }

    foreach (array_keys($eingabe) as $feld) {
        $eingabe[$feld] = is_string($_POST[$feld] ?? null) ? $_POST[$feld] : '';
    }

    $fehler = $stammdaten->fuegeFerienHinzu($eingabe['von'], $eingabe['bis'], $eingabe['bezeichnung']);
    if ($fehler === null) {
        Sitzung::meldung('erfolg', 'Eingetragen. Der Wochenbonus aller Klassen ist neu berechnet.');
        Kern::weiterleiten('/verwaltung/ferien');
    }
}

$kern->zeichne('verwaltung_ferien.twig', [
    'konto' => $konto,
    'ferien' => $stammdaten->ferien(),
    'fehler' => $fehler,
    'eingabe' => $eingabe,
]);
