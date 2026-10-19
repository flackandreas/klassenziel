<?php

declare(strict_types=1);

namespace Klassenziel\Sicherheit;

/**
 * Der Hauptschluessel und die daraus abgeleiteten Teilschluessel.
 *
 * Ein Schluessel je Zweck, alle ueber HKDF aus KLASSENZIEL_SCHLUESSEL: die
 * Verschluesselung der Originaltexte, die Kennungen (Fingerabdruecke der
 * Exportzeilen, Hashes der Lehrkraftkuerzel und Namensbestandteile) und der
 * Pruefwert. Wer einen Teilschluessel kennt, kennt damit keinen der anderen.
 *
 * Die Kennungen sind HMAC, kein einfacher Hash: Vornamen und Kuerzel sind so
 * wenige, dass sich ein ungesalzener Hash in Sekunden durchprobieren liesse.
 * Ohne den Schluessel geht das nicht.
 */
final class Schluessel
{
    private const MINDESTLAENGE = 32;

    private function __construct(private readonly string $haupt)
    {
    }

    /**
     * @throws SchluesselFehlt
     */
    public static function ausUmgebung(): self
    {
        $wert = umgebung('KLASSENZIEL_SCHLUESSEL');
        if ($wert === null) {
            throw new SchluesselFehlt('KLASSENZIEL_SCHLUESSEL ist nicht gesetzt.');
        }

        return self::ausWert($wert);
    }

    /**
     * @throws SchluesselFehlt
     */
    public static function ausWert(string $base64): self
    {
        $roh = base64_decode(trim($base64), true);

        if ($roh === false || strlen($roh) < self::MINDESTLAENGE) {
            throw new SchluesselFehlt(
                'KLASSENZIEL_SCHLUESSEL muss mindestens 32 Byte lang und base64-kodiert sein (openssl rand -base64 32).'
            );
        }

        return new self($roh);
    }

    /** 32 Byte fuer XChaCha20-Poly1305. */
    public function verschluesselung(): string
    {
        return $this->ableiten('verschluesselung');
    }

    /**
     * Pseudonyme Kennung eines Werts, als Hex.
     *
     * Der Zweck geht mit in die Berechnung ein. Dasselbe Wort als Name und als
     * Kuerzel ergibt deshalb zwei verschiedene Kennungen - aus der einen
     * Tabelle laesst sich nichts ueber die andere schliessen.
     */
    public function kennung(string $zweck, string $wert): string
    {
        return hash_hmac('sha256', $zweck . "\x1F" . $wert, $this->ableiten('kennung'));
    }

    /**
     * Ein fester Wert, an dem sich erkennen laesst, ob der Schluessel noch
     * derselbe ist wie beim letzten Import (siehe Schluesselpruefung).
     */
    public function pruefwert(): string
    {
        return hash_hmac('sha256', 'klassenziel/pruefwert', $this->ableiten('pruefwert'));
    }

    private function ableiten(string $zweck): string
    {
        return hash_hkdf('sha256', $this->haupt, 32, 'klassenziel/' . $zweck . '/v1');
    }

    /**
     * Ein var_dump() oder print_r() in einer Fehlersuche soll den Schluessel
     * nicht ins Protokoll schreiben.
     *
     * @return array<string,string>
     */
    public function __debugInfo(): array
    {
        return ['haupt' => '[verborgen]'];
    }
}
