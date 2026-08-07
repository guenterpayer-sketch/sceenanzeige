<?php
/**
 * admin/termin-aktion.php
 *
 * AJAX-Endpunkt für Kalender-Termine (Schritt 29, Etappe C) — wird vom
 * Termin-Dialog in wochenplan.php (assets/js/admin/wochenplan.js) per
 * fetch() aufgerufen. Sofort-Speichern: jede Aktion wirkt direkt in der DB.
 *
 * Aktionen (POST):
 *   speichern  ids[] (bestehende Zeilen der Termin-Gruppe, leer = neu),
 *              monitor_ids[], playlist_id, datum_von, datum_bis,
 *              von, bis (beide leer = ganztags), prio, dauer_sek
 *              → Gruppe ersetzen: alte Zeilen löschen, je Monitor eine neue.
 *   loeschen   ids[] → Zeilen der Termin-Gruppe löschen.
 *
 * Antwort: JSON { ok: true, termine: [...] } — termine ist die frische
 * Liste der übergebenen Woche (wochen_start), damit der Kalender ohne
 * Neuladen aktualisieren kann. Fehler: { ok: false, fehler: "..." }.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function antwort(array $daten): never
{
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

function fehler(string $meldung): never
{
    antwort(['ok' => false, 'fehler' => $meldung]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    fehler('Nur POST erlaubt.');
}

// Woche für die aktualisierte Termin-Liste in der Antwort
$wochenStart = (string)($_POST['wochen_start'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wochenStart)) {
    fehler('Ungültige Wochenangabe.');
}
$wochenEnde = (new DateTimeImmutable($wochenStart))->modify('+6 days')->format('Y-m-d');

function termine_antwort(string $wochenStart, string $wochenEnde): never
{
    antwort(['ok' => true, 'termine' => Monitor::termineFuerKalender($wochenStart, $wochenEnde)]);
}

$aktion = (string)($_POST['aktion'] ?? '');
$ids    = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));

if ($aktion === 'loeschen') {
    if (empty($ids)) { fehler('Kein Termin angegeben.'); }
    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    get_pdo()->prepare("DELETE FROM monitor_termine WHERE id IN ($platzhalter)")->execute($ids);
    termine_antwort($wochenStart, $wochenEnde);
}

if ($aktion === 'speichern') {
    // --- Eingaben validieren ---
    $playlistId = (int)($_POST['playlist_id'] ?? 0);
    if ($playlistId <= 0 || !Playlist::find($playlistId)) {
        fehler('Bitte eine gültige Playlist wählen.');
    }

    $monitorIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['monitor_ids'] ?? []))
    )));
    $gueltigeMonitore = [];
    foreach (Monitor::listAll() as $m) { $gueltigeMonitore[(int)$m['id']] = true; }
    $monitorIds = array_values(array_filter($monitorIds, static fn($id) => isset($gueltigeMonitore[$id])));
    if (empty($monitorIds)) {
        fehler('Bitte mindestens einen Monitor auswählen.');
    }

    $datumVon = (string)($_POST['datum_von'] ?? '');
    $datumBis = (string)($_POST['datum_bis'] ?? '');
    foreach ([$datumVon, $datumBis] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || DateTimeImmutable::createFromFormat('Y-m-d', $d) === false) {
            fehler('Bitte gültige Datumsangaben machen.');
        }
    }
    if ($datumVon > $datumBis) {
        fehler('Das Von-Datum muss vor (oder gleich) dem Bis-Datum liegen.');
    }

    $von = (string)($_POST['von'] ?? '');
    $bis = (string)($_POST['bis'] ?? '');
    if (($von === '') !== ($bis === '')) {
        fehler('Bitte Von- UND Bis-Uhrzeit angeben oder beide leer lassen (ganztags).');
    }
    if ($von !== '') {
        if (!preg_match('/^\d{2}:\d{2}$/', $von) || !preg_match('/^\d{2}:\d{2}$/', $bis)) {
            fehler('Ungültige Uhrzeit.');
        }
        if ($von >= $bis) {
            fehler('Die Von-Uhrzeit muss vor der Bis-Uhrzeit liegen.');
        }
    }

    $prio  = max(0, (int)($_POST['prio'] ?? 1));
    $dauer = max(10, (int)($_POST['dauer_sek'] ?? 300));

    // --- Gruppe ersetzen: alte Zeilen weg, je Monitor eine neue ---
    $pdo = get_pdo();
    $pdo->beginTransaction();
    try {
        if (!empty($ids)) {
            $platzhalter = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM monitor_termine WHERE id IN ($platzhalter)")->execute($ids);
        }
        $ins = $pdo->prepare(
            'INSERT INTO monitor_termine
                (monitor_id, playlist_id, datum_von, datum_bis, von_uhrzeit, bis_uhrzeit, prioritaet, dauer_sek)
             VALUES (:mid, :pid, :dvon, :dbis, :von, :bis, :prio, :dauer)'
        );
        foreach ($monitorIds as $mid) {
            $ins->execute([
                ':mid'   => $mid,
                ':pid'   => $playlistId,
                ':dvon'  => $datumVon,
                ':dbis'  => $datumBis,
                ':von'   => $von !== '' ? $von . ':00' : null,
                ':bis'   => $bis !== '' ? $bis . ':00' : null,
                ':prio'  => $prio,
                ':dauer' => $dauer,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fehler('Speichern fehlgeschlagen: ' . $e->getMessage());
    }
    termine_antwort($wochenStart, $wochenEnde);
}

fehler('Unbekannte Aktion.');
