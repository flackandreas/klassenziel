<?php

declare(strict_types=1);

/**
 * Kommandozeile von Klassenziel.
 *
 *   php bin/klassenziel.php migrieren     Migrationen einspielen
 *   php bin/klassenziel.php status        Stand von Schema und Schluessel
 *   php bin/klassenziel.php schluessel    einen neuen Hauptschluessel ausgeben
 *   php bin/klassenziel.php aufraeumen    Ratenbremse und abgelaufene Vorschauen leeren
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur ueber die Kommandozeile aufrufbar.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Sicherheit\SchluesselFehlt;
use Klassenziel\Sicherheit\Schluesselpruefung;
use Klassenziel\Support\Datenbank;
use Klassenziel\Support\Migrator;
use Klassenziel\Support\Ratenbremse;

$befehl = $argv[1] ?? 'hilfe';

$sage = static function (string $text): void {
    fwrite(STDOUT, $text . PHP_EOL);
};
$schimpfe = static function (string $text): void {
    fwrite(STDERR, $text . PHP_EOL);
};

switch ($befehl) {
    case 'migrieren':
        try {
            $ergebnis = (new Migrator())->einspielen(Datenbank::verbindung());
        } catch (\Throwable $e) {
            $schimpfe('Klassenziel: Migration abgebrochen: ' . $e->getMessage());
            exit(1);
        }

        $sage(sprintf(
            'Klassenziel: Migrationen: %d eingespielt, %d bereits vorhanden',
            count($ergebnis['ausgefuehrt']),
            $ergebnis['uebersprungen']
        ));
        foreach ($ergebnis['fehler'] as $fehler) {
            $schimpfe('Klassenziel: FEHLER: ' . $fehler);
        }
        exit($ergebnis['fehler'] === [] ? 0 : 1);

    case 'status':
        $migrator = new Migrator();
        $sage('Schema:     ' . ($migrator->istAktuell() ? 'aktuell' : 'Migrationen stehen aus (oder Container neu gestartet)'));

        try {
            $schluessel = Schluessel::ausUmgebung();
            $stimmt = (new Schluesselpruefung(Datenbank::verbindung()))->stimmt($schluessel);
            $sage('Schluessel: ' . ($stimmt ? 'vorhanden und passend' : 'PASST NICHT zu den gespeicherten Daten - Import gesperrt'));
        } catch (SchluesselFehlt $e) {
            $sage('Schluessel: fehlt - ' . $e->getMessage());
        } catch (\Throwable $e) {
            $sage('Schluessel: nicht pruefbar - ' . $e->getMessage());
        }
        exit(0);

    case 'schluessel':
        // Nur ausgeben, nie selbst eintragen: der Schluessel gehoert in die
        // .env und in die Sicherung, und das entscheidet ein Mensch.
        $sage(base64_encode(random_bytes(32)));
        exit(0);

    case 'aufraeumen':
        $db = Datenbank::verbindung();
        (new Ratenbremse($db))->raeumeAuf();
        $geloescht = $db->exec(
            "UPDATE importe SET status = 'verworfen', plan = NULL
              WHERE status = 'vorschau' AND laeuft_ab < NOW()"
        );
        $sage('Klassenziel: aufgeraeumt, ' . (int) $geloescht . ' abgelaufene Vorschau(en) verworfen.');
        exit(0);

    default:
        $sage('Befehle: migrieren | status | schluessel | aufraeumen');
        exit($befehl === 'hilfe' ? 0 : 1);
}
