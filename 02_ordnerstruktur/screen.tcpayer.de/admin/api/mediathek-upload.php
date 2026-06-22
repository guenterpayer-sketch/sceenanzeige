<?php
/**
 * admin/api/mediathek-upload.php
 *
 * JSON-Endpoint für den Drag&Drop-/Sammel-Upload der Mediathek. Erwartet pro
 * Request EINE Datei im Feld "datei" (das Frontend lädt mehrere Dateien
 * nacheinander hoch, um pro Bild eine eigene Neu/Duplikat-Rückmeldung zu
 * bekommen).
 *
 * Antwort:
 *   { ok:true, duplikat:bool, eintrag:{id, dateiname, original_name, breite,
 *     hoehe, url} }
 *   { ok:false, error:"..." }
 *
 * Gleicher Origin wie die Admin-Seite -> kein CORS nötig.
 */

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function antwort(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    antwort(['ok' => false, 'error' => 'Nur POST erlaubt.'], 405);
}
if (!isset($_FILES['datei'])) {
    antwort(['ok' => false, 'error' => 'Kein Datei-Feld "datei" übermittelt.'], 400);
}

$res = Mediathek::speichereUpload($_FILES['datei']);
if (!$res['ok']) {
    antwort(['ok' => false, 'error' => $res['error'] ?? 'Unbekannter Fehler.'], 422);
}

$e = $res['eintrag'];
antwort([
    'ok'       => true,
    'duplikat' => (bool)$res['duplikat'],
    'eintrag'  => [
        'id'            => (int)$e['id'],
        'dateiname'     => $e['dateiname'],
        'original_name' => $e['original_name'],
        'breite'        => $e['breite'] !== null ? (int)$e['breite'] : null,
        'hoehe'         => $e['hoehe'] !== null ? (int)$e['hoehe'] : null,
        'url'           => rtrim(UPLOADS_URL, '/') . '/' . rawurlencode($e['dateiname']),
    ],
]);
