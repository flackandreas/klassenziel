-- Lehrkraefte, wie sie vom Portal kommen.
--
-- Verknuepft ueber die dauerhafte Kennung des Portals (sso_sub), nicht ueber
-- das Kuerzel: Kuerzel werden an Schulen nach Jahren neu vergeben. Das Kuerzel
-- steht hier gar nicht erst - Klassenziel braucht es nicht, und so bleibt die
-- Regel ohne Ausnahme pruefbar, dass kein Lehrkraftkuerzel im Klartext in der
-- Datenbank steht.
CREATE TABLE IF NOT EXISTS konten (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sso_sub VARCHAR(64) NOT NULL,
    name VARCHAR(150) NOT NULL,
    ist_verwaltung TINYINT(1) NOT NULL DEFAULT 0,
    angelegt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zuletzt_am TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_konten_sub (sso_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
