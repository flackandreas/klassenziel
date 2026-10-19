<?php

declare(strict_types=1);

namespace Klassenziel\Support;

/**
 * Schema, Host und Absender-Adresse der Anfrage.
 *
 * Hinter dem Reverse-Proxy ist $_SERVER['HTTPS'] nicht gesetzt und der
 * Host-Header vom Client bestimmbar. Beides wird hier einmal richtig
 * ausgewertet, statt an jeder Stelle neu.
 *
 * Unveraendert aus dem Portal uebernommen (src/app/Support/Anfrage.php) -
 * eine Korrektur dort gehoert auch hierher.
 */
final class Anfrage
{
    /**
     * X-Forwarded-Proto darf nur hochstufen, nie herabstufen: ein gefaelschter
     * Kopf kann einer echten TLS-Verbindung nicht das Secure-Flag nehmen.
     */
    public static function istHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strcasecmp((string) $https, 'off') !== 0) {
            return true;
        }

        $weitergereicht = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($weitergereicht !== '' && strcasecmp(trim(explode(',', $weitergereicht)[0]), 'https') === 0) {
            return true;
        }

        return strcasecmp((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''), 'on') === 0;
    }

    /** APP_URL hat Vorrang; sonst der auf ein striktes Format geprueft Host-Kopf. */
    public static function host(): string
    {
        $eingestellt = (string) (umgebung('APP_URL') ?? '');
        if ($eingestellt !== '') {
            $host = parse_url($eingestellt, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $port = parse_url($eingestellt, PHP_URL_PORT);

                return $port ? $host . ':' . $port : $host;
            }
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]{1,253}(:[0-9]{1,5})?$/', $host)) {
            return $host;
        }

        return 'localhost';
    }

    /** Basisadresse ohne abschliessenden Schraegstrich. */
    public static function basis(): string
    {
        $eingestellt = (string) (umgebung('APP_URL') ?? '');
        if ($eingestellt !== '') {
            return rtrim($eingestellt, '/');
        }

        return (self::istHttps() ? 'https' : 'http') . '://' . self::host();
    }

    /**
     * Vertrauenswuerdige Vermittler aus TRUSTED_PROXIES.
     *
     * Einzeladressen oder CIDR-Bereiche, kommagetrennt. Das Wort "private"
     * steht fuer die privaten Netze und die Rueckschleife - im
     * Containerverbund, wo der Reverse Proxy im selben Bridge-Netz haengt,
     * ist das der Regelfall.
     *
     * @return list<string>
     */
    public static function vermittler(): array
    {
        $roh = trim((string) (umgebung('TRUSTED_PROXIES') ?? ''));
        if ($roh === '') {
            return [];
        }

        $bereiche = [];
        foreach (explode(',', $roh) as $eintrag) {
            $eintrag = trim($eintrag);
            if ($eintrag === '') {
                continue;
            }

            if (strcasecmp($eintrag, 'private') === 0) {
                array_push(
                    $bereiche,
                    '127.0.0.0/8',
                    '10.0.0.0/8',
                    '172.16.0.0/12',
                    '192.168.0.0/16',
                    '::1/128',
                    'fc00::/7'
                );
                continue;
            }

            $bereiche[] = $eintrag;
        }

        return $bereiche;
    }

    /** Liegt eine Adresse in einem Netz? Einzeladresse oder CIDR, v4 und v6. */
    public static function imBereich(string $adresse, string $bereich): bool
    {
        $binAdresse = @inet_pton($adresse);
        if ($binAdresse === false) {
            return false;
        }

        if (!str_contains($bereich, '/')) {
            return @inet_pton(trim($bereich)) === $binAdresse;
        }

        [$netz, $praefix] = explode('/', $bereich, 2);
        $binNetz = @inet_pton(trim($netz));

        if ($binNetz === false || strlen($binNetz) !== strlen($binAdresse)) {
            return false;
        }

        $bits = (int) $praefix;
        if ($bits < 0 || $bits > strlen($binAdresse) * 8) {
            return false;
        }

        $ganzeBytes = intdiv($bits, 8);
        $restBits = $bits % 8;

        if ($ganzeBytes > 0 && strncmp($binAdresse, $binNetz, $ganzeBytes) !== 0) {
            return false;
        }

        if ($restBits === 0) {
            return true;
        }

        $maske = chr((0xFF << (8 - $restBits)) & 0xFF);

        return ($binAdresse[$ganzeBytes] & $maske) === ($binNetz[$ganzeBytes] & $maske);
    }

    /**
     * @param list<string> $bereiche
     */
    public static function istVermittler(string $adresse, array $bereiche): bool
    {
        foreach ($bereiche as $bereich) {
            if (self::imBereich($adresse, $bereich)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adresse des Anfragenden.
     *
     * Hier stand: nimm den ersten Eintrag aus X-Forwarded-For, wenn es eine
     * gueltige Adresse ist. Diesen Kopf setzt aber der Aufrufer selbst. Wer
     * ihn bei jedem Versuch aendert, bekommt jedes Mal einen frischen
     * Zaehler - und damit war die Bremse vor der Anmeldung wirkungslos,
     * ausgerechnet an der Stelle, an der sich alle Module anmelden. Im
     * Protokoll stand ausserdem eine Adresse, die sich der Schreiber selbst
     * ausgesucht hat.
     *
     * X-Forwarded-For zaehlt jetzt nur, wenn die Verbindung von einem in
     * TRUSTED_PROXIES eingetragenen Vermittler kommt. Aus der Kette wird von
     * rechts nach links der erste Eintrag genommen, der nicht selbst ein
     * bekannter Vermittler ist; die linken Eintraege kann der Client frei
     * erfinden.
     */
    public static function absender(): string
    {
        $entfernt = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($entfernt === '') {
            return 'unbekannt';
        }

        $vermittler = self::vermittler();

        if ($vermittler === []) {
            if (($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') !== '') {
                error_log('Klassenziel: X-Forwarded-For wird ignoriert, weil TRUSTED_PROXIES nicht gesetzt ist.');
            }

            return $entfernt;
        }

        if (!self::istVermittler($entfernt, $vermittler)) {
            return $entfernt;
        }

        $kette = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));

        for ($i = count($kette) - 1; $i >= 0; $i--) {
            if (!filter_var($kette[$i], FILTER_VALIDATE_IP)) {
                continue;
            }

            if (!self::istVermittler($kette[$i], $vermittler)) {
                return $kette[$i];
            }
        }

        return $entfernt;
    }

    /**
     * Ein Ziel, das innerhalb des Moduls liegt. Alles andere wird zu "/".
     * Ohne diese Pruefung waere jeder "weiter"-Parameter eine offene
     * Weiterleitung auf eine fremde Anmeldeseite.
     */
    public static function sicheresZiel(?string $ziel): string
    {
        if ($ziel === null || $ziel === '' || $ziel[0] !== '/' || str_starts_with($ziel, '//')) {
            return '/';
        }

        if (str_contains($ziel, "\n") || str_contains($ziel, "\r")) {
            return '/';
        }

        return $ziel;
    }
}
