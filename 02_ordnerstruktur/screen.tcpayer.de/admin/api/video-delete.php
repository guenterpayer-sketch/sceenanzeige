<?php
/**
 * admin/api/video-delete.php
 *
 * JSON-Endpoint zum Löschen eines Videothek-Eintrags. Löscht nur, wenn das
 * Video von keiner Modul-Instanz mehr verwendet wird (Prüfung in
 * Videothek::delete).
 *
 * Erwartet POST mit "id". Antwort: { ok:true } oder { ok:false, error:"..." }.
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

$res = Videothek::delete($id);
if (!$res['ok']) {
    antwort(['ok' => false, 'error' => $res['error'] ?? 'Löschen fehlgeschlagen.'], 409);
}
antwort(['ok' => true]);
