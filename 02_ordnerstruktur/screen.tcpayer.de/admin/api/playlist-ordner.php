<?php
/**
 * admin/api/playlist-ordner.php
 *
 * JSON-Endpoint für die Playlist-Ordner-Verwaltung (Muster: api/ordner.php).
 * POST mit "action":
 *   create  + name            -> { ok, id, name }
 *   rename  + id + name       -> { ok }
 *   delete  + id              -> { ok }
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

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'create':
        $res = PlaylistOrdner::create((string)($_POST['name'] ?? ''));
        if (!$res['ok']) {
            antwort(['ok' => false, 'error' => $res['error']], 422);
        }
        $ordner = PlaylistOrdner::find($res['id']);
        antwort(['ok' => true, 'id' => (int)$ordner['id'], 'name' => $ordner['name']]);
        break;

    case 'rename':
        $res = PlaylistOrdner::rename((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
        antwort($res['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $res['error']], $res['ok'] ? 200 : 422);
        break;

    case 'delete':
        $res = PlaylistOrdner::delete((int)($_POST['id'] ?? 0));
        antwort($res['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $res['error']], $res['ok'] ? 200 : 422);
        break;

    default:
        antwort(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
}
