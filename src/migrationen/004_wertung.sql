-- Gewichtung, Kategorien, Ferien, Faecher und Textbausteine.
--
-- Zwei Ebenen: Vorgaben der Schule und Werte je Klasse.
--
--  - Punkte und Wochenbonus werden beim Anlegen einer Klasse aus den Vorgaben
--    kopiert. Eine spaetere Aenderung der Vorgaben veraendert damit keinen
--    laufenden Wettbewerb.
--  - Kategorien folgen der Vorgabe, solange die Klasse keine eigene Zuordnung
--    hat. Neue WebUntis-Kategorien tauchen schulweit auf (etwa eine spaeter
--    eingefuehrte Kategorie "Lob") und sollen nicht in jeder Klasse einzeln
--    eingetragen werden muessen.
--
-- Kuerzel und Kategorien mit utf8mb4_bin: verglichen wird genau so, wie es im
-- Export steht.

CREATE TABLE IF NOT EXISTS vorgaben (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    punkte_klein TINYINT UNSIGNED NOT NULL DEFAULT 1,
    punkte_mittel TINYINT UNSIGNED NOT NULL DEFAULT 3,
    punkte_gross TINYINT UNSIGNED NOT NULL DEFAULT 5,
    wochenbonus TINYINT UNSIGNED NOT NULL DEFAULT 3,
    geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO vorgaben (id, punkte_klein, punkte_mittel, punkte_gross, wochenbonus) VALUES (1, 1, 3, 5, 3);

CREATE TABLE IF NOT EXISTS einstellungen (
    klasse_id INT NOT NULL PRIMARY KEY,
    punkte_klein TINYINT UNSIGNED NOT NULL,
    punkte_mittel TINYINT UNSIGNED NOT NULL,
    punkte_gross TINYINT UNSIGNED NOT NULL,
    wochenbonus TINYINT UNSIGNED NOT NULL,
    geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_einstellungen_klasse FOREIGN KEY (klasse_id) REFERENCES klassen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- richtung "pruefen": unbekannt, der Vorfall landet in der Pruefliste.
CREATE TABLE IF NOT EXISTS kategorie_vorgaben (
    webuntis_kategorie VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    richtung ENUM('positiv', 'negativ', 'neutral', 'pruefen') NOT NULL DEFAULT 'pruefen',
    stufe ENUM('klein', 'mittel', 'gross') NULL DEFAULT NULL,
    zuerst_gesehen_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Startwerte aus der Planung (Abschnitt 7.3). "mittel" gibt es in WebUntis
-- nicht; es laesst sich nur von Hand vergeben.
INSERT IGNORE INTO kategorie_vorgaben (webuntis_kategorie, richtung, stufe) VALUES
    ('klein/Schüler*in', 'negativ', 'klein'),
    ('klein/Klasse', 'negativ', 'klein'),
    ('groß/Schüler*in', 'negativ', 'gross'),
    ('groß/Klasse', 'negativ', 'gross'),
    ('Info/Schüler*in', 'neutral', NULL),
    ('Info/Klasse', 'neutral', NULL);

CREATE TABLE IF NOT EXISTS kategorie_zuordnungen (
    klasse_id INT NOT NULL,
    webuntis_kategorie VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    richtung ENUM('positiv', 'negativ', 'neutral', 'pruefen') NOT NULL,
    stufe ENUM('klein', 'mittel', 'gross') NULL DEFAULT NULL,
    geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (klasse_id, webuntis_kategorie),
    CONSTRAINT fk_zuordnung_klasse FOREIGN KEY (klasse_id) REFERENCES klassen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ferien gelten fuer die ganze Schule. Eine Woche, deren Montag bis Freitag
-- vollstaendig in Ferien liegen, bekommt keinen Wochenbonus.
CREATE TABLE IF NOT EXISTS ferien (
    id INT AUTO_INCREMENT PRIMARY KEY,
    von DATE NOT NULL,
    bis DATE NOT NULL,
    bezeichnung VARCHAR(100) NOT NULL,
    CONSTRAINT chk_ferien_reihenfolge CHECK (bis >= von),
    INDEX idx_ferien_von (von)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fachkuerzel aus dem Export. Ohne Klartext zeigt die Seite das Kuerzel.
CREATE TABLE IF NOT EXISTS faecher (
    kuerzel VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    klartext VARCHAR(100) NULL DEFAULT NULL,
    zuerst_gesehen_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vorschlaege fuer neutrale Kurztexte.
CREATE TABLE IF NOT EXISTS textbausteine (
    id INT AUTO_INCREMENT PRIMARY KEY,
    richtung ENUM('positiv', 'negativ', 'neutral') NOT NULL,
    text VARCHAR(120) NOT NULL,
    sortierung SMALLINT NOT NULL DEFAULT 0,
    INDEX idx_textbausteine_richtung (richtung, sortierung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO textbausteine (richtung, text, sortierung) VALUES
    ('negativ', 'Unterrichtsstörung', 10),
    ('negativ', 'Zu spät aus der Pause', 20),
    ('negativ', 'Arbeitsauftrag nicht erledigt', 30),
    ('negativ', 'Pausenregel nicht beachtet', 40),
    ('negativ', 'Respektloser Umgang', 50),
    ('negativ', 'Unterricht kaum möglich', 60),
    ('positiv', 'Gute Mitarbeit', 10),
    ('positiv', 'Hilfsbereitschaft', 20),
    ('positiv', 'Gelungene Gruppenarbeit', 30),
    ('positiv', 'Ruhiges, konzentriertes Arbeiten', 40),
    ('positiv', 'Besonderes Engagement', 50);
