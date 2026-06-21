<?php
/**
 * modules/registry.php
 *
 * Zentrale Liste aller AKTIVEN Inhalts-Module (Plugin-System, siehe
 * Abschnitt 4 der Projektdokumentation).
 *
 * Stand Schritt 4: "ankuendigung" wurde bewusst NICHT aufgenommen (siehe
 * Rücksprache im Chat) — würde sich mit dem eigenständigen Ticker-System
 * überschneiden, wird nicht gebraucht. Die fünf verbleibenden Module sind
 * fertig implementiert. "stundenplan" benötigt vor dem ersten echten
 * Live-Einsatz noch eine angepasste SQL-Query (siehe TODO in
 * proxies/nc.php) — der Eintrag bleibt trotzdem registriert, damit das
 * Modul im Backend bereits sichtbar/konfigurierbar ist.
 */

declare(strict_types=1);

return [
    'uhrzeit',
    'bild',
    'stundenplan',
    'community',
    'song',
];