-- Ratenbremse und Pruefwert des Schluessels.
--
-- In ratenbremse steht nie eine Adresse, nur ihr Hash (siehe Ratenbremse.php).
CREATE TABLE IF NOT EXISTS ratenbremse (
    topf VARCHAR(191) NOT NULL PRIMARY KEY,
    fenster_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    treffer INT NOT NULL DEFAULT 0,
    INDEX idx_ratenbremse_alter (fenster_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Genau eine Zeile. Passt der Pruefwert nicht mehr zum Schluessel, sperrt
-- Klassenziel den Import - sonst zaehlte der naechste kumulative Export jeden
-- Vorfall ein zweites Mal (siehe Schluesselpruefung.php).
CREATE TABLE IF NOT EXISTS schluesselpruefung (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    pruefwert CHAR(64) NOT NULL,
    angelegt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
