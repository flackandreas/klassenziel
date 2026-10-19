# Klassenziel

Ein SchulOS-Modul: Eine Klasse verdient sich über das Schuljahr gemeinsam eine
Belohnung – für die 9d eine Kanutour mit Übernachtung. Der Punktestand steht als
„Health-Balken“ am Beamer und auf einer Seite für Schülerinnen, Schüler und Eltern.
Er sinkt mit negativen und steigt mit positiven Einträgen aus dem Klassenbuch.
Die Einträge erscheinen dort ohne Namen.

Datenquelle ist der wöchentliche CSV-Export aus WebUntis, den die Klassenlehrkraft
hochlädt.

> **Stand:** Phase 3 von 5. Fertig sind:
>
> - Gerüst, Datenmodell, Anmeldung über das Portal
> - Klassenverwaltung und geheimer Link
> - Import des WebUntis-Exports
> - Prüfliste, Gewichtung mit Simulation, Wochenbonus und Ferien
>
> Es folgen die öffentliche Anzeige (Beamer und Handy) sowie Datenschutz und
> Betrieb; der Plan steht in [`UMSETZUNGSPLAN.md`](UMSETZUNGSPLAN.md).

---

## Inhalt

- [Wie es läuft](#wie-es-läuft)
- [Datenschutz](#datenschutz)
- [Entwicklung und Tests](#entwicklung-und-tests)
- [Konfiguration](#konfiguration)
- [Routen](#routen)
- [Kommandozeile](#kommandozeile)

---

## Wie es läuft

- **PHP 8.2, Twig 3, MariaDB**, gebaut wie das SchulOS-Portal: Der Code liegt im
  Docker-Abbild, nur `public/` wird ausgeliefert, und die Content-Security-Policy
  kommt ohne `unsafe-inline` aus.
- **Anmeldung nur über das SchulOS-Portal.** Klassenziel hat keine eigene
  Anmeldemaske und keine Passwörter. Wer im Portal in `PORTAL_ADMIN_GRUPPEN`
  steht, hat Verwaltungsrechte: Diese Personen sehen alle Klassen und vergeben
  die Zuständigkeiten.
- **Lehrkräfte legen ihre Klassen selbst an** und sind dann zuständig. Weitere
  Zuständige trägt die Verwaltung ein. Eintragen lassen sich nur Personen, die
  sich einmal bei Klassenziel angemeldet haben.
- **Eigene Datenbank** `db_klassenziel` mit eigenem Nutzer, wie jedes Modul.
- Im Verbund und auf dem Mac mini wird Klassenziel über `schulos-portal`
  eingebunden (Profil `klassenziel`), siehe dort `compose.yml` und
  `pi/compose.pi.yml`.

## Datenschutz

Die Regeln stehen in der Planung (Abschnitt 5). So setzt der Code sie um:

| Regel | Umsetzung |
|---|---|
| Keine Schülernamen speichern | Die Spalte `Name` gibt es nur während des Imports im Speicher. Gespeichert werden HMAC-Kennungen, nie die Namen selbst |
| Kein Lehrkraftkürzel im Klartext | Aus dem Export nur als HMAC (`lehrkraft_kennung`). Die Kontentabelle führt gar kein Kürzel |
| Originaltext nie öffentlich | Gespeichert mit XChaCha20-Poly1305 (libsodium). Je Feld bindet ein Zweck mit, deshalb lässt sich ein Geheimtext nicht in eine andere Spalte umkopieren |
| Geheimer Link | 192 Bit. In der Datenbank liegt er verschlüsselt und als HMAC, gesucht wird nur über das HMAC. Ein neuer Link macht den alten sofort ungültig |
| Öffentliche Seite | `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, Ratenbremse, für alles dasselbe 404, keine Sitzung, kein Cookie |
| Zugriffsprotokoll | Apache protokolliert `/klassenziel/[...]` statt des Links |
| Exporte nie im Repository | `.gitignore`, `.dockerignore` und ein CI-Schritt. Tests arbeiten nur mit erfundenen Daten unter `src/tests/daten/` |
| Uploads nie auf der Platte | `/tmp` im Container ist ein `tmpfs` |
| Archivieren | Der Link verfällt. Originaltexte, Textvorlagen, Namenskennungen und die Namensliste werden gelöscht |

### Import

1. **Hochladen.** Die Datei wird gelesen und nicht gespeichert.
   - Tabulator, Semikolon oder Komma als Trennzeichen
   - `""` in Anführungszeichen, Zeilenumbrüche in einem Feld
   - BOM, Windows-Zeilenenden und Windows-1252 aus Excel
   - Datum `dd.mm.yy`
2. **Fingerabdruck je Zeile.** HMAC über Klasse, Datum, Fach, Name, Benutzer,
   Kategorie und Text, nach Trimmen und Unicode-NFC. Bekannte Zeilen werden
   übersprungen. Dadurch ist der Import idempotent, obwohl der Export kumulativ ist.
3. **Gruppierung.** Zeilen gehören zu einem Vorfall bei gleicher Klasse, gleichem
   Datum, Fach, Lehrkraft und gleicher Kategorie, wenn die Texte nach dem
   Ersetzen der Namen dieser Zeile zu mindestens 0,8 ähnlich sind (Levenshtein).
   Ein Vorfall zählt einmal, unabhängig von der Zahl der Beteiligten.
4. **Vorschau.** Zwischen Hochladen und Bestätigen liegt ein namensfreier,
   verschlüsselter Plan in der Datenbank, der nach einer Stunde abläuft.
5. **Bestätigen.** Läuft in einer Transaktion. Danach greift die Löschregel:
   Ein entschiedener Vorfall, dem dieser Import nichts hinzufügt, verliert
   Originaltext und Textvorlage.

Einträge der Kategoriegruppe „Inf. / neutral“ werden ohne Text gespeichert, nur
damit sie nicht erneut auftauchen, und nie gezählt.

### Wertung

Der Punktestand wird nicht gespeichert, sondern bei jedem Abruf berechnet
(`Wertung/Punkterechner`). Eine geänderte Gewichtung wirkt deshalb sofort und
rückwirkend. Das Ergebnis liegt in `storage/cache/stand`. Der Schlüssel dieses
Zwischenspeichers enthält `daten_version` der Klasse, den Stand der
Schulvorgaben, den Tag und den Datenstand. Jede Änderung macht den alten Eintrag
damit von selbst ungültig.

- Start mit dem Startwert, dann alle zählenden Vorfälle chronologisch. Nach
  jedem Schritt wird auf 0–100 begrenzt, nicht erst am Ende.
- Es zählen Vorfälle vom Startdatum bis zum Stichtag. Ein Vorfall zählt einmal,
  egal wie viele beteiligt waren.
- Es zählen die Status offen (mit bekannter Kategorie), freigegeben und ohne
  Text. Nicht zählen: ignoriert, „Zuordnung prüfen“, neutral und alles ohne
  Einstufung.
- Stufen ohne eigene Einstufung folgen der Kategorie-Zuordnung, auch einer
  später geänderten.
- **Wochenbonus** für jede Woche (Mo–So) ohne zählenden großen negativen
  Vorfall. Er wird am Sonntag gutgeschrieben, nach den Vorfällen des Tages, und
  nur wenn alle drei Bedingungen gelten:
  - die Woche ist vorbei,
  - sie ist vollständig eingelesen (der Datenstand liegt danach),
  - sie ist keine Ferienwoche (Montag bis Freitag ganz in Ferien).
- Die **Simulation** zeigt den Verlauf mit den neuen Werten neben dem bisherigen.
  Gespeichert wird nur, was in der Vorschau zu sehen war: Der Knopf trägt eine
  Prüfsumme der simulierten Werte.

**Der Schlüssel `KLASSENZIEL_SCHLUESSEL` gehört in die Sicherung.** Ohne ihn
sind die Originaltexte unlesbar, alle Links müssen neu erzeugt werden, und die
Fingerabdrücke der Exportzeilen passen nicht mehr. Für diesen Fall liegt ein
Prüfwert in der Datenbank: Passt er nicht zum Schlüssel, sperrt Klassenziel den
Import. Sonst würde der nächste kumulative Export jeden Vorfall doppelt zählen.

## Entwicklung und Tests

Voraussetzung ist Docker (OrbStack). PHP muss lokal nicht installiert sein.

```bash
cp .env.example .env
# Schlüssel eintragen:
openssl rand -base64 32
docker compose up -d --build
```

Danach antwortet `http://localhost:8893/healthz`. Zum Anmelden braucht es das
Portal, also den Verbund aus `schulos-portal`
(`http://klassenziel.schulos.localhost:8090`).

```bash
docker compose exec web composer test                                  # PHPUnit, Unit und Integration
docker compose exec web php -d memory_limit=1G vendor/bin/phpstan analyse   # PHPStan, Level 6
```

Die Integrationstests laufen gegen eine eigene Datenbank `db_klassenziel_test`
und leeren sie bei jedem Test. Die öffentliche Route prüfen sie über echtes HTTP
mit dem eingebauten Server von PHP. Ohne `TEST_DB_NAME` werden sie übersprungen.

`src/vendor/` ist versioniert, wie im Portal: Das Abbild führt kein
`composer install` aus.

## Konfiguration

| Variable | Bedeutung |
|---|---|
| `KLASSENZIEL_SCHLUESSEL` | Hauptschlüssel, 32 Byte base64 (`openssl rand -base64 32`). Pflicht |
| `APP_URL` | Adresse, wie sie im Browser steht. Aus ihr entstehen die Links |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Datenbank |
| `PORTAL_ISSUER`, `PORTAL_CLIENT_ID`, `PORTAL_CLIENT_SECRET`, `PORTAL_REDIRECT_URI`, `PORTAL_INTERN` | Anbindung an das Portal |
| `PORTAL_ADMIN_GRUPPEN` | Diese Portalgruppen haben hier Verwaltungsrechte (Vorgabe `Schulleitung`) |
| `PORTAL_ZUGANG_GRUPPEN` | Nur diese Gruppen dürfen hinein. Leer heißt: alle, die das Portal hereinlässt |
| `TRUSTED_PROXIES` | Vermittler, deren `X-Forwarded-For` zählt (`private` für die privaten Netze) |
| `AUTO_MIGRATE` | `0` schaltet die Migrationen beim Start ab |
| `APP_ENV` | `development` schaltet den Zwischenspeicher der Vorlagen ab |

## Routen

| Route | |
|---|---|
| `/klassenziel/{link}` | öffentliche Klassenseite (ab Phase 4 mit dem Health-Balken) |
| `/datenschutz` | Hinweise zum Datenschutz (Platzhalter) |
| `/` | meine Klassen |
| `/klassen/neu`, `/klassen/{id}` | anlegen, bearbeiten, Link, Zuständigkeit, archivieren |
| `/import`, `/import/{id}` | Export hochladen, Vorschau, bestätigen oder verwerfen, Protokoll |
| `/klassen/{id}/pruefliste` (`?alle=1`) | offene Vorfälle, oder alle zum nachträglichen Ändern |
| `/vorfaelle/{id}` | einstufen, Kurztext mit Namenswarnung, freigeben / ohne Text / ignorieren, teilen, zusammenführen |
| `/klassen/{id}/gewichtung` | Stufenpunkte, Wochenbonus, Kategorie-Zuordnung, Simulation vor dem Speichern |
| `/verwaltung/…` | Ferien, Fächer, Textbausteine; Vorgaben nur für die Verwaltung |
| `/sso/start`, `/sso/rueckweg`, `/sso/abmelden`, `/abmelden` | Anmeldung über das Portal |
| `/healthz` | Lebenszeichen für das Portal |

## Kommandozeile

```bash
docker compose exec web php bin/klassenziel.php migrieren
docker compose exec web php bin/klassenziel.php status       # Schema und Schlüssel
docker compose exec web php bin/klassenziel.php schluessel   # neuen Schlüssel ausgeben
docker compose exec web php bin/klassenziel.php aufraeumen
```
