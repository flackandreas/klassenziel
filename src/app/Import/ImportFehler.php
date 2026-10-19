<?php

declare(strict_types=1);

namespace Klassenziel\Import;

use RuntimeException;

/**
 * Der Import geht nicht weiter. Die Meldung ist fuer die Lehrkraft gedacht und
 * enthaelt nie Inhalte aus der Datei - sie kann im Protokoll landen.
 */
final class ImportFehler extends RuntimeException
{
}
