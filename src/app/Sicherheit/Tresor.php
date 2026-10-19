<?php

declare(strict_types=1);

namespace Klassenziel\Sicherheit;

/**
 * Verschluesselt einzelne Felder: den WebUntis-Originaltext, die Textvorlage
 * fuer die Gruppierung, den geheimen Link, den Importplan.
 *
 * XChaCha20-Poly1305 aus libsodium, das PHP von Haus aus mitbringt. Der Zweck
 * eines Felds geht als zusaetzliche Angabe in die Pruefsumme ein: ein Geheimtext
 * aus der Spalte "originaltext" laesst sich deshalb nicht in die Spalte des
 * Links kopieren und dort entschluesseln.
 */
final class Tresor
{
    private const PRAEFIX = 'v1:';

    public function __construct(private readonly Schluessel $schluessel)
    {
    }

    public function verschliessen(string $klartext, string $zweck): string
    {
        $einmalwert = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $geheim = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $klartext,
            $zweck,
            $einmalwert,
            $this->schluessel->verschluesselung()
        );

        return self::PRAEFIX . base64_encode($einmalwert . $geheim);
    }

    /**
     * @throws TresorFehler wenn der Wert beschaedigt ist, zu einem anderen
     *                      Zweck gehoert oder mit einem anderen Schluessel
     *                      verschluesselt wurde
     */
    public function oeffnen(string $verschlossen, string $zweck): string
    {
        if (!str_starts_with($verschlossen, self::PRAEFIX)) {
            throw new TresorFehler('Unbekanntes Format.');
        }

        $roh = base64_decode(substr($verschlossen, strlen(self::PRAEFIX)), true);
        $laenge = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($roh === false || strlen($roh) <= $laenge) {
            throw new TresorFehler('Beschaedigter Wert.');
        }

        $klartext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($roh, $laenge),
            $zweck,
            substr($roh, 0, $laenge),
            $this->schluessel->verschluesselung()
        );

        if ($klartext === false) {
            // Bewusst ohne Einzelheiten: die Meldung landet im Protokoll.
            throw new TresorFehler('Entschluesselung fehlgeschlagen.');
        }

        return $klartext;
    }

    /** Wie oeffnen(), liefert bei einem Fehler aber null statt einer Ausnahme. */
    public function oeffnenOderNull(?string $verschlossen, string $zweck): ?string
    {
        if ($verschlossen === null || $verschlossen === '') {
            return null;
        }

        try {
            return $this->oeffnen($verschlossen, $zweck);
        } catch (TresorFehler) {
            return null;
        }
    }
}
