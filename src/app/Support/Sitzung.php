<?php

declare(strict_types=1);

namespace Klassenziel\Support;

use Klassenziel\Konten\Konten;
use Klassenziel\Konten\Konto;

/**
 * Die Browser-Sitzung der Lehrkraefte.
 *
 * Die oeffentlichen Seiten starten keine Sitzung und setzen kein Cookie: wer
 * den Link einer Klasse oeffnet, hinterlaesst hier nichts ausser einem Eintrag
 * in der Ratenbremse.
 *
 * SameSite=Lax wie im Portal: der Rueckweg vom Portal ist eine Navigation von
 * einer anderen Herkunft, und mit Strict kaeme die Sitzung dort ohne Cookie an.
 */
final class Sitzung
{
    private const NAME = 'klassenziel_sitzung';
    private const LEBENSDAUER = 8 * 3600;

    public static function starten(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => Anfrage::istHttps(),
        ]);
        session_start();

        if (isset($_SESSION['letzte_aktivitaet'])
            && time() - (int) $_SESSION['letzte_aktivitaet'] > self::LEBENSDAUER) {
            self::beenden();
            session_start();
        }

        $_SESSION['letzte_aktivitaet'] = time();
    }

    /**
     * Meldet an und erneuert dabei die Sitzungskennung, damit eine vorab
     * untergeschobene Kennung nach der Anmeldung nicht weitergilt.
     */
    public static function anmelden(Konto $konto, string $portalSitzung, string $idToken): void
    {
        self::starten();
        session_regenerate_id(true);

        $_SESSION['konto_id'] = $konto->id;
        // Ohne die Sitzungskennung des Portals liesse sich eine Abmeldung ueber
        // den Vorderkanal nicht dieser Sitzung zuordnen.
        $_SESSION['sso_sid'] = $portalSitzung;
        $_SESSION['sso_id_token'] = $idToken;
        $_SESSION['letzte_aktivitaet'] = time();
    }

    public static function angemeldet(): bool
    {
        return !empty($_SESSION['konto_id']);
    }

    /** Das angemeldete Konto, frisch aus der Datenbank. */
    public static function konto(): ?Konto
    {
        $id = $_SESSION['konto_id'] ?? null;
        if ($id === null) {
            return null;
        }

        return (new Konten(Datenbank::verbindung()))->nachId((int) $id);
    }

    public static function portalSitzung(): ?string
    {
        $sid = $_SESSION['sso_sid'] ?? null;

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    public static function idToken(): ?string
    {
        $token = $_SESSION['sso_id_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function beenden(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name() ?: self::NAME, '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }

        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::starten();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf'];
    }

    public static function csrfGueltig(mixed $token): bool
    {
        return is_string($token) && $token !== ''
            && !empty($_SESSION['csrf'])
            && hash_equals((string) $_SESSION['csrf'], $token);
    }

    public static function meldung(string $art, string $text): void
    {
        self::starten();
        $_SESSION['meldung_' . $art] = $text;
    }

    public static function holeMeldung(string $art): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $text = $_SESSION['meldung_' . $art] ?? null;
        unset($_SESSION['meldung_' . $art]);

        return is_string($text) ? $text : null;
    }
}
