<?php

declare(strict_types=1);

/**
 * WebUntis-Export hochladen - und das Protokoll der bisherigen Importe.
 *
 * Die Datei wird nicht gespeichert. PHP legt sie fuer die Dauer der Anfrage
 * unter /tmp ab - im Container ein tmpfs, also im Arbeitsspeicher -, sie wird
 * gelesen und sofort geloescht. Was bleibt, ist die namensfreie Vorschau.
 */

use Klassenziel\Import\ImportFehler;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Sitzung;

/** @var Kern $kern */

$konto = $kern->verlangeAnmeldung();
$dienst = $kern->importdienst();
$fehler = null;

$hoechstgroesse = 2 * 1024 * 1024;

// Ist die Anfrage groesser als post_max_size (3 MB), verwirft PHP das ganze
// Formular - auch das Token, und die Lehrkraft saehe nur "Formular
// abgelaufen". Deshalb vorher die ehrliche Meldung.
if (Kern::istPost() && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    Sitzung::meldung('fehler', 'Die Datei ist zu groß. Ein Export über ein ganzes Schuljahr hat wenige hundert Kilobyte.');
    Kern::weiterleiten('/import');
}

if (Kern::istPost()) {
    $kern->verlangeFormular();

    $datei = $_FILES['export'] ?? null;
    $tmp = is_array($datei) && is_string($datei['tmp_name'] ?? null) ? $datei['tmp_name'] : '';

    try {
        if (!is_array($datei) || ($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ImportFehler('Bitte eine Datei auswählen.');
        }
        if ($datei['error'] !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new ImportFehler('Die Datei ist nicht vollständig angekommen. Bitte noch einmal versuchen.');
        }
        if ((int) $datei['size'] > $hoechstgroesse) {
            throw new ImportFehler('Die Datei ist zu groß. Ein Export über ein ganzes Schuljahr hat wenige hundert Kilobyte.');
        }

        $inhalt = (string) file_get_contents($tmp);
        @unlink($tmp);

        $id = $dienst->vorschau($inhalt, (string) ($datei['name'] ?? ''), $konto, new DateTimeImmutable());
        unset($inhalt);

        Kern::weiterleiten('/import/' . $id);
    } catch (ImportFehler $e) {
        $fehler = $e->getMessage();
    } finally {
        if ($tmp !== '' && is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

$kern->zeichne('import.twig', [
    'konto' => $konto,
    'fehler' => $fehler,
    'klassen' => $kern->klassen()->sichtbarFuer($konto),
    'protokoll' => $dienst->protokoll($konto),
]);
