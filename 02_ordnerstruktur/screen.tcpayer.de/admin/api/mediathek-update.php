<?php
/**
 * admin/api/mediathek-update.php
 *
 * JSON-Endpoint zum Bearbeiten eines Bilds: Ordner-Zuordnung und Tags in
 * einem Schritt (vom "Bearbeiten"-Dialog der Galerie).
 *
 * POST:
 *   id        (int, Pflicht)
 *   ordner_id (int oder leer/0 = "Ohne Ordner")
 *   original_name (String, optional; leer = zurück auf technischen Dateinamen)
 *   tags      (String, kommagetrennt; leere/doppelte werden ignoriert)
 *
 * Antwort: { ok:true, ordner_id:int|null, original_name:string|null, tags:string[] }
 *          oder { ok:false, error }
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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    antwort(['ok' => false, 'error' => 'Ungültige id.'], 400);
}

$ordnerRoh = $_POST['ordner_id'] ?? '';
$ordnerId  = ctype_digit((string)$ordnerRoh) && (int)$ordnerRoh > 0 ? (int)$ordnerRoh : null;

$resMove = Mediathek::verschiebe($id, $ordnerId);
if (!$resMove['ok']) {
    antwort(['ok' => false, 'error' => $resMove['error']], 422);
}

$anzeigeName = null;
if (array_key_exists('original_name', $_POST)) {
    $resName = Mediathek::setzeAnzeigename($id, (string)$_POST['original_name']);
    $anzeigeName = $resName['original_name'] ?? null;
} else {
    $aktuell = Mediathek::find($id);
    $anzeigeName = $aktuell['original_name'] ?? null;
}

$tagsRoh = (string)($_POST['tags'] ?? '');
$tagNamen = $tagsRoh === '' ? [] : preg_split('/[,\n]/', $tagsRoh);
$gesetzteTags = MediathekTag::setzeTagsFuerBild($id, $tagNamen);

antwort(['ok' => true, 'ordner_id' => $ordnerId, 'original_name' => $anzeigeName, 'tags' => $gesetzteTags]);
