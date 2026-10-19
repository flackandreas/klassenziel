<?php

declare(strict_types=1);

namespace Klassenziel\Konten;

use PDO;
use SchulOS\Sso\Identitaet;

/**
 * Konten entstehen bei der ersten Anmeldung ueber das Portal.
 *
 * Das Portal ist die fuehrende Quelle: Name und Verwaltungsrecht werden bei
 * jeder Anmeldung nachgezogen. Wer dort aus der Schulleitung genommen wird,
 * hat hier ab der naechsten Anmeldung keine Verwaltungsrechte mehr.
 */
final class Konten
{
    public function __construct(private readonly PDO $verbindung)
    {
    }

    /**
     * @return Konto|null null heisst: kein Zugang zu diesem Modul
     */
    public function anmelden(Identitaet $identitaet, string $zugangsGruppen, string $verwaltungsGruppen): ?Konto
    {
        if ($zugangsGruppen !== '' && !$identitaet->inGruppenListe($zugangsGruppen)) {
            // Die Kennung des Portals, nicht das Kuerzel: das Protokoll soll
            // keine Kuerzel tragen.
            error_log('Klassenziel: Zugang verweigert fuer Portalkonto ' . substr(hash('sha256', $identitaet->sub), 0, 12));

            return null;
        }

        $istVerwaltung = $identitaet->inGruppenListe($verwaltungsGruppen);
        $name = mb_substr($identitaet->name !== '' ? $identitaet->name : 'Lehrkraft', 0, 150);

        $this->verbindung->prepare(
            'INSERT INTO konten (sso_sub, name, ist_verwaltung, zuletzt_am)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), ist_verwaltung = VALUES(ist_verwaltung), zuletzt_am = NOW()'
        )->execute([mb_substr($identitaet->sub, 0, 64), $name, $istVerwaltung ? 1 : 0]);

        return $this->nachSub($identitaet->sub);
    }

    public function nachId(int $id): ?Konto
    {
        $abfrage = $this->verbindung->prepare('SELECT * FROM konten WHERE id = ?');
        $abfrage->execute([$id]);
        $zeile = $abfrage->fetch();

        return is_array($zeile) ? Konto::ausZeile($zeile) : null;
    }

    public function nachSub(string $sub): ?Konto
    {
        $abfrage = $this->verbindung->prepare('SELECT * FROM konten WHERE sso_sub = ?');
        $abfrage->execute([$sub]);
        $zeile = $abfrage->fetch();

        return is_array($zeile) ? Konto::ausZeile($zeile) : null;
    }

    /**
     * Alle Lehrkraefte, die sich schon einmal angemeldet haben - nur sie
     * lassen sich einer Klasse zuordnen.
     *
     * @return list<Konto>
     */
    public function alle(): array
    {
        $zeilen = $this->verbindung->query('SELECT * FROM konten ORDER BY name')->fetchAll() ?: [];

        return array_map(static fn (array $z): Konto => Konto::ausZeile($z), $zeilen);
    }
}
