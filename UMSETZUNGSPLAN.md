# Klassenziel – Umsetzungsplan

Stand: 19.10.2026 · Grundlage: `planung-schulos-klassenziel.md` · am 19.10.2026 von Andreas freigegeben, mit den Vorschlägen aus Abschnitt 8

| Phase | Stand |
|---|---|
| 1 – Gerüst, Datenmodell, Klassen, Link | erledigt |
| 2 – Import | erledigt |
| 3 – Prüfliste und Wertung | erledigt |
| 4 – Öffentliche Ansicht | offen |
| 5 – Datenschutz und Betrieb | offen |

---

## 1. Was die Analyse ergeben hat

| Annahme in der Planung | Tatsächlicher Stand in SchulOS |
|---|---|
| SchulOS ist eine Laravel-App im Repository `schulos` | `schulos` ist die Marketing-Seite (React/Vite, Vercel). SchulOS selbst ist ein **Verbund**: `schulos-portal` (OIDC-Anbieter, PHP 8.2) plus je Modul ein eigenes Repository mit eigenem Hostnamen und eigener Datenbank |
| Modul „Anträge“ als Vorlage | `feedback_rstn`: PHP 8.2, Twig, PDO, ohne Tests. Die neueren Konventionen stehen im **Portal** (Code im Abbild, CSP ohne `unsafe-inline`, PHPUnit + PHPStan, festgenagelte Actions) und in **Unterricht** (`schulos/sso`, `portal_konten`, Migrator, `rate_limits`, Token-Seiten ohne Anmeldung) |
| Rollen aus SchulOS | Portalgruppen über `schulos/sso`: `PORTAL_ADMIN_GRUPPEN` = Verwaltung, `PORTAL_ZUGANG_GRUPPEN` = Zugang. Klassen aus IServ überträgt das Portal bewusst nicht |
| Erreichbarkeit von außen offen (Planung, Abschnitt 13) | Weitgehend schon gelöst: `*.rstn.de` hat DNS-Platzhalter und Zertifikat, IServ leitet je Hostname an einen Port des Mac mini weiter. Für Klassenziel fehlt nur ein Eintrag `klassenziel.rstn.de → <Mac mini>:8010` (Adresse siehe `deploy/MAC-MINI.md` im Portal) |

## 2. Abweichungen von der Planung (Konventionen gehen vor)

1. **Kein Laravel.** Reines PHP 8.2 + Twig 3 + PDO/MariaDB wie Portal und Unterricht. Ersatz für die Laravel-Bausteine:
   - `encrypted`-Cast → libsodium `secretbox` (in PHP 8.2 eingebaut) in `app/Sicherheit/Tresor.php`
   - `APP_KEY` → eigener Schlüssel `KLASSENZIEL_SCHLUESSEL` (32 Byte). Daraus leitet HKDF getrennte Teilschlüssel ab: einen für die Verschlüsselung, einen für die Fingerabdrücke, einen für die Link-Tokens
   - Laravel-Cache → Dateicache in `storage/`, Schlüssel: Klasse + Datenversion + Tagesdatum
   - Migrationen → SQL-Dateien mit eigenem Migrator, Reihenfolge im Code (wie im Portal)
   - `Str::random(32)` → `random_bytes(24)` als base64url (192 Bit)
2. **Eigenes Repository** `klassenziel`, dazu ein Branch im `schulos-portal` für Katalog, Compose und Datenbank.
3. **Eigene Datenbank** `db_klassenziel` mit eigenem Nutzer, wie bei jedem Modul. Das Präfix `klassenziel_` entfällt damit; es hätte nur in einer gemeinsamen Datenbank einen Zweck.
4. **Anmeldung nur über das Portal**, ohne eigene Anmeldemaske (so macht es auch Auszeit). Die Tabelle `konten` speichert nur `sso_sub`, den Anzeigenamen und ob die Person zur Verwaltung gehört. Das Kürzel speichert sie nicht: So kann der Datenschutz-Test ohne Ausnahme prüfen, dass kein Lehrkraftkürzel im Klartext in der Datenbank steht.
5. **Zuständigkeit** über eine eigene Tabelle `klassen_lehrkraefte`. Über vorhandene Rollen geht es nicht, weil das Portal keine Klassen überträgt.
6. **JavaScript ohne Build-Schritt** (Vanilla, wie in allen PHP-Modulen). Kein TypeScript, kein Node im Abbild.
7. **Balken und Simulationskurve als SVG vom Server.** So bleibt die CSP streng (`style-src 'self'`), und die Anzeige funktioniert auch ohne JavaScript. JavaScript übernimmt nur Animation, Aktualisierung und „Weitere laden“.
8. **Unicode-NFC** über `symfony/polyfill-intl-normalizer` (reines PHP) statt `ext-intl`. Das spart rund 30 MB ICU im Abbild auf dem Pi.

## 3. Ergänzungen, die die Planung präzisieren

- **Namensprüfung über den Import hinaus:** Je Vorfall speichere ich die HMAC-Hashes der Namensbestandteile (nie die Namen selbst). Die Warnung greift dadurch auch, wenn der Kurztext erst Tage nach dem Import entsteht.
- **Namensliste der Klasse** wird ebenfalls nur als Hashes gespeichert. Im Admin steht dann „14 Namen hinterlegt – Liste ersetzen“, die Namen selbst lassen sich nicht mehr anzeigen.
- **Zwischen Vorschau und Bestätigen** liegt ein namensfreier, verschlüsselter Importplan in der Datenbank. Er enthält Fingerabdrücke, Gruppierung, verschlüsselte Texte und Namens-Hashes und läuft nach einer Stunde ab. Die Sitzung kommt dafür nicht in Frage, weil Sitzungsdateien auf der Platte liegen. `/tmp` im Container ist ein `tmpfs`, damit auch die temporäre Upload-Datei von PHP nur im RAM liegt.
- **Schutz gegen Schlüsselwechsel:** Ein Prüfwert des Schlüssels liegt in der Datenbank. Passt der Schlüssel nicht mehr (z. B. nach einer Rücksicherung ohne die `.env`), sperrt das System den Import mit einer klaren Meldung. Sonst hielte der nächste kumulative Export alle Zeilen für neu, und jeder Vorfall zählte doppelt.
- **Wochenbonus nur für Wochen, die vollständig vor dem Exportzeitpunkt liegen.** Der Zeitpunkt kommt aus dem Dateinamen. Ohne diese Regel bekäme eine Woche ihren Bonus, bevor der Freitagseintrag importiert ist, und verlöre ihn mit dem nächsten Import wieder. Als Ferienwoche gilt eine Woche, deren Montag bis Freitag vollständig in Ferien liegen.
- **Wertungszeitraum:** Es zählen nur Vorfälle vom Startdatum bis zum Stichtag.
- **Möglicher Nachzügler:** Kommt eine neue Zeile zu einem schon erledigten Vorfall, dessen Text bereits gelöscht ist, entsteht ein Vorfall „Zuordnung prüfen“. Er zählt erst, wenn die Lehrkraft ihn zusammenführt oder bestätigt, damit nichts doppelt zählt.
- **Lehrkräfte dürfen eigene Klassen anlegen** und sind dann automatisch zuständig. Die Verwaltung sieht alle Klassen und vergibt die Zuständigkeiten. Für den Pilot heißt das: Du brauchst keine Verwaltungsrechte im Portal.

## 4. Aufbau des neuen Repositorys

```
klassenziel/
├── Dockerfile                 php:8.2-apache, Code im Abbild (wie das Portal), ARM-tauglich
├── docker-compose.yml         Entwicklung: web + MariaDB, Port 8893, /tmp als tmpfs
├── docker-entrypoint.sh       Ablage-Rechte, Zeitzone, Migrationen als www-data
├── .env.example  .gitignore   (.gitignore sperrt ClassregEventPerKlasse_*.csv und *.csv außerhalb tests/daten)
├── .github/workflows/pruefung.yml   Syntax, Twig, PHPUnit, PHPStan, Migrationen gegen leeres Schema
├── README.md
└── src/
    ├── bootstrap.php  composer.json  vendor/ (versioniert, wie im Portal)
    ├── public/        index.php (Wegweiser, Schutzköpfe), css/, js/, fonts/ (woff2, OFL)
    ├── seiten/        ein Skript je Route (siehe 5.)
    ├── templates/     grundlage.twig, anzeige/, verwaltung/, fehler.twig
    ├── migrationen/   001_konten.sql … (siehe 6.)
    ├── bin/klassenziel.php   migrieren | schluessel | aufraeumen | status
    ├── app/                  Namespace Klassenziel\
    │   ├── Support/          Datenbank, Migrator, Anfrage (TRUSTED_PROXIES), Ratenbremse, Kern (Twig), Sitzung, helfer.php
    │   ├── Sicherheit/       Schluessel (HKDF), Tresor (secretbox), Zugangslink
    │   ├── Konten/           Konten (Portal-Identität → Konto)
    │   ├── Klassen/          KlassenRepository, Zustaendigkeit
    │   ├── Import/           ExportParser, Exportzeile, Normalisierung, Fingerabdruck, Gruppierer, Importplan, Importdienst
    │   ├── Vorfaelle/        VorfallRepository, Namenspruefung, Aufteilen/Zusammenfuehren
    │   ├── Wertung/          Gewichtung, Kategoriezuordnung, Ferienkalender, Punkterechner (rein, ohne DB), Standdienst (+ Cache)
    │   └── Anzeige/          Anzeigedaten: das einzige Ansichtsmodell der öffentlichen Seite, mit fester Feldliste
    └── tests/
        ├── daten/            synthetische Exporte (Tab, Semikolon, Komma, BOM, Folge-Export)
        ├── Unit/             Parser, Fingerabdruck, Gruppierer, Punkterechner, Wochenbonus, Namenspruefung, Tresor, Zugangslink, Anzeigedaten, Migrationen
        └── Integration/      gegen MariaDB: Idempotenz, Datenschutz, öffentliche Route
```

## 5. Routen

| Route | Wer | Zweck |
|---|---|---|
| `/klassenziel/{token}` (`?ansicht=beamer`) | öffentlich | Beamer- oder Handy-Ansicht, `noindex`, Ratenbremse, generisches 404, `Referrer-Policy: no-referrer` |
| `/klassenziel/{token}/stand.json` | öffentlich | Aktualisierung alle 5 Minuten und „Weitere laden“, mit denselben Feldern wie die Seite |
| `/datenschutz` | öffentlich | Platzhaltertext |
| `/` | Lehrkraft | meine Klassen, offene Prüffälle je Klasse |
| `/klassen/neu`, `/klassen/{id}` | Lehrkraft | anlegen, bearbeiten, Link anzeigen/kopieren/neu erzeugen, Namensliste, archivieren |
| `/klassen/{id}/pruefliste`, `/vorfaelle/{id}` | Lehrkraft | einstufen, Kurztext mit Namenswarnung, freigeben / ohne Text / ignorieren, teilen, zusammenführen |
| `/klassen/{id}/gewichtung` (+ `/simulation.json`) | Lehrkraft | Stufenpunkte, Kategorie-Zuordnung, Wochenbonus, Simulation vor dem Speichern |
| `/import` | Lehrkraft | Upload → Vorschau → Bestätigen, Protokoll |
| `/verwaltung/{vorgaben,ferien,faecher,textbausteine}` | Verwaltung | Schulstandards (Ferien, Fächer und Textbausteine dürfen auch Lehrkräfte pflegen). Die Zuständigkeit vergibt die Verwaltung direkt auf der Seite der Klasse |
| `/sso/start`, `/sso/rueckweg`, `/sso/abmelden`, `/abmelden`, `/healthz` | – | wie in den anderen Modulen |

## 6. Datenmodell (`db_klassenziel`)

Wie in der Planung, Abschnitt 10, mit diesen Änderungen:
- `konten` (id, sso_sub, name, ist_verwaltung, zuletzt_am)
- `klassen_lehrkraefte` (klasse_id, konto_id)
- `klassen` zusätzlich mit `daten_version` für den Cache und einem Unique-Index auf (export_kuerzel, schuljahr)
- `vorfaelle` zusätzlich mit `text_vorlage` (verschlüsselt, mit Namensplatzhaltern, für die Gruppierung), `namens_hashes`, `beteiligt_klasse` (Klasseneintrag) und dem Status `zuordnung_pruefen`
- `importzeilen` mit `klasse_id`. `importe` ohne feste Klasse, weil eine Datei mehrere Klassen enthalten kann; dazu `status` (vorschau/bestätigt), `plan` (verschlüsselt), `laeuft_ab`
- `namensliste` (klasse_id, hash)
- `schluesselpruefung`, `rate_limits`, `migration_log`

## 7. Phasen (nach jeder ein lauffähiger Commit)

**Phase 1 – Gerüst, Datenmodell, Klassen, geheimer Link.** Repository-Gerüst, Docker, CI, Anmeldung über das Portal, Migrationen, Klassen anlegen und bearbeiten, Zuständigkeit, Link erzeugen/ungültig machen, öffentliche Route mit 404, `noindex` und Ratenbremse (Inhalt folgt in Phase 4).
*Im Portal (Branch `feat/klassenziel`):* `Modulkatalog.php` (Eintrag und Symbol), `migrationen/009_klassenziel.sql` (sichtbar für die Personalgruppen), `Migrator.php`, `compose.yml`, `Caddyfile`, `.env.example`, `datenbank/00-schulos.sql` und `20-rechte.sh` (lokaler Verbund: `klassenziel.schulos.localhost:8090`), `pi/compose.pi.yml` (Profil `klassenziel`, Port 8010), `pi/db_klassenziel.sql`, `pi/.env.example`, CI-Prüfung der Compose-Dateien.

**Phase 2 – Import.** Parser, Normalisierung, Fingerabdruck, Gruppierung, Vorschau, Bestätigen, Protokoll; neue Fachkürzel und Kategorien werden automatisch erfasst. Tests für Parser, Idempotenz und Gruppierung.

**Phase 3 – Prüfliste und Wertung.** Prüfliste mit allen Aktionen, Namensprüfung, Textbausteine, Gewichtung, Kategorie-Zuordnung, Wochenbonus, Ferien, Simulation (zwei Kurven, alt und neu, als SVG). Tests für Punkterechner, Wochenbonus und Namenswarnung.

**Phase 4 – Öffentliche Ansicht.** Beamer- (1280×720 bis 1920×1080, ohne Scrollen) und Handy-Layout (390×844) mit den Design-Tokens. Bricolage Grotesque und Atkinson Hyperlegible selbst gehostet; die woff2-Dateien kommen aus den Fontsource-npm-Paketen, OFL, Lizenztext liegt bei. Aktualisierung ohne Flackern, `prefers-reduced-motion`, Kontrast geprüft. Am Ende Sichtprüfung im eingebauten Browser in beiden Größen.

**Phase 5 – Datenschutz und Betrieb.** Datenschutz-Test über Datenbank-Dump, öffentliches HTML und Log; Archivieren mit Löschung von Originaltexten, Vorlagen und Namens-Hashes; `bin/klassenziel.php aufraeumen` setzt die Löschregel um. Abschnitt im `deploy/MAC-MINI.md` (IServ-Eintrag, `.env`, Datenbank anlegen, Sicherung des Schlüssels). Erst Test auf dem Pi, dann Übergabe für den Mac mini. Die Sicherung erfasst `db_klassenziel` von selbst (`--all-databases`), der Schlüssel muss aber mit der `pi/.env` gesichert werden.

Commits lege ich lokal an. GitHub-Repository anlegen, pushen und den Pull Request im Portal öffne ich erst nach deinem OK.

## 8. Offene Punkte – mein Vorschlag gilt, wenn du nichts anderes sagst

1. **Name und Ordner.** Der leere Ordner heißt `klassenklima`, die Planung nennt das Modul `klassenziel`. Vorschlag: Repository hier anlegen, Modul, Hostname und Datenbank heißen `klassenziel`. Soll das Modul stattdessen „Klassenklima“ heißen, ändere ich das vor dem ersten Commit.
2. **Löschregel für den Originaltext.** Die Planung sagt: gelöscht, „sobald freigegeben oder ignoriert und der nächste Import ihn nicht mehr liefert“. Wörtlich genommen greift das nie, weil der Export kumulativ ist und jede Zeile immer wieder mitliefert. Vorschlag: gelöscht beim ersten Import nach der Entscheidung, der dem Vorfall keine neue Zeile hinzufügt; spätestens beim Archivieren.
3. **Wochenbonus erst bei vollständig importierten Wochen** (Abschnitt 3). Vorschlag: so umsetzen.
4. **Lehrkräfte legen eigene Klassen an** (Abschnitt 3). Vorschlag: ja.
