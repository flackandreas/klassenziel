<?php

declare(strict_types=1);

/**
 * Abmeldung ueber den Vorderkanal (OpenID Connect Front-Channel Logout 1.0).
 *
 * Das Portal laedt diese Adresse beim Abmelden in einem unsichtbaren Rahmen.
 * Geprueft wird, dass Aussteller und Sitzungskennung zu dieser Sitzung
 * gehoeren - sonst koennte jede fremde Seite die Lehrkraft abmelden.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

// Der Rahmen kommt vom Portal; X-Frame-Options: DENY aus dem Front Controller
// wuerde ihn blockieren. Erlaubt wird ausschliesslich das Portal.
header_remove('X-Frame-Options');
$portal = $kern->portalAdresse();
header("Content-Security-Policy: default-src 'none'; frame-ancestors " . ($portal !== '' ? $portal : "'none'"));
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

if (!$kern->ssoAktiv()) {
    http_response_code(204);
    exit;
}

Sitzung::starten();

if ($kern->anmeldung()->pruefeVorderkanalAbmeldung($_GET, Sitzung::portalSitzung())) {
    Sitzung::beenden();
}

// Immer 204: ob hier eine Sitzung bestand, geht den Aufrufer nichts an.
http_response_code(204);
exit;
