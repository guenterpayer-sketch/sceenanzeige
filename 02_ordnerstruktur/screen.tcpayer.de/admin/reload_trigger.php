<?php
/**
 * admin/reload_trigger.php
 *
 * Setzt reload_at = NOW() für alle Monitore. Das Monitor-Frontend erkennt
 * die Änderung beim nächsten Poll (~60 s) und lädt sich neu.
 *
 * Leert zusätzlich den Nimbuscloud-Cache (includes/NcCache.php): Die
 * NC-Daten werden sonst bis Mitternacht gehalten, um das API-Kontingent zu
 * schonen. „Monitore neu laden" ist damit der bewusste Weg, nach einer
 * Stundenplan-Änderung frische Daten zu ziehen — die Monitore holen sie
 * beim Reload direkt mit.
 *
 * Geleert werden dabei BEIDE Ebenen: die gecachten Daten (Stundenplan +
 * Standorte) und die kurz gemerkten Fehler. Letzteres ist der Notausgang,
 * falls die Nimbuscloud gerade klemmt und der Server sie deshalb in Ruhe
 * lässt — ein Klick hebt die Schonfrist sofort auf.
 *
 * Nur POST erlaubt; Redirect zurück zur Referrer-Seite.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/NcCache.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

Monitor::triggerReloadAlle();
NcCache::leeren();

$referrer = $_SERVER['HTTP_REFERER'] ?? 'monitore.php';
header('Location: ' . $referrer . (str_contains($referrer, '?') ? '&' : '?') . 'reload_ok=1');
exit;
