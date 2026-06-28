<?php
/**
 * admin/reload_trigger.php
 *
 * Setzt reload_at = NOW() für alle Monitore. Das Monitor-Frontend erkennt
 * die Änderung beim nächsten Poll (~60 s) und lädt sich neu.
 *
 * Nur POST erlaubt; Redirect zurück zur Referrer-Seite.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

Monitor::triggerReloadAlle();

$referrer = $_SERVER['HTTP_REFERER'] ?? 'monitore.php';
header('Location: ' . $referrer . (str_contains($referrer, '?') ? '&' : '?') . 'reload_ok=1');
exit;
