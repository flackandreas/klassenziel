/*
 * Oeffentliche Klassenseite.
 *
 * Die Seite steht ohne dieses Skript vollstaendig da. Es tut drei Dinge:
 *   - der Balken waechst beim Laden vom zuletzt gesehenen zum aktuellen
 *     Stand (der zuletzt gesehene liegt nur auf diesem Geraet, im
 *     localStorage; mit prefers-reduced-motion ohne Animation),
 *   - alle fuenf Minuten holt es den Stand neu und aendert nur, was sich
 *     geaendert hat - ohne Neuladen, ohne Flackern,
 *   - "Weitere laden" haengt Eintraege an, statt die Seite neu zu laden.
 *
 * Alle Texte aus den Daten kommen ueber textContent ins Dokument.
 */
(function () {
    'use strict';

    var AKTUALISIERUNG = 5 * 60 * 1000;

    var body = document.body;
    var merker = body.getAttribute('data-merker') || 'klassenziel';
    var standAktuell = parseInt(body.getAttribute('data-stand'), 10);
    var zielAktuell = parseInt(body.getAttribute('data-ziel'), 10);
    var ruhig = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var feld = function (name) {
        return document.querySelector('[data-feld="' + name + '"]');
    };

    var setzeText = function (name, text) {
        var element = feld(name);
        if (element && element.textContent !== text) {
            element.textContent = text;
        }
    };

    var lies = function () {
        try {
            var wert = window.localStorage.getItem(merker);
            return wert !== null && /^\d{1,3}$/.test(wert) ? parseInt(wert, 10) : null;
        } catch (e) {
            return null;
        }
    };

    var merke = function (wert) {
        try {
            window.localStorage.setItem(merker, String(wert));
        } catch (e) {
            // Privates Fenster oder gesperrter Speicher: dann ohne Animation.
        }
    };

    // --- Balken ---------------------------------------------------------

    var setzeBalken = function (von, nach) {
        var fuellung = feld('fuellung');
        if (!fuellung) {
            return;
        }
        if (ruhig || von === null || von === nach) {
            fuellung.style.width = nach + '%';
            return;
        }
        fuellung.style.transition = 'none';
        fuellung.style.width = von + '%';
        void fuellung.offsetWidth;
        fuellung.style.transition = '';
        window.requestAnimationFrame(function () {
            fuellung.style.width = nach + '%';
        });
    };

    setzeBalken(lies(), standAktuell);
    merke(standAktuell);

    // --- Eintraege --------------------------------------------------------

    var liste = feld('eintraege');

    var span = function (klasse, text) {
        var element = document.createElement('span');
        element.className = klasse;
        element.textContent = text;
        return element;
    };

    var eintragElement = function (e) {
        var li = document.createElement('li');
        li.className = 'eintrag eintrag--' + (e.art === 'wochenbonus' ? 'wochenbonus' : 'vorfall');

        var meta = e.datum_text + (e.fach ? ' · ' + e.fach : '') + (e.stufe ? ' · ' + e.stufe : '');
        li.appendChild(span('eintrag__datum', e.datum_text));
        li.appendChild(span('eintrag__fach', e.fach));
        li.appendChild(span('eintrag__meta nur-handy', meta));
        li.appendChild(span('eintrag__text', e.text));
        li.appendChild(span(
            'abzeichen abzeichen--' + (e.punkte >= 0 ? 'positiv' : 'negativ'),
            e.punkte_text + (e.stufe ? ' · ' + e.stufe : '')
        ));

        return li;
    };

    var gezeigt = function () {
        return liste ? liste.querySelectorAll('.eintrag:not(.eintrag--leer)').length : 0;
    };

    // --- Aktualisierung -----------------------------------------------------

    var adresse = function (abfrage) {
        return window.location.pathname.replace(/\/+$/, '') + '/stand.json?' + abfrage;
    };

    var countdown = function (c) {
        var box = feld('countdown');
        if (!box) {
            return;
        }
        var teile = {
            laeuft: ['noch', c.tage + (c.tage === 1 ? ' Tag' : ' Tage'), 'bis zum Stichtag ' + c.stichtag],
            heute: [' ', 'Heute', 'ist der Stichtag (' + c.stichtag + ')'],
            erreicht: [' ', 'Stichtag erreicht', c.stichtag || ''],
            offen: [' ', 'Stichtag folgt', ' ']
        }[c.zustand] || [' ', '', ' '];

        var p = box.querySelectorAll('p');
        if (p.length === 3) {
            p[0].textContent = teile[0];
            p[1].textContent = teile[1];
            p[1].className = 'countdown__zahl' + (c.zustand === 'laeuft' || c.zustand === 'heute' ? '' : ' countdown__zahl--text');
            p[2].textContent = teile[2];
        }
    };

    var zeige = function (d) {
        setzeText('stand', String(d.stand));
        setzeText('status', d.ziel_erreicht
            ? 'Ziel erreicht – jetzt halten!'
            : 'Noch ' + d.fehlt + (d.fehlt === 1 ? ' Punkt' : ' Punkte') + ' bis zum Ziel');
        var status = feld('status');
        if (status) {
            status.classList.toggle('stand__status--erreicht', d.ziel_erreicht);
        }

        if (d.stand !== standAktuell) {
            setzeBalken(standAktuell, d.stand);
            standAktuell = d.stand;
            merke(d.stand);
        }

        if (d.ziel !== zielAktuell) {
            document.querySelectorAll('.balken__ziel, .skala__ziel').forEach(function (element) {
                element.style.left = d.ziel + '%';
            });
            var beschriftung = document.querySelector('.skala__ziel');
            if (beschriftung) {
                beschriftung.textContent = 'Ziel: ' + d.ziel;
            }
            zielAktuell = d.ziel;
        }

        var balken = feld('balken');
        if (balken) {
            balken.setAttribute('aria-label', d.stand + ' von 100 Punkten, Ziel ' + d.ziel);
        }

        countdown(d.countdown);

        setzeText('woche-bilanz', d.woche.bilanz_text + (Math.abs(d.woche.bilanz) === 1 ? ' Punkt' : ' Punkte'));
        setzeText('woche-zahlen', d.woche.positiv + ' positive, ' + d.woche.negativ + ' negative Einträge');
        setzeText('wertung', 'klein ' + d.wertung.klein + ' · mittel ' + d.wertung.mittel + ' · groß ' + d.wertung.gross + ' Punkte');
        setzeText('bonus', d.wertung.wochenbonus > 0
            ? '+' + d.wertung.wochenbonus + ' für jede Schulwoche ohne großen Eintrag'
            : 'kein Wochenbonus');
        setzeText('quelle', d.quelle ? 'Quelle: WebUntis-Export vom ' + d.quelle : 'Noch kein Export eingelesen');
        setzeText('quelle-kurz', d.quelle || '');

        if (liste) {
            // Nur neu aufbauen, wenn sich etwas geaendert hat - sonst bleibt
            // die Liste stehen, wie sie ist.
            var neu = document.createDocumentFragment();
            d.eintraege.forEach(function (e) {
                neu.appendChild(eintragElement(e));
            });
            var vorher = Array.prototype.map.call(liste.children, function (li) { return li.textContent; }).join('|');
            var nachher = Array.prototype.map.call(neu.children, function (li) { return li.textContent; }).join('|');
            if (d.eintraege.length > 0 && vorher !== nachher) {
                liste.textContent = '';
                liste.appendChild(neu);
            }
        }
    };

    var aktualisiere = function () {
        var anzahl = Math.max(gezeigt(), parseInt(body.getAttribute('data-anzahl'), 10) || 5);
        window.fetch(adresse('anzahl=' + anzahl), { cache: 'no-store', credentials: 'omit' })
            .then(function (antwort) { return antwort.ok ? antwort.json() : null; })
            .then(function (daten) { if (daten) { zeige(daten); } })
            .catch(function () {
                // Netz weg, Server neu gestartet: der alte Stand bleibt stehen.
            });
    };

    if (window.fetch) {
        window.setInterval(aktualisiere, AKTUALISIERUNG);
    }

    // --- Weitere laden --------------------------------------------------------

    var mehr = document.querySelector('.eintraege__mehr');
    if (mehr && window.fetch && liste) {
        mehr.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            window.fetch(adresse('anzahl=20&versatz=' + gezeigt()), { cache: 'no-store', credentials: 'omit' })
                .then(function (antwort) { return antwort.ok ? antwort.json() : null; })
                .then(function (daten) {
                    if (!daten) {
                        return;
                    }
                    daten.eintraege.forEach(function (e) {
                        liste.appendChild(eintragElement(e));
                    });
                    if (!daten.weitere) {
                        mehr.parentNode.removeChild(mehr);
                    }
                })
                .catch(function () {
                    window.location.href = mehr.getAttribute('href');
                });
        });
    }
})();
