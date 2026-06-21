<?php
/**
 * modules/registry.php
 *
 * Zentrale Liste aller Inhalts-Module (Plugin-System, siehe Abschnitt 4 der
 * Projektdokumentation). Neue Module werden hier per Eintrag registriert —
 * bestehender Code muss dafür NICHT angefasst werden.
 *
 * Jeder Eintrag verweist auf einen Ordner unter modules/<id>/, der mindestens
 * folgende Dateien enthält:
 *   - module.json    Metadaten + Einstellungsfelder (für das Backend-Formular)
 *   - backend.php    Formular-Logik im Backend (optional, falls über das
 *                     generische module.json-Formular hinausgehend nötig)
 *   - proxy.php       Serverseitiger Datenabruf (nur falls has_proxy = true)
 *   - frontend.js     Darstellung am Monitor
 *
 * Wird in Schritt 3/4 (Modul-Implementierung) inhaltlich befüllt.
 */

declare(strict_types=1);

return [
    'bild',
    'stundenplan',
    'ankuendigung',
    'community',
    'uhrzeit',
    'song',
];
