<?php

declare(strict_types=1);

namespace Klassenziel\Sicherheit;

/**
 * Der geheime Teil des Links, ueber den Schuelerinnen, Schueler und Eltern die
 * Klassenseite ohne Anmeldung sehen.
 *
 * 24 Zufallsbyte, base64url: 32 Zeichen und 192 Bit. Verlangt sind mindestens
 * 128 Bit; der Rest kostet nichts und macht das Durchprobieren aussichtslos,
 * auch ohne die Ratenbremse.
 *
 * In der Datenbank steht der Link zweimal: verschluesselt, damit die Lehrkraft
 * ihn wieder anzeigen und kopieren kann, und als Kennung, ueber die eine
 * Anfrage ihre Klasse findet. Gesucht wird nur ueber die Kennung - der Klartext
 * taucht in keiner Abfrage auf.
 */
final class Zugangslink
{
    public const ZWECK_VERSCHLUESSELUNG = 'klassen.link_token';
    public const ZWECK_KENNUNG = 'link';

    private const BYTES = 24;
    private const MUSTER = '/^[A-Za-z0-9_-]{32}$/';

    public static function erzeugen(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    /**
     * Ein Link, der nicht einmal die Form hat, braucht keine Datenbankabfrage.
     */
    public static function formGueltig(string $token): bool
    {
        return preg_match(self::MUSTER, $token) === 1;
    }

    public static function kennung(Schluessel $schluessel, string $token): string
    {
        return $schluessel->kennung(self::ZWECK_KENNUNG, $token);
    }
}
