<?php

declare(strict_types=1);

/**
 * Startseite des Lehrerbereichs: die eigenen Klassen.
 */

use Klassenziel\Support\Kern;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$mitArchiv = isset($_GET['archiv']);

$kern->zeichne('start.twig', [
    'konto' => $konto,
    'klassen' => $kern->klassen()->sichtbarFuer($konto, $mitArchiv),
    'mit_archiv' => $mitArchiv,
]);
