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
define('DB_NAME', 'd04768bb');
define('DB_USER', 'd04768bb');
define('DB_PASS', 'xxx'); // Passwort ist ind der Orginal Datei, die auf dem Server liegt eingetragen
define('DB_CHARSET', 'utf8mb4');

// ----------------------------------------------------------------------------
// Pfade
// ----------------------------------------------------------------------------
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('UPLOADS_URL', 'https://screen.tcpayer.de/uploads');

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
