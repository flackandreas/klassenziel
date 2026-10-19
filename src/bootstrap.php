<?php

declare(strict_types=1);

/**
 * Gemeinsamer Einstieg: Autoloader und Umgebungsvariablen, genau einmal je
 * Anfrage. Vorbild ist src/bootstrap.php im Portal.
 */

require_once __DIR__ . '/vendor/autoload.php';

(static function (): void {
    static $geladen = false;
    if ($geladen) {
        return;
    }
    $geladen = true;

    if (!is_file(__DIR__ . '/.env')) {
        return;
    }

    try {
        Dotenv\Dotenv::createImmutable(__DIR__)->load();
    } catch (\Throwable $e) {
        // Im Container kommen die Werte aus der Umgebung, nicht aus der Datei.
        error_log('Klassenziel: .env konnte nicht geladen werden: ' . $e->getMessage());
    }
})();
