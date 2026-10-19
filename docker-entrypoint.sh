#!/bin/sh
#
# Bereitet Ablage und Datenbank vor, bevor Apache startet.
#
# 1. storage/ wird zur Laufzeit eingehaengt. Ein Volume verdeckt das
#    Verzeichnis aus dem Abbild samt der dort gesetzten Rechte - ohne diesen
#    Schritt kann Klassenziel seinen Zwischenspeicher nicht anlegen.
#
# 2. Die Migrationen laufen als www-data, nicht als root. Sonst gehoerte der
#    Migrationsvermerk hinterher root.

set -e

# Zeitzone fuer PHP aus der Umgebung uebernehmen. TZ stellt nur die Uhr des
# Containers; ohne date.timezone rechnet PHP in UTC, und der Countdown sprang
# um 22 Uhr auf den naechsten Tag.
printf 'date.timezone = %s\n' "${TZ:-Europe/Berlin}" > /usr/local/etc/php/conf.d/zeitzone.ini

ABLAGE=/var/www/html/storage

mkdir -p "$ABLAGE/cache/twig" "$ABLAGE/cache/stand" 2>/dev/null || true

if chown -R www-data:www-data "$ABLAGE" 2>/dev/null \
    && chmod -R u+rwX,g+rwX,o-rwx "$ABLAGE" 2>/dev/null \
    && find "$ABLAGE" -type d -exec chmod g+s {} + 2>/dev/null
then
    :
else
    echo "Klassenziel: WARNUNG - $ABLAGE ist nicht beschreibbar." >&2
fi

if [ -z "${KLASSENZIEL_SCHLUESSEL:-}" ]; then
    echo "Klassenziel: WARNUNG - KLASSENZIEL_SCHLUESSEL fehlt." >&2
    echo "Klassenziel: Ohne ihn lassen sich weder Exporte einlesen noch Links erzeugen." >&2
    echo "Klassenziel: Erzeugen mit: openssl rand -base64 32" >&2
fi

if [ "${AUTO_MIGRATE:-1}" = "1" ]; then
    if ! su -s /bin/sh www-data -c "php /var/www/html/bin/klassenziel.php migrieren"; then
        echo "Klassenziel: WARNUNG - die Migrationen sind nicht durchgelaufen." >&2
        echo "Klassenziel: Der Webserver startet trotzdem; Einzelheiten stehen darueber." >&2
    fi
fi

exec docker-php-entrypoint "$@"
