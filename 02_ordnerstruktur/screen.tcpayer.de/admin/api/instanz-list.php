<?php
/**
 * admin/api/instanz-list.php
 *
 * Liefert die Modul-Instanzen aus der Bibliothek als JSON für den Spalten-
 * Picker im Playlist-Editor (analog api/mediathek-list.php für den Bild-Picker).
 *
 * Optionaler GET-Filter: typ (Modul-Typ, z.B. "bild").
 *
 * Antwort: { ok:true,
 *            instanzen:[{id, name, modul_typ, typ_label, icon, aktiv}],
 *            typen:[{id, label, icon, anzahl}] }
 */

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$typFilterRaw = $_GET['typ'] ?? '';
$typFilter = (is_string($typFilterRaw) && ModuleRegistry::exists($typFilterRaw)) ? $typFilterRaw : null;

$module = ModuleRegistry::getAll();

$alle = ModulInstanz::listAll();           // alle Typen, für die Typ-Zählung
$anzahlProTyp = [];
foreach ($alle as $inst) {
    $anzahlProTyp[$inst['modul_typ']] = ($anzahlProTyp[$inst['modul_typ']] ?? 0) + 1;
}

$instanzen = [];
foreach ($alle as $inst) {
    if ($typFilter !== null && $inst['modul_typ'] !== $typFilter) {
        continue;
    }
    $meta = $module[$inst['modul_typ']] ?? null;
    $instanzen[] = [
        'id'        => (int)$inst['id'],
        'name'      => $inst['name'],
        'modul_typ' => $inst['modul_typ'],
        'typ_label' => $meta['label'] ?? $inst['modul_typ'],
        'icon'      => $meta['icon'] ?? '',
        'aktiv'     => (bool)$inst['aktiv'],
    ];
}

$typen = [];
foreach ($module as $id => $meta) {
    $typen[] = [
        'id'     => $id,
        'label'  => $meta['label'] ?? $id,
        'icon'   => $meta['icon'] ?? '',
        'anzahl' => $anzahlProTyp[$id] ?? 0,
    ];
}

echo json_encode([
    'ok'        => true,
    'instanzen' => $instanzen,
    'typen'     => $typen,
], JSON_UNESCAPED_UNICODE);
