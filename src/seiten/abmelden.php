<?php

declare(strict_types=1);

/**
 * Abmeldung.
 *
 * Auch am Portal: das meldet anschliessend jedes Modul ab, das in dieser
 * Sitzung betreten wurde. Auf dem Rechner im Lehrerzimmer bliebe man sonst in
 * den uebrigen Modulen angemeldet.
 */

use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

Sitzung::starten();

$abmeldeAdresse = null;

if ($kern->ssoAktiv() && Sitzung::portalSitzung() !== null) {
    try {
        $abmeldeAdresse = $kern->anmeldung()->abmeldeAdresse(
            Sitzung::idToken(),
            $kern->portalAdresse() . '/anmelden'
        );
    } catch (\Throwable $e) {
        // Portal nicht erreichbar: oertlich abmelden ist dann alles, was geht.
        error_log('Klassenziel: Abmeldeadresse des Portals nicht ermittelbar: ' . $e->getMessage());
    }
}

Sitzung::beenden();

Kern::weiterleiten($abmeldeAdresse ?? '/anmelden?abgemeldet=1');
