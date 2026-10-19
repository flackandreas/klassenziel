FROM php:8.2-apache

# Der Code liegt im Abbild, nicht in einem eingehaengten Verzeichnis - wie
# beim Portal. Ein bind-mount verdeckt das Verzeichnis aus dem Abbild samt der
# dort gesetzten Rechte; daran sind in dieser Familie schon drei Module still
# gescheitert. Eingehaengt wird nur die Ablage.
#
# Bewusst ohne apt-get: pdo_mysql braucht keine Systembibliothek, sodium, curl
# und mbstring bringt das Abbild schon mit. Unicode-NFC kommt aus einem
# PHP-Polyfill statt aus ext-intl - das spart rund 30 MB ICU auf dem Pi.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

# Nur public/ wird ausgeliefert. Code, Vorlagen und Ablage liegen darueber.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/;s/^ServerSignature .*/ServerSignature Off/' \
        /etc/apache2/conf-available/security.conf

# PHP: Fehler ins Log statt in die Antwort, keine Version im Kopf.
#
# variables_order mit "E", damit die Werte aus compose in $_ENV stehen und eine
# zufaellig mitgebaute src/.env sie nicht verdraengt (siehe php-modul.ini im
# Portal).
#
# 2 MB reichen: ein Export ueber ein ganzes Schuljahr hat wenige hundert KB.
COPY docker/php.ini /usr/local/etc/php/conf.d/klassenziel.ini

# Apache: das Zugriffsprotokoll traegt keine geheimen Links.
#
# Der VirtualHost des Abbilds hat ein eigenes CustomLog und wuerde damit die
# Regel aus klassenziel.conf uebergehen. Ohne eigenes erbt er die des Servers.
COPY docker/apache.conf /etc/apache2/conf-available/klassenziel.conf
RUN sed -ri '/CustomLog/d' /etc/apache2/sites-available/000-default.conf \
    && a2disconf other-vhosts-access-log \
    && a2enconf klassenziel

COPY src/ /var/www/html/

RUN mkdir -p /var/www/html/storage/cache \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod 2770 /var/www/html/storage

COPY docker-entrypoint.sh /usr/local/bin/klassenziel-entrypoint.sh
RUN chmod +x /usr/local/bin/klassenziel-entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://localhost/healthz") ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/klassenziel-entrypoint.sh"]
CMD ["apache2-foreground"]
