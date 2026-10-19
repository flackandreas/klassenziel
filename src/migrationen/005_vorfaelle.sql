-- Vorfaelle aus dem Klassenbuch.
--
-- Ein Vorfall ist, was die Klasse einmal Punkte kostet oder bringt - auch wenn
-- WebUntis ihn als eine Zeile je beteiligter Person exportiert.
--
-- Was hier NICHT steht: Schuelernamen und Lehrkraftkuerzel.
--  - lehrkraft_kennung ist der HMAC des Kuerzels, nur fuer die Gruppierung.
--  - namens_kennungen sind HMAC der Namensbestandteile, nur fuer die Warnung,
--    wenn ein Kurztext einen Namen enthaelt.
--  - originaltext und text_vorlage sind verschluesselt (Tresor.php). Sie
--    werden geloescht, sobald der Vorfall entschieden ist und ein spaeterer
--    Import ihm keine Zeile mehr hinzufuegt, spaetestens beim Archivieren.
--
-- richtung und stufe stehen nur bei manueller Bewertung. Sonst ergeben sie
-- sich bei jeder Berechnung aus der Kategorie-Zuordnung - so wirkt eine
-- geaenderte Zuordnung auch rueckwirkend.
--
-- Status:
--   offen              noch nicht entschieden; zaehlt schon, wenn die
--                      Kategorie bekannt ist
--   freigegeben        zaehlt, Kurztext oeffentlich
--   ohne_text          zaehlt, oeffentlich nur die Kategorie
--   ignoriert          zaehlt nicht, nicht oeffentlich
--   zuordnung_pruefen  koennte zu moeglich_zu gehoeren; zaehlt erst nach
--                      der Entscheidung, damit nichts doppelt zaehlt
CREATE TABLE IF NOT EXISTS vorfaelle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    klasse_id INT NOT NULL,
    datum DATE NOT NULL,
    fach_kuerzel VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    lehrkraft_kennung CHAR(64) NOT NULL,
    webuntis_kategorie VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
    kategoriegruppe VARCHAR(60) NOT NULL DEFAULT '',
    richtung ENUM('positiv', 'negativ', 'neutral') NULL DEFAULT NULL,
    stufe ENUM('klein', 'mittel', 'gross') NULL DEFAULT NULL,
    manuell_bewertet TINYINT(1) NOT NULL DEFAULT 0,
    anzahl_beteiligte SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    klasseneintrag TINYINT(1) NOT NULL DEFAULT 0,
    originaltext MEDIUMTEXT NULL DEFAULT NULL,
    text_vorlage MEDIUMTEXT NULL DEFAULT NULL,
    namens_kennungen TEXT NULL DEFAULT NULL,
    kurztext VARCHAR(160) NULL DEFAULT NULL,
    status ENUM('offen', 'freigegeben', 'ohne_text', 'ignoriert', 'zuordnung_pruefen') NOT NULL DEFAULT 'offen',
    moeglich_zu INT NULL DEFAULT NULL,
    entschieden_am DATETIME NULL DEFAULT NULL,
    entschieden_von INT NULL DEFAULT NULL,
    angelegt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vorfaelle_klasse_datum (klasse_id, datum),
    INDEX idx_vorfaelle_klasse_status (klasse_id, status),
    INDEX idx_vorfaelle_gruppe (klasse_id, datum, fach_kuerzel, lehrkraft_kennung),
    CONSTRAINT fk_vorfaelle_klasse FOREIGN KEY (klasse_id) REFERENCES klassen (id) ON DELETE CASCADE,
    CONSTRAINT fk_vorfaelle_moeglich FOREIGN KEY (moeglich_zu) REFERENCES vorfaelle (id) ON DELETE SET NULL,
    CONSTRAINT fk_vorfaelle_entschieden FOREIGN KEY (entschieden_von) REFERENCES konten (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
