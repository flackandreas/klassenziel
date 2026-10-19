/*
 * Lehrerbereich von Klassenziel.
 *
 * Nur, was ohne Skript nicht geht. Alles andere funktioniert auch mit
 * abgeschaltetem JavaScript - die Content-Security-Policy erlaubt ohnehin kein
 * Skript im HTML selbst.
 */
(function () {
    'use strict';

    // Link kopieren. Ohne Zwischenablage-Schnittstelle (etwa ueber http im
    // Schulnetz) bleibt das Markieren des Felds - kopieren geht dann von Hand.
    document.querySelectorAll('[data-kopieren]').forEach(function (knopf) {
        knopf.addEventListener('click', function () {
            var feld = document.getElementById(knopf.getAttribute('data-kopieren'));
            if (!feld) {
                return;
            }

            var bestaetigen = function () {
                var vorher = knopf.textContent;
                knopf.textContent = knopf.getAttribute('data-kopiert-text') || 'Kopiert';
                window.setTimeout(function () { knopf.textContent = vorher; }, 2000);
            };

            feld.select();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(feld.value).then(bestaetigen, function () {});
            } else {
                try {
                    if (document.execCommand('copy')) {
                        bestaetigen();
                    }
                } catch (e) {
                    // Markiert ist das Feld trotzdem.
                }
            }
        });
    });

    // Textbausteine: ein Klick setzt den Text ins Kurztextfeld. Ohne Skript
    // bietet das Feld dieselben Bausteine ueber die Datalist an.
    document.querySelectorAll('[data-baustein]').forEach(function (knopf) {
        knopf.addEventListener('click', function () {
            var feld = document.getElementById(knopf.getAttribute('data-ziel'));
            if (feld) {
                feld.value = knopf.getAttribute('data-baustein');
                feld.focus();
            }
        });
    });

    // Verlaufsgrafik: Fadenkreuz und Tooltip. Die Werte stehen auch in der
    // Tabelle darunter - der Tooltip ergaenzt, er ersetzt nichts.
    document.querySelectorAll('svg.verlauf').forEach(function (svg) {
        var daten;
        try {
            daten = JSON.parse(svg.getAttribute('data-verlauf') || '');
        } catch (e) {
            return;
        }
        if (!daten || !daten.x || daten.x.length === 0) {
            return;
        }

        var kreuz = svg.querySelector('.verlauf__kreuz');
        var flaeche = svg.querySelector('.verlauf__flaeche');
        var tooltip = svg.parentNode.querySelector('.verlauf-tooltip');
        var aktuell = daten.x.length - 1;

        var zeige = function (index) {
            aktuell = Math.max(0, Math.min(daten.x.length - 1, index));
            var x = daten.x[aktuell];
            kreuz.setAttribute('x1', x);
            kreuz.setAttribute('x2', x);
            kreuz.setAttribute('visibility', 'visible');

            // Werte zuerst, Namen dahinter; alles ueber textContent.
            tooltip.textContent = '';
            var kopf = document.createElement('div');
            kopf.className = 'verlauf-tooltip__datum';
            kopf.textContent = 'Woche bis ' + daten.datum[aktuell];
            tooltip.appendChild(kopf);

            daten.reihen.forEach(function (reihe) {
                var zeile = document.createElement('div');
                zeile.className = 'verlauf-tooltip__zeile';
                var linie = document.createElement('span');
                linie.className = 'verlauf-tooltip__linie';
                linie.style.backgroundColor = reihe.farbe;
                var wert = document.createElement('strong');
                wert.textContent = String(reihe.werte[aktuell]);
                var name = document.createElement('span');
                name.textContent = ' ' + reihe.name;
                zeile.appendChild(linie);
                zeile.appendChild(wert);
                zeile.appendChild(name);
                tooltip.appendChild(zeile);
            });

            tooltip.hidden = false;
            var anteil = x / svg.viewBox.baseVal.width;
            tooltip.classList.toggle('verlauf-tooltip--links', anteil > 0.6);
            tooltip.style.left = (anteil * 100) + '%';
        };

        var verberge = function () {
            kreuz.setAttribute('visibility', 'hidden');
            tooltip.hidden = true;
        };

        var naechster = function (ereignis) {
            var punkt = svg.createSVGPoint();
            punkt.x = ereignis.clientX;
            punkt.y = ereignis.clientY;
            var matrix = svg.getScreenCTM();
            if (!matrix) {
                return aktuell;
            }
            var imSvg = punkt.matrixTransform(matrix.inverse());
            var bester = 0;
            daten.x.forEach(function (x, i) {
                if (Math.abs(x - imSvg.x) < Math.abs(daten.x[bester] - imSvg.x)) {
                    bester = i;
                }
            });
            return bester;
        };

        flaeche.addEventListener('pointermove', function (e) { zeige(naechster(e)); });
        flaeche.addEventListener('pointerleave', verberge);

        // Tastatur: dieselben Werte mit den Pfeiltasten.
        svg.setAttribute('tabindex', '0');
        svg.addEventListener('focus', function () { zeige(aktuell); });
        svg.addEventListener('blur', verberge);
        svg.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { zeige(aktuell - 1); e.preventDefault(); }
            if (e.key === 'ArrowRight') { zeige(aktuell + 1); e.preventDefault(); }
            if (e.key === 'Home') { zeige(0); e.preventDefault(); }
            if (e.key === 'End') { zeige(daten.x.length - 1); e.preventDefault(); }
        });
    });
})();
