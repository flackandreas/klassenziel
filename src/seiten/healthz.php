<?php

declare(strict_types=1);

/**
 * Lebenszeichen fuer das Portal.
 *
 * Bewusst ohne Versionsangaben: der Endpunkt ist unangemeldet erreichbar und
 * soll nichts verraten, was bei der Suche nach Luecken hilft. Ob der Schluessel
 * fehlt, darf er sagen - das steht ohnehin beim Start im Protokoll, und ohne
 * Schluessel ist das Modul fuer niemanden benutzbar.
 */

use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\SchluesselFehlt;
use Klassenziel\Support\Datenbank;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    Datenbank::verbindung()->query('SELECT 1');
} catch (\Throwable $e) {
    error_log('Klassenziel: healthz meldet Datenbankproblem: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'datenbank']);
    exit;
}

try {
    Schluessel::ausUmgebung();
} catch (SchluesselFehlt) {
    http_response_code(503);
    echo json_encode(['status' => 'schluessel']);
    exit;
}

echo json_encode(['status' => 'ok', 'modul' => 'klassenziel']);
