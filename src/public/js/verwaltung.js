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
})();
