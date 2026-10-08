<?php
/**
 * proxies/veranstaltung-kategorien.php
 *
 * Liefert die Kategorien des Veranstaltungskalenders auf tcpayer.de für den
 * Admin-Instanz-Editor (Modul veranstaltung, Kategorie-Checkboxen).
 * Öffentliche Daten, kein Key — Muster wie proxies/nc-locations.php.
 *
 * Liefert: { ok: true, kategorien: [ {id, name, anzahl} ] }
 *      bzw. { ok: false, error: "…" }
 */

declare(strict_types=1);

require __DIR__ . '/../includes/VeranstaltungKategorien.php';

header('Content-Type: application/json; charset=utf-8');

$liste = VeranstaltungKategorien::laden();

if ($liste === null) {
    echo json_encode(['ok' => false, 'error' => 'Kategorien konnten nicht von tcpayer.de geladen werden.'],
        JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'kategorien' => $liste], JSON_UNESCAPED_UNICODE);
