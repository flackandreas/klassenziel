<?php

declare(strict_types=1);

namespace Klassenziel\Sicherheit;

use RuntimeException;

/** KLASSENZIEL_SCHLUESSEL fehlt oder taugt nicht. */
final class SchluesselFehlt extends RuntimeException
{
}
