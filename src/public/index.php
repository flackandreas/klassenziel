<?php

declare(strict_types=1);

/**
 * Front Controller von Klassenziel.
 *
 * Alle Anfragen laufen hier durch; unter public/ liegen nur statische Dateien.
 * Code, Vorlagen und Ablage liegen darueber und sind ueber HTTP nicht
 * erreichbar.
 */

// Der eingebaute Server von PHP (Integrationstests) liefert vorhandene Dateien
// selbst aus, wenn der Router false zurueckgibt.
if (PHP_SAPI === 'cli-server') {
    $datei = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($datei !== __DIR__ . '/' && is_file($datei)) {
        return false;
    }
}

require_once __DIR__ . '/../bootstrap.php';

use Klassenziel\Support\Anfrage;
use Klassenziel\Support\Datenbank;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Migrator;
use Klassenziel\Support\Wegweiser;

// --- Schutzkoepfe ---------------------------------------------------------

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()');
header('X-Frame-Options: DENY');
header('Cross-Origin-Opener-Policy: same-origin');

if (Anfrage::istHttps()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Kein 'unsafe-inline', auch nicht bei style-src: die Vorlagen kommen ohne
// style- und on*-Attribute aus. Balken und Kurven sind SVG vom Server; das
// wenige JavaScript liegt in public/js/.
//
// form-action gilt fuer die ganze Kette der Weiterleitungen nach einem
// Formular. Ist die Sitzung abgelaufen, fuehrt sie ueber die Anmeldung am
// Portal - ohne das Portal hier bliebe der Browser stumm auf dem Formular
// stehen, und die Eingabe saehe gespeichert aus, obwohl sie es nicht ist.
$portal = Anfrage::ursprung((string) umgebung('PORTAL_ISSUER', ''));
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
    . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; "
    . "base-uri 'self'; form-action 'self'" . ($portal !== null ? ' ' . $portal : '') . "; frame-ancestors 'none'"
);

// --- Wegweiser ------------------------------------------------------------

$pfad = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$weg = Wegweiser::finde(is_string($pfad) ? $pfad : '/');

if ($weg === null) {
    Kern::nichtGefunden();
}

// --- Migrationen ----------------------------------------------------------
//
// Laufen beim Start des Containers (docker-entrypoint.sh). Hier nur als Netz,
// falls ein Update ohne Neustart eingespielt wurde.

if ($weg['seite'] !== 'healthz.php') {
    try {
        $migrator = new Migrator();
        if (!$migrator->istAktuell()) {
            if (umgebung_schalter('AUTO_MIGRATE', true)) {
                foreach ($migrator->einspielen(Datenbank::verbindung())['fehler'] as $fehler) {
                    error_log('Klassenziel: Migration: ' . $fehler);
                }
            } else {
                error_log('Klassenziel: Es stehen Migrationen aus, AUTO_MIGRATE ist aus.');
            }
        }
    } catch (\Throwable $e) {
        error_log('Klassenziel: Migrationslauf fehlgeschlagen: ' . $e->getMessage());
    }
}

// --- Seite ausfuehren -----------------------------------------------------

$kern = Kern::eine();
$kern->setzeParameter($weg['parameter']);

try {
    require __DIR__ . '/../seiten/' . $weg['seite'];
} catch (\Klassenziel\Sicherheit\SchluesselFehlt $e) {
    error_log('Klassenziel: ' . $e->getMessage());
    $kern->fehlerseite(
        'Noch nicht eingerichtet',
        'Klassenziel hat noch keinen Schlüssel (KLASSENZIEL_SCHLUESSEL). Bitte an die Administration wenden.',
        503,
        null
    );
} catch (\Throwable $e) {
    // Nur Art und Ort des Fehlers. Die Meldung einer Ausnahme kann Werte aus
    // der Verarbeitung tragen - und das hiesse hier: aus dem Klassenbuch.
    error_log(sprintf(
        'Klassenziel: unbehandelter Fehler in %s: %s in %s:%d',
        $weg['seite'],
        $e::class,
        basename($e->getFile()),
        $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head>'
        . '<body><h1>Ein Fehler ist aufgetreten</h1>'
        . '<p>Die Anfrage konnte nicht verarbeitet werden. Der Fehler steht im Protokoll des Servers.</p>'
        . '</body></html>';
}
