<?php

declare(strict_types=1);

namespace Klassenziel\Klassen;

use RuntimeException;

/** Fuer dieses Exportkuerzel gibt es im selben Schuljahr schon eine Klasse. */
final class KlasseExistiert extends RuntimeException
{
    public function __construct(public readonly string $exportKuerzel, public readonly string $schuljahr)
    {
        parent::__construct('Klasse existiert bereits.');
    }
}
