<?php
/**
 * admin/api/mediathek-list.php
 *
 * Liefert die Mediathek als JSON für den Bild-Picker im Instanz-Editor.
 * Optionale GET-Filter: ordner (int|"none"), q (Suchtext), tag (int).
 *
 * Antwort: { ok:true, bilder:[{id, url, original_name, dateiname, ordner_id,
 *            tags[]}], ordner:[{id,name,anzahl}], tags:[{id,name,anzahl}] }
 */

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$filter = [];
$ordner = $_GET['ordner'] ?? '';
if ($ordner === 'none') {
    $filter['ordner'] = 'none';
} elseif (ctype_digit((string)$ordner)) {
    $filter['ordner'] = (int)$ordner;
}
if (!empty($_GET['q']))  { $filter['suche'] = trim((string)$_GET['q']); }
if (!empty($_GET['tag']) && ctype_digit((string)$_GET['tag'])) { $filter['tag'] = (int)$_GET['tag']; }

$basis = rtrim(UPLOADS_URL, '/') . '/';
$bilder = array_map(static function (array $b) use ($basis): array {
    return [
        'id'            => (int)$b['id'],
        'url'           => $basis . rawurlencode($b['dateiname']),
        'original_name' => $b['original_name'],
        'dateiname'     => $b['dateiname'],
        'ordner_id'     => $b['ordner_id'] !== null ? (int)$b['ordner_id'] : null,
        'tags'          => $b['tags'] ?? [],
    ];
}, Mediathek::listAll($filter));

echo json_encode([
    'ok'     => true,
    'bilder' => $bilder,
    'ordner' => MediathekOrdner::listAllMitAnzahl(),
    'tags'   => MediathekTag::listAllMitAnzahl(),
], JSON_UNESCAPED_UNICODE);
