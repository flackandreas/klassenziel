-- Klassen und wer fuer sie zustaendig ist.
--
-- link_token steht verschluesselt da, damit die Lehrkraft den Link wieder
-- anzeigen kann. Gesucht wird ausschliesslich ueber link_token_kennung (HMAC):
-- der Klartext taucht in keiner Abfrage auf.
--
-- daten_version zaehlt jede Aenderung hoch, die den Punktestand betreffen
-- kann. Der Zwischenspeicher des Punktestands haengt daran.
--
-- datenstand ist der Zeitpunkt des juengsten eingelesenen Exports, der diese
-- Klasse enthielt (aus dem Dateinamen). Bis dahin gelten Wochen als
-- vollstaendig erfasst - der Wochenbonus richtet sich danach.
CREATE TABLE IF NOT EXISTS klassen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anzeigename VARCHAR(100) NOT NULL,
    export_kuerzel VARCHAR(20) NOT NULL,
    schuljahr CHAR(7) NOT NULL,
    belohnung VARCHAR(200) NOT NULL,
    startdatum DATE NOT NULL,
    startwert TINYINT UNSIGNED NOT NULL DEFAULT 50,
    zielwert TINYINT UNSIGNED NOT NULL DEFAULT 70,
    stichtag DATE NULL DEFAULT NULL,
    link_token TEXT NULL DEFAULT NULL,
    link_token_kennung CHAR(64) NULL DEFAULT NULL,
    daten_version INT UNSIGNED NOT NULL DEFAULT 0,
    datenstand DATETIME NULL DEFAULT NULL,
    archiviert_am DATETIME NULL DEFAULT NULL,
    angelegt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_klassen_kuerzel_jahr (export_kuerzel, schuljahr),
    UNIQUE KEY uniq_klassen_link (link_token_kennung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zustaendigkeit. Das Portal uebertraegt keine Klassen aus IServ, deshalb
-- laesst sie sich nicht aus den vorhandenen Rollen ableiten.
CREATE TABLE IF NOT EXISTS klassen_lehrkraefte (
    klasse_id INT NOT NULL,
    konto_id INT NOT NULL,
    seit TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (klasse_id, konto_id),
    INDEX idx_zustaendig_konto (konto_id),
    CONSTRAINT fk_zustaendig_klasse FOREIGN KEY (klasse_id) REFERENCES klassen (id) ON DELETE CASCADE,
    CONSTRAINT fk_zustaendig_konto FOREIGN KEY (konto_id) REFERENCES konten (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vornamen der Klasse fuer die Namenswarnung beim Kurztext. Nur als Kennung
-- (HMAC), nie im Klartext: die Liste laesst sich ersetzen, aber nicht mehr
-- anzeigen.
CREATE TABLE IF NOT EXISTS namensliste (
    klasse_id INT NOT NULL,
    kennung CHAR(64) NOT NULL,
    PRIMARY KEY (klasse_id, kennung),
    CONSTRAINT fk_namensliste_klasse FOREIGN KEY (klasse_id) REFERENCES klassen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
