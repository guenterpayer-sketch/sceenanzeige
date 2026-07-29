/**
 * assets/js/admin/editor-core.js
 *
 * Gemeinsame Bausteine für die Admin-Editoren (instanz, playlist-editor,
 * monitor-zeitplan). Muss VOR dem jeweiligen Editor-Skript geladen werden.
 *
 * Grundsatz: wiederkehrendes Verhalten existiert genau EINMAL — ein Fix
 * hier gilt sofort für alle Editoren (kein Nachziehen an drei Stellen).
 */
window.TMAdmin = (function () {
    'use strict';

    /** HTML-Sonderzeichen escapen (für innerHTML-Aufbau). */
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /**
     * Dirty-Guard: warnt beim Verlassen der Seite vor ungespeicherten
     * Änderungen. Die EINZIGE Implementierung dieser Warnung.
     *
     * Gelernt aus dem Livebetrieb (beide Fixes hier zentral):
     *  - beforeunload braucht e.preventDefault() UND e.returnValue = '',
     *    sonst zeigen Chrome/Edge/Firefox keinen Dialog.
     *  - input/change zählen nur mit e.isTrusted (echte Nutzer-Eingabe) —
     *    programmatische Events beim Seitenaufbau würden sonst sofort
     *    „ungespeichert" melden.
     *
     * Rückgabe: { markiere, reset, istDirty } für DOM-Änderungen, die keine
     * Formular-Events auslösen (Zeile entfernen, Drag&Drop, Picker-Auswahl).
     */
    function dirtyGuard(form) {
        var dirty = false;

        form.addEventListener('input',  function (e) { if (e.isTrusted) { dirty = true; } });
        form.addEventListener('change', function (e) { if (e.isTrusted) { dirty = true; } });
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });

        return {
            markiere: function () { dirty = true; },
            reset:    function () { dirty = false; },
            istDirty: function () { return dirty; }
        };
    }

    return { escapeHtml: escapeHtml, dirtyGuard: dirtyGuard };
})();
