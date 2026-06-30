<?php
/**
 * admin/api/video-upload.php
 *
 * JSON-Endpoint für den Upload eigener Videodateien in der Videothek.
 * Erwartet pro Request EINE Datei im Feld "datei" sowie optional "dauer_sek"
 * (im Browser per <video>-Metadaten ermittelte Laufzeit, nur Schätzwert).
 *
 * Antwort:
 *   { ok:true, duplikat:bool, eintrag:{id, dateiname, original_name,
 *     dauer_sek, url} }
 *   { ok:false, error:"..." }
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

$dauerSek = isset($_POST['dauer_sek']) && ctype_digit((string)$_POST['dauer_sek'])
    ? (int)$_POST['dauer_sek'] : null;

$res = Videothek::speichereUpload($_FILES['datei'], $dauerSek);
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
        'dauer_sek'     => $e['dauer_sek'] !== null ? (int)$e['dauer_sek'] : null,
        'url'           => rtrim(UPLOADS_URL, '/') . '/' . rawurlencode($e['dateiname']),
    ],
]);
