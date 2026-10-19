<?php

declare(strict_types=1);

/**
 * Textbausteine fuer neutrale Kurztexte.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$stammdaten = $kern->stammdaten();
$fehler = null;

if (Kern::istPost()) {
    $kern->verlangeFormular();

    if (($_POST['aktion'] ?? '') === 'entfernen') {
        $stammdaten->entferneTextbaustein((int) ($_POST['id'] ?? 0));
        Sitzung::meldung('erfolg', 'Entfernt.');
        Kern::weiterleiten('/verwaltung/textbausteine');
    }

    $fehler = $stammdaten->fuegeTextbausteinHinzu(
        is_string($_POST['richtung'] ?? null) ? $_POST['richtung'] : '',
        is_string($_POST['text'] ?? null) ? $_POST['text'] : ''
    );
    if ($fehler === null) {
        Sitzung::meldung('erfolg', 'Hinzugefügt.');
        Kern::weiterleiten('/verwaltung/textbausteine');
    }
}

$kern->zeichne('verwaltung_textbausteine.twig', [
    'konto' => $konto,
    'bausteine' => $stammdaten->textbausteine(),
    'fehler' => $fehler,
]);
