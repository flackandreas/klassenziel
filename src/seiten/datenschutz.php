<?php

declare(strict_types=1);

/**
 * Hinweise zum Datenschutz. Der Text ist ein Platzhalter; den Inhalt stimmt
 * die Schule mit der oder dem Datenschutzbeauftragten ab.
 */

use Klassenziel\Support\Kern;

/** @var Kern $kern */

header('X-Robots-Tag: noindex, nofollow');

$kern->zeichneOeffentlich('datenschutz.twig');
