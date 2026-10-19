<?php

declare(strict_types=1);

namespace Klassenziel\Sicherheit;

use RuntimeException;

/** Ein verschluesselter Wert liess sich nicht oeffnen. */
final class TresorFehler extends RuntimeException
{
}
