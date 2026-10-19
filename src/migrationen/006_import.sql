-- Importe und die Fingerabdruecke der eingelesenen Zeilen.
--
-- Die Datei selbst wird nicht gespeichert. Zwischen Vorschau und Bestaetigen
-- liegt in "plan" ein namensfreier, verschluesselter Importplan:
-- Fingerabdruecke, Gruppierung, verschluesselte Texte und Namens-Kennungen.
-- Er laeuft nach einer Stunde ab und wird mit dem Bestaetigen geleert. Die
-- Sitzung kommt dafuer nicht in Frage - Sitzungsdateien liegen auf der Platte.
--
-- dateiname steht nur, wenn er die Form des WebUntis-Exports hat
-- (ClassregEventPerKlasse_JJJJMMTT_HHMM.csv); sonst steht dort "Export". Ein
-- frei gewaehlter Dateiname kann einen Namen tragen.
CREATE TABLE IF NOT EXISTS importe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dateiname VARCHAR(120) NOT NULL,
    exportzeitpunkt DATETIME NULL DEFAULT NULL,
    importiert_von INT NULL DEFAULT NULL,
    status ENUM('vorschau', 'bestaetigt', 'verworfen') NOT NULL DEFAULT 'vorschau',
    plan MEDIUMTEXT NULL DEFAULT NULL,
    laeuft_ab DATETIME NULL DEFAULT NULL,
    zeilen_gesamt INT UNSIGNED NOT NULL DEFAULT 0,
    zeilen_neu INT UNSIGNED NOT NULL DEFAULT 0,
    zeilen_duplikat INT UNSIGNED NOT NULL DEFAULT 0,
    zeilen_fremd INT UNSIGNED NOT NULL DEFAULT 0,
    vorfaelle_neu INT UNSIGNED NOT NULL DEFAULT 0,
    vorfaelle_zugeordnet INT UNSIGNED NOT NULL DEFAULT 0,
    pruefliste INT UNSIGNED NOT NULL DEFAULT 0,
    angelegt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bestaetigt_am DATETIME NULL DEFAULT NULL,
    INDEX idx_importe_status (status, laeuft_ab),
    CONSTRAINT fk_importe_konto FOREIGN KEY (importiert_von) REFERENCES konten (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eine Zeile je bekannter Exportzeile. Ein Fingerabdruck, der hier steht,
-- wird beim naechsten Import uebersprungen: so ist jeder Import idempotent,
-- obwohl der Export kumulativ ist.
CREATE TABLE IF NOT EXISTS importzeilen (
    fingerabdruck CHAR(64) NOT NULL PRIMARY KEY,
    vorfall_id INT NOT NULL,
    import_id INT NULL DEFAULT NULL,
    klasse_id INT NOT NULL,
    angelegt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_importzeilen_vorfall (vorfall_id),
    INDEX idx_importzeilen_klasse (klasse_id),
    CONSTRAINT fk_importzeilen_vorfall FOREIGN KEY (vorfall_id) REFERENCES vorfaelle (id) ON DELETE CASCADE,
    CONSTRAINT fk_importzeilen_import FOREIGN KEY (import_id) REFERENCES importe (id) ON DELETE SET NULL,
    CONSTRAINT fk_importzeilen_klasse FOREIGN KEY (klasse_id) REFERENCES klassen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
