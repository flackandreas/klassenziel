-- Ein Zaehler fuer alles, was fuer die ganze Schule gilt.
--
-- Der Punktestand jeder Klasse haengt auch an Ferien, Kategorie-Vorgaben und
-- Faechern. Aendert sich davon etwas, muss jeder zwischengespeicherte Stand
-- neu berechnet werden - der Zwischenspeicher fragt dafuer diesen Zaehler mit
-- ab, neben daten_version der Klasse.
ALTER TABLE vorgaben ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 0;
