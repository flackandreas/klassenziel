<?php

declare(strict_types=1);

namespace Klassenziel\Support;

use Klassenziel\Import\Importdienst;
use Klassenziel\Klassen\KlassenRepository;
use Klassenziel\Konten\Konten;
use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Schluessel;
use PDO;
use SchulOS\Sso\Anmeldung;
use SchulOS\Sso\Konfiguration;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Haelt die Dienste des Moduls zusammen, wie der Kern im Portal.
 *
 * Bewusst schlank: die Seiten sollen nicht jede Verdrahtung wiederholen, aber
 * es soll auch niemand raten muessen, woher ein Dienst kommt.
 */
final class Kern
{
    private static ?self $eine = null;

    private ?Environment $twig = null;
    private ?Schluessel $schluessel = null;
    private ?Konfiguration $sso = null;

    /** @var array<string,string> Benannte Teile des Pfads, vom Wegweiser */
    private array $parameter = [];

    private function __construct(
        public readonly string $wurzel,
        public readonly string $ablage,
    ) {
    }

    public static function eine(): self
    {
        if (self::$eine !== null) {
            return self::$eine;
        }

        $wurzel = dirname(__DIR__, 2);

        return self::$eine = new self($wurzel, $wurzel . '/storage');
    }

    /** Nur fuer Tests. */
    public static function zuruecksetzen(): void
    {
        self::$eine = null;
    }

    public function db(): PDO
    {
        return Datenbank::verbindung();
    }

    /**
     * @throws \Klassenziel\Sicherheit\SchluesselFehlt
     */
    public function schluessel(): Schluessel
    {
        return $this->schluessel ??= Schluessel::ausUmgebung();
    }

    public function klassen(): KlassenRepository
    {
        return new KlassenRepository($this->db(), $this->schluessel());
    }

    public function konten(): Konten
    {
        return new Konten($this->db());
    }

    public function importdienst(): Importdienst
    {
        return new Importdienst($this->db(), $this->schluessel(), $this->klassen());
    }

    public function ratenbremse(): Ratenbremse
    {
        return new Ratenbremse($this->db());
    }

    // --- Portal -----------------------------------------------------------

    public function sso(): Konfiguration
    {
        if ($this->sso !== null) {
            return $this->sso;
        }

        $ablage = $this->ablage . '/cache/sso';
        if (!is_dir($ablage)) {
            @mkdir($ablage, 0o750, true);
        }

        return $this->sso = Konfiguration::ausUmgebung('PORTAL_', is_writable($ablage) ? $ablage : '');
    }

    /** Laeuft das Modul am Portal? Ohne Portal gibt es keine Anmeldung. */
    public function ssoAktiv(): bool
    {
        $fehlt = $this->sso()->fehlendeAngaben();
        if ($fehlt !== []) {
            // Der gefaehrlichere Fall: das Modul meint, es sei am Portal, und
            // scheitert erst beim Anmelden.
            error_log('Klassenziel: Portal-Anbindung unvollstaendig, es fehlt: PORTAL_' . implode(', PORTAL_', $fehlt));
        }

        return $this->sso()->aktiv();
    }

    public function anmeldung(): Anmeldung
    {
        return new Anmeldung($this->sso());
    }

    public function portalAdresse(): string
    {
        return rtrim((string) umgebung('PORTAL_ISSUER', ''), '/');
    }

    // --- Wegweiser ---------------------------------------------------------

    /** @param array<string,string> $parameter */
    public function setzeParameter(array $parameter): void
    {
        $this->parameter = $parameter;
    }

    public function parameter(string $name): string
    {
        return $this->parameter[$name] ?? '';
    }

    // --- Zugang ------------------------------------------------------------

    /**
     * Das angemeldete Konto, oder weiter zur Anmeldung.
     *
     * Fuehrt der Weg zur Anmeldung, merkt sich die Sitzung das Ziel - nach der
     * Anmeldung geht es dorthin zurueck, nicht auf die Startseite.
     */
    public function verlangeAnmeldung(): Konto
    {
        Sitzung::starten();

        $konto = Sitzung::angemeldet() ? Sitzung::konto() : null;
        if ($konto !== null) {
            return $konto;
        }

        Sitzung::beenden();

        $ziel = Anfrage::sicheresZiel((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        self::weiterleiten('/anmelden?weiter=' . rawurlencode($ziel));
    }

    /** Formulare nur per POST und mit gueltigem Token. */
    public function verlangeFormular(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Sitzung::csrfGueltig($_POST['csrf'] ?? null)) {
            $this->fehlerseite(
                'Formular abgelaufen',
                'Das Formular war nicht mehr gültig. Bitte die Seite neu laden und es noch einmal versuchen.',
                400
            );
        }
    }

    public static function istPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    // --- Ausgabe -----------------------------------------------------------

    public function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $cache = $this->ablage . '/cache/twig';
        if (!is_dir($cache)) {
            @mkdir($cache, 0o750, true);
        }

        $entwicklung = umgebung('APP_ENV', 'production') === 'development';

        $twig = new Environment(new FilesystemLoader($this->wurzel . '/templates'), [
            'cache' => !$entwicklung && is_writable($cache) ? $cache : false,
            // Immer an: ein stat() je Vorlage ist billiger als eine alte
            // Vorlage nach dem Update.
            'auto_reload' => true,
            'strict_variables' => $entwicklung,
        ]);

        $twig->addFunction(new TwigFunction('datei', function (string $pfad): string {
            $datei = $this->wurzel . '/public/' . ltrim($pfad, '/');
            $stand = is_readable($datei) ? (int) filemtime($datei) : time();

            // Ohne Version liefert ein Zwischenspeicher die alte Fassung weiter.
            return $pfad . '?v=' . $stand;
        }));

        return $this->twig = $twig;
    }

    /**
     * Zeichnet eine Seite des Lehrerbereichs.
     *
     * @param array<string,mixed> $daten
     */
    public function zeichne(string $vorlage, array $daten = []): void
    {
        Sitzung::starten();

        $konto = $daten['konto'] ?? (Sitzung::angemeldet() ? Sitzung::konto() : null);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        echo $this->twig()->render($vorlage, array_merge([
            'konto' => $konto,
            'csrf' => Sitzung::csrfToken(),
            'meldung_erfolg' => Sitzung::holeMeldung('erfolg'),
            'meldung_fehler' => Sitzung::holeMeldung('fehler'),
            'portal_adresse' => $this->portalAdresse(),
        ], $daten));
    }

    /**
     * Zeichnet eine oeffentliche Seite: ohne Sitzung, ohne Cookie.
     *
     * @param array<string,mixed> $daten
     */
    public function zeichneOeffentlich(string $vorlage, array $daten = []): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo $this->twig()->render($vorlage, $daten);
    }

    /**
     * Eine Fehlerseite im Layout des Lehrerbereichs.
     */
    public function fehlerseite(string $titel, string $text, int $status = 400, ?string $zurueck = '/'): never
    {
        if (!headers_sent()) {
            http_response_code($status);
        }

        $this->zeichneOeffentlich('fehler.twig', ['titel' => $titel, 'text' => $text, 'zurueck' => $zurueck]);
        exit;
    }

    public static function weiterleiten(string $ziel): never
    {
        header('Location: ' . $ziel, true, 303);
        exit;
    }

    /**
     * Das eine 404 fuer alles: unbekannte Adressen, erfundene Links, Links
     * archivierter Klassen. Bewusst ohne Vorlage und ohne Datenbank - es soll
     * Byte fuer Byte gleich aussehen, egal warum es kommt.
     */
    public static function nichtGefunden(): never
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo self::nichtGefundenHtml();
        exit;
    }

    public static function nichtGefundenHtml(): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Nicht gefunden</title></head>'
            . '<body><h1>Seite nicht gefunden</h1>'
            . '<p>Diese Adresse gibt es nicht (mehr). Den aktuellen Link zur Klassenseite '
            . 'gibt es bei der Klassenlehrkraft.</p></body></html>';
    }
}
