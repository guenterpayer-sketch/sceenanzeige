<?php
/**
 * admin/api/video-update.php
 *
 * POST: id, original_name, dauer_sek (optional)
 * Aktualisiert Anzeigename + Laufzeit eines Video-Eintrags.
 */

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$id   = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['original_name'] ?? ''));
$dauer = isset($_POST['dauer_sek']) && $_POST['dauer_sek'] !== '' ? (int)$_POST['dauer_sek'] : null;

if ($id <= 0 || $name === '') {
    echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter.']);
    exit;
}

$eintrag = Videothek::find($id);
if ($eintrag === null) {
    echo json_encode(['ok' => false, 'error' => 'Video nicht gefunden.']);
    exit;
}

Videothek::update($id, $name, $dauer);
$aktuell = Videothek::find($id);

echo json_encode(['ok' => true, 'eintrag' => $aktuell]);
