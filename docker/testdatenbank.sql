-- Zweite Datenbank fuer die Integrationstests.
--
-- Die Tests leeren sie bei jedem Lauf. In derselben Datenbank wie die
-- Entwicklungsdaten waere das nach dem ersten Testlauf alles weg.
CREATE DATABASE IF NOT EXISTS db_klassenziel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON db_klassenziel_test.* TO 'klassenziel'@'%';
FLUSH PRIVILEGES;
