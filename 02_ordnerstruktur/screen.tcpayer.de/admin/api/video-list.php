<?php
/**
 * admin/api/video-list.php
 *
 * Liefert die Videothek als JSON für den Video-Picker im Instanz-Editor
 * (Modul "video", Eintragstyp "Datei hochladen").
 *
 * Antwort: { ok:true, videos:[{id, url, original_name, dateiname, dauer_sek}] }
 */

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$basis = rtrim(UPLOADS_URL, '/') . '/';
$videos = array_map(static function (array $v) use ($basis): array {
    return [
        'id'            => (int)$v['id'],
        'url'           => $basis . rawurlencode($v['dateiname']),
        'original_name' => $v['original_name'],
        'dateiname'     => $v['dateiname'],
        'dauer_sek'     => $v['dauer_sek'] !== null ? (int)$v['dauer_sek'] : null,
    ];
}, Videothek::listAll());

echo json_encode(['ok' => true, 'videos' => $videos], JSON_UNESCAPED_UNICODE);
