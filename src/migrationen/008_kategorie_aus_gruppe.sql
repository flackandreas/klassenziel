-- Kategorien, die WebUntis einer Kategoriegruppe "positiv" oder "negativ"
-- zuordnet, zaehlen in diese Richtung, ohne dass die Verwaltung sie erst
-- einstellen muss. Neue Kategorien traegt der Import seitdem so ein
-- (Kategoriezuordnung::ausGruppe). Diese Datei holt das fuer Kategorien nach,
-- die vorher als "pruefen" eingetragen wurden - etwa "positiv/Schüler*in".
--
-- Nur Kategorien, die noch auf "pruefen" stehen und deren Vorfaelle alle aus
-- derselben Gruppe kommen. Die Stufe steht vor dem Schraegstrich ("groß/..."),
-- sonst ist sie klein. Alles laesst sich unter Vorgaben wieder aendern.

UPDATE kategorie_vorgaben kv
   SET kv.richtung = 'positiv',
       kv.stufe = CASE LOWER(TRIM(SUBSTRING_INDEX(kv.webuntis_kategorie, '/', 1)))
                      WHEN 'groß' THEN 'gross'
                      WHEN 'gross' THEN 'gross'
                      WHEN 'mittel' THEN 'mittel'
                      ELSE 'klein'
                  END
 WHERE kv.richtung = 'pruefen'
   AND EXISTS (SELECT 1 FROM vorfaelle v
                WHERE v.webuntis_kategorie = kv.webuntis_kategorie AND v.kategoriegruppe LIKE '%positiv%')
   AND NOT EXISTS (SELECT 1 FROM vorfaelle v
                    WHERE v.webuntis_kategorie = kv.webuntis_kategorie
                      AND (v.kategoriegruppe NOT LIKE '%positiv%' OR v.kategoriegruppe LIKE '%neutral%'));

UPDATE kategorie_vorgaben kv
   SET kv.richtung = 'negativ',
       kv.stufe = CASE LOWER(TRIM(SUBSTRING_INDEX(kv.webuntis_kategorie, '/', 1)))
                      WHEN 'groß' THEN 'gross'
                      WHEN 'gross' THEN 'gross'
                      WHEN 'mittel' THEN 'mittel'
                      ELSE 'klein'
                  END
 WHERE kv.richtung = 'pruefen'
   AND EXISTS (SELECT 1 FROM vorfaelle v
                WHERE v.webuntis_kategorie = kv.webuntis_kategorie AND v.kategoriegruppe LIKE '%negativ%')
   AND NOT EXISTS (SELECT 1 FROM vorfaelle v
                    WHERE v.webuntis_kategorie = kv.webuntis_kategorie
                      AND (v.kategoriegruppe NOT LIKE '%negativ%' OR v.kategoriegruppe LIKE '%neutral%'));

-- Jeder zwischengespeicherte Punktestand ist damit veraltet.
UPDATE vorgaben SET version = version + 1 WHERE id = 1;
