<?php

declare(strict_types=1);

/**
 * Anmeldung - ausschliesslich ueber das SchulOS-Portal.
 *
 * Klassenziel hat keine eigene Anmeldemaske und keine eigenen Passwoerter. Ist
 * das Portal eingerichtet, geht es ohne Zwischenschritt dorthin; sonst sagt
 * diese Seite, warum niemand hineinkommt.
 */

use Klassenziel\Support\Anfrage;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

Sitzung::starten();

$weiter = Anfrage::sicheresZiel(is_string($_GET['weiter'] ?? null) ? $_GET['weiter'] : '/');

if (Sitzung::angemeldet() && Sitzung::konto() !== null) {
    Kern::weiterleiten($weiter);
}

if ($kern->ssoAktiv() && !isset($_GET['abgemeldet'])) {
    Kern::weiterleiten('/sso/start?weiter=' . rawurlencode($weiter));
}

$kern->zeichne('anmelden.twig', [
    'sso_aktiv' => $kern->ssoAktiv(),
    'abgemeldet' => isset($_GET['abgemeldet']),
    'weiter' => $weiter,
]);
