<?php

declare(strict_types=1);

/**
 * Schickt den Browser zur Anmeldung ans SchulOS-Portal.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;
use SchulOS\Sso\SsoFehler;

/** @var Kern $kern */

Sitzung::starten();

if (!$kern->ssoAktiv()) {
    Kern::weiterleiten('/anmelden?abgemeldet=1');
}

if (Sitzung::angemeldet() && Sitzung::konto() !== null) {
    Kern::weiterleiten('/');
}

try {
    $ziel = $kern->anmeldung()->startAdresse(is_string($_GET['weiter'] ?? null) ? $_GET['weiter'] : '/');
} catch (SsoFehler $e) {
    error_log('Klassenziel: Anmeldung am Portal nicht startbar: ' . $e->getMessage());
    $kern->fehlerseite(
        'Portal nicht erreichbar',
        'Das SchulOS-Portal antwortet gerade nicht. Bitte in ein paar Minuten noch einmal versuchen.',
        503,
        null
    );
}

Kern::weiterleiten($ziel);
