/*
 * Oeffentliche Klassenseite.
 *
 * Die Seite steht ohne dieses Skript vollstaendig da. Es tut drei Dinge:
 *   - die Linie des Punkteverlaufs zeichnet sich beim Laden einmal auf,
 *     danach erscheint ihr Endpunkt mit dem Stand. Ist seit dem letzten
 *     Besuch eine Smiley-Etappe neu erreicht (der zuletzt gesehene Stand
 *     liegt nur auf diesem Geraet, im localStorage), leuchtet sie danach
 *     auf und huepft einmal. Mit prefers-reduced-motion ohne Bewegung,
 *   - alle fuenf Minuten holt es den Stand neu und aendert nur, was sich
 *     geaendert hat. Hat sich der Verlauf geaendert - neuer Stand, eine
 *     weitere Woche, ein anderes Ziel -, laedt es die Seite neu: Die Linie
 *     zeichnet der Server, das Skript baut sie nicht nach,
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
    var punkteAktuell = parseInt(body.getAttribute('data-punkte'), 10);
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

    // --- Linie -----------------------------------------------------------

    var DAUER = 1400;

    var etappen = function () {
        return Array.prototype.slice.call(document.querySelectorAll('.etappe'));
    };
    var bei = function (etappe) {
        return parseInt(etappe.getAttribute('data-bei'), 10);
    };

    // Zeichnet jede sichtbare Linie einmal auf: Strichmuster in voller Laenge,
    // um die ganze Laenge verschoben, dann auf null - per CSSOM, die CSP
    // erlaubt kein style-Attribut. Danach wieder ohne Muster, damit die Linie
    // auch nach einer Groessenaenderung vollstaendig bleibt.
    var zeichneLinie = function () {
        document.querySelectorAll('svg[data-verlauf]').forEach(function (bild) {
            var linie = bild.querySelector('.verlauf__linie');
            var ende = bild.querySelector('.verlauf__ende');
            var laenge = 0;
            try {
                laenge = linie && bild.getBoundingClientRect().width > 0 ? linie.getTotalLength() : 0;
            } catch (e) {
                laenge = 0;
            }
            if (!(laenge > 0)) {
                return;
            }

            linie.style.strokeDasharray = laenge + ' ' + laenge;
            linie.style.strokeDashoffset = String(laenge);
            if (ende) {
                ende.classList.add('verlauf__ende--warten');
            }
            void linie.getBoundingClientRect();
            linie.style.transition = 'stroke-dashoffset ' + DAUER + 'ms cubic-bezier(.3, .7, .4, 1)';
            window.requestAnimationFrame(function () {
                linie.style.strokeDashoffset = '0';
            });
            window.setTimeout(function () {
                linie.style.transition = '';
                linie.style.strokeDasharray = '';
                linie.style.strokeDashoffset = '';
                if (ende) {
                    ende.classList.remove('verlauf__ende--warten');
                }
            }, DAUER + 50);
        });
    };

    // Seit dem letzten Besuch neu erreichte Etappen: erst grau, und wenn die
    // Linie fertig ist, leuchten sie auf und huepfen einmal.
    var feiereEtappen = function (von, nach) {
        if (von === null || nach <= von) {
            return;
        }
        etappen().forEach(function (etappe) {
            if (von < bei(etappe) && bei(etappe) <= nach) {
                etappe.classList.remove('etappe--erreicht');
                window.setTimeout(function () {
                    etappe.classList.add('etappe--erreicht', 'etappe--neu');
                }, DAUER);
            }
        });
    };

    if (!ruhig) {
        zeichneLinie();
        feiereEtappen(lies(), standAktuell);
    }
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
        // Neuer Stand, eine weitere Woche oder ein anderes Ziel veraendern die
        // Linie - dann die Seite neu laden, statt sie hier nachzubauen. Das
        // kommt hoechstens einmal am Tag vor, meist einmal in der Woche.
        if (d.ziel !== zielAktuell || d.stand !== standAktuell || d.verlauf.punkte.length !== punkteAktuell) {
            window.location.reload();
            return;
        }

        var s = d.stimmung;
        setzeText('stand', String(d.stand));
        setzeText('spruch', s.spruch);
        setzeText('status', s.status);
        var spruch = feld('spruch');
        if (spruch) {
            spruch.classList.toggle('stand__spruch--erreicht', d.ziel_erreicht);
        }
        var gesicht = feld('gesicht');
        if (gesicht) {
            for (var n = 0; n <= 4; n++) {
                gesicht.classList.toggle('stimmung--' + n, n === s.stufe);
            }
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
