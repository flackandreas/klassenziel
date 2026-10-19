<?php

declare(strict_types=1);

/**
 * Rueckweg vom SchulOS-Portal: Code einloesen, Konto zuordnen, anmelden.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;
use SchulOS\Sso\SsoFehler;

/** @var Kern $kern */

Sitzung::starten();

if (!$kern->ssoAktiv()) {
    Kern::weiterleiten('/anmelden?abgemeldet=1');
}

$anmeldung = $kern->anmeldung();
$zurueck = $kern->portalAdresse() !== '' ? $kern->portalAdresse() : null;

try {
    $identitaet = $anmeldung->abschliessen($_GET);
} catch (SsoFehler $e) {
    error_log('Klassenziel: Anmeldung ueber das Portal gescheitert: ' . $e->getMessage());
    $kern->fehlerseite(
        'Anmeldung nicht abgeschlossen',
        'Die Anmeldung über das Portal konnte nicht abgeschlossen werden. Bitte erneut versuchen.',
        400,
        $zurueck
    );
}

$konto = $kern->konten()->anmelden(
    $identitaet,
    (string) umgebung('PORTAL_ZUGANG_GRUPPEN', ''),
    (string) umgebung('PORTAL_ADMIN_GRUPPEN', 'Schulleitung')
);

if ($konto === null) {
    $kern->fehlerseite(
        'Kein Zugang',
        'Für Ihr Konto ist Klassenziel nicht freigegeben. Die Schulleitung kann das im Portal ändern.',
        403,
        $zurueck
    );
}

Sitzung::anmelden($konto, $identitaet->sitzung, $anmeldung->letztesIdToken());

Kern::weiterleiten($anmeldung->zielNachAnmeldung());
