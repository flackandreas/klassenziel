<?php

declare(strict_types=1);

/**
 * Gemeinsamer Start der Tests.
 *
 * Feste Zeitzone wie im Betrieb und ein eigener Schluessel nur fuer die Tests -
 * nie der aus der Umgebung, damit kein Test mit dem Schluessel einer echten
 * Installation laeuft.
 */

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Berlin');

const TEST_SCHLUESSEL = 'dGVzdHNjaGx1ZXNzZWwtbnVyLWZ1ZXItZGllLXRlc3RzIQ==';

putenv('KLASSENZIEL_SCHLUESSEL=' . TEST_SCHLUESSEL);
$_ENV['KLASSENZIEL_SCHLUESSEL'] = TEST_SCHLUESSEL;
