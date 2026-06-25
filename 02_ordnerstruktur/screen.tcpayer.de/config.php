<?php
/**
 * config.php
 *
 * Zentrale Konfiguration für das Backend (screen.tcpayer.de).
 * Wird durch die übergeordnete .htaccess vor direktem Browserzugriff
 * geschützt (siehe .htaccess: <FilesMatch "^config\.php$">).
 *
 * WICHTIG: Diese Datei NICHT ins öffentliche Git-Repo einchecken, falls
 * Versionskontrolle genutzt wird. Reale Zugangsdaten erst beim Deployment
 * auf all-inkl eintragen (siehe Schritt 11: Deployment-Guide).
 */

declare(strict_types=1);

// ----------------------------------------------------------------------------
// Datenbank (all-inkl KAS: Zugangsdaten aus dem Datenbank-Bereich übernehmen)
// ----------------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ----------------------------------------------------------------------------
// Pfade
// ----------------------------------------------------------------------------
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('UPLOADS_URL', 'https://screen.tcpayer.de/uploads');

// ----------------------------------------------------------------------------
// Nimbuscloud Legacy-API (stundenplan-Modul, siehe NC_Legacy_API_Stundenplan.md)
// Basis-URL der schul-spezifischen Instanz, OHNE abschließenden Slash.
// Es gibt genau EINEN Nimbuscloud-API-Key pro Schule (nicht pro Saal) — er
// liegt daher schulweit hier, serverseitig, und gelangt nie ans Frontend.
// Berechtigung des Keys: "Stundenplan — Lesezugriff".
// ----------------------------------------------------------------------------
define('NC_API_BASE', 'https://xyz.nimbuscloud.at/api/json/v1');
define('NC_API_KEY', '');

// ----------------------------------------------------------------------------
// FRET-API (song-Modul, siehe Projektzusammenfassung_Song_Anzeige.md)
// FRET_SCHOOL_ID ist sicherheitsrelevant (die FRET-API hat auch schreibende
// Endpunkte) und darf NIEMALS ans Frontend gelangen — daher serverseitig hier
// statt in den Modul-Instanz-Einstellungen (die ans Frontend übertragen werden).
// ----------------------------------------------------------------------------
define('FRET_API_BASE', 'https://fret-api.azurewebsites.net/api/v1');
define('FRET_SCHOOL_ID', '');

// ----------------------------------------------------------------------------
// Admin-Passwörter (Klartext; config.php ist per .htaccess geschützt)
//   REDAKTEUR_PASSWORT → Bibliothek, Mediathek, Playlists, Ticker
//   ADMIN_PASSWORT     → zusätzlich Monitore, FRET-Geräte
// Beim Deployment eigene sichere Passwörter eintragen.
// ----------------------------------------------------------------------------
define('REDAKTEUR_PASSWORT', 'redakteur');
define('ADMIN_PASSWORT',     'admin');

// ----------------------------------------------------------------------------
// PDO-Verbindung (wird von allen Backend-Skripten/Proxies eingebunden)
// ----------------------------------------------------------------------------
function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
