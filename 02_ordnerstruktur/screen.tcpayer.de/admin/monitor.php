<?php
/**
 * admin/monitor.php
 *
 * Zeitplan-Editor für EINEN Monitor (monitor-zentrisches Modell):
 *   monitor.php?id=<monitor_id>
 *
 * Hier wird festgelegt, welche Playlist wann auf diesem Monitor läuft:
 * dynamische Zeilen mit Playlist-Auswahl + Wochentagen (Toggle + Presets) +
 * von/bis-Uhrzeit + Priorität (höher gewinnt bei Überschneidung). Speichert
 * nach monitor_zeitplan.
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$monitor = $id > 0 ? Monitor::find($id) : null;
if (!$monitor) {
    http_response_code(404);
    admin_header('Zeitplan', 'monitore');
    echo '<p class="adm-flash adm-flash-fehler">Monitor nicht gefunden.</p>';
    admin_footer();
    exit;
}

$fehler = [];

$playlists = Playlist::listAll();
$gueltigePlaylists = [];
foreach ($playlists as $p) { $gueltigePlaylists[(int)$p['id']] = true; }

// --- Speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $eintraege = [];
    foreach (($_POST['zeitplan'] ?? []) as $z) {
        $pid  = (int)($z['playlist_id'] ?? 0);
        $tage = array_values(array_unique(array_filter(
            array_map('intval', (array)($z['tage'] ?? [])),
            static fn($d) => $d >= 1 && $d <= 7
        )));
        sort($tage);
        $von  = substr(trim((string)($z['von'] ?? '')), 0, 5);
        $bis  = substr(trim((string)($z['bis'] ?? '')), 0, 5);

        if ($pid <= 0 && empty($tage) && $von === '' && $bis === '') {
            continue; // komplett leere Zeile ignorieren
        }
        if ($pid <= 0 || !isset($gueltigePlaylists[$pid])) {
            $fehler[] = 'Jeder Zeitplan-Eintrag braucht eine gültige Playlist.';
            continue;
        }
        if (empty($tage)) {
            $fehler[] = 'Jeder Eintrag braucht mindestens einen Wochentag.';
            continue;
        }
        // Uhrzeit ist optional: entweder beide leer (= dauerhaft) ODER beide gesetzt mit von < bis.
        if (($von === '') !== ($bis === '')) {
            $fehler[] = 'Bitte entweder Von- UND Bis-Uhrzeit angeben oder beide leer lassen (dann läuft der Eintrag dauerhaft).';
            continue;
        }
        if ($von !== '' && $von >= $bis) {
            $fehler[] = 'Bei einem Eintrag mit Uhrzeit muss „von" vor „bis" liegen (' . htmlspecialchars($von . '–' . $bis) . ').';
            continue;
        }
        $eintraege[] = [
            'playlist_id' => $pid,
            'wochentage'  => implode(',', $tage),
            'von'         => $von,
            'bis'         => $bis,
            'prioritaet'  => (int)($z['prio'] ?? 0),
        ];
    }
    $fehler = array_values(array_unique($fehler));

    if (empty($fehler)) {
        Monitor::ersetzeZeitplan($id, $eintraege);
        header('Location: monitore.php?gespeichert=1');
        exit;
    }
}

// --- Zeitplan für das JS (POST-Eingaben erhalten, sonst aus DB) ---
$zeitplanFuerJs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['zeitplan'] ?? []) as $z) {
        $tage = array_values(array_filter(array_map('intval', (array)($z['tage'] ?? [])),
            static fn($d) => $d >= 1 && $d <= 7));
        $zeitplanFuerJs[] = [
            'playlist_id' => (int)($z['playlist_id'] ?? 0),
            'tage'        => $tage,
            'von'         => substr((string)($z['von'] ?? ''), 0, 5),
            'bis'         => substr((string)($z['bis'] ?? ''), 0, 5),
            'prio'        => (int)($z['prio'] ?? 0),
        ];
    }
} else {
    foreach (Monitor::ladeZeitplan($id) as $z) {
        $tage = array_values(array_filter(array_map('intval', explode(',', (string)$z['wochentage'])),
            static fn($d) => $d >= 1 && $d <= 7));
        $zeitplanFuerJs[] = [
            'playlist_id' => (int)$z['playlist_id'],
            'tage'        => $tage,
            'von'         => substr((string)$z['von_uhrzeit'], 0, 5),
            'bis'         => substr((string)$z['bis_uhrzeit'], 0, 5),
            'prio'        => (int)$z['prioritaet'],
        ];
    }
}

$playlistsFuerJs = array_map(static fn($p) => [
    'id'    => (int)$p['id'],
    'name'  => $p['name'],
    'aktiv' => (bool)$p['aktiv'],
], $playlists);

admin_header('Zeitplan — ' . $monitor['name'], 'monitore');
?>

<p><a href="monitore.php" class="adm-zurueck">← zurück zu den Monitoren</a></p>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= $f ?></div>
<?php endforeach; ?>

<h1 style="margin-top:0">Zeitplan: <?= htmlspecialchars($monitor['name']) ?>
    <span class="adm-eintrag-typ"><?= htmlspecialchars($monitor['subdomain']) ?></span></h1>

<?php if (empty($playlists)): ?>
    <p class="adm-hilfe">
        Es gibt noch keine Playlists. Lege zuerst unter
        <a href="playlists.php">Playlists</a> eine an, dann kannst du sie hier einplanen.
    </p>
<?php else: ?>
<form method="post" id="zeitplan-form">
    <input type="hidden" name="aktion" value="speichern">

    <div class="adm-card">
        <p class="adm-hilfe">
            Lege fest, welche Playlist wann auf diesem Monitor läuft. Pro Eintrag:
            Playlist + Wochentage + <strong>optional</strong> ein Uhrzeit-Fenster +
            Priorität. <strong>Ohne Uhrzeit läuft der Eintrag dauerhaft</strong>
            (ganztags an den gewählten Tagen) und dient als Fallback — Einträge
            <strong>mit</strong> Uhrzeit überschreiben ihn. Bei mehreren passenden
            Einträgen gewinnt die <strong>höhere Priorität</strong> (Zahl). Die
            Auswertung erfolgt am Monitor (Schritt 9).
        </p>
        <div id="zeitplan-liste" class="adm-zeitregeln"></div>
        <button type="button" id="zeitplan-hinzu" class="adm-btn">+ Eintrag hinzufügen</button>
    </div>

    <div class="adm-aktionsleiste">
        <button type="submit" class="adm-btn-primary">Speichern</button>
        <a href="monitore.php" class="adm-btn adm-btn-grau">Abbrechen</a>
    </div>
</form>

<script>
(function () {
    var ZEITPLAN  = <?= json_encode($zeitplanFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var PLAYLISTS = <?= json_encode($playlistsFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var TAGE  = [ [1,'Mo'], [2,'Di'], [3,'Mi'], [4,'Do'], [5,'Fr'], [6,'Sa'], [7,'So'] ];
    var PRESETS = { alle:[1,2,3,4,5,6,7], woche:[1,2,3,4,5], we:[6,7] };

    var liste = document.getElementById('zeitplan-liste');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function playlistOptions(sel) {
        var opts = '<option value="">— Playlist wählen —</option>';
        PLAYLISTS.forEach(function (p) {
            var s = (sel != null && parseInt(sel, 10) === p.id) ? ' selected' : '';
            opts += '<option value="' + p.id + '"' + s + '>' +
                    escapeHtml(p.name) + (p.aktiv ? '' : ' (pausiert)') + '</option>';
        });
        return opts;
    }

    function baueZeile(data) {
        data = data || {};
        var tage = data.tage || [];
        var zeile = document.createElement('div');
        zeile.className = 'adm-zeitregel';

        var tageBtns = TAGE.map(function (t) {
            var an = tage.indexOf(t[0]) !== -1;
            return '<button type="button" class="adm-tag-btn' + (an ? ' an' : '') +
                   '" data-tag="' + t[0] + '" aria-pressed="' + (an ? 'true' : 'false') + '">' + t[1] + '</button>';
        }).join('');

        zeile.innerHTML =
            '<div class="adm-zr-playlist">' +
                '<label>Playlist<select data-feld="playlist_id">' + playlistOptions(data.playlist_id) + '</select></label>' +
            '</div>' +
            '<div class="adm-zr-tage">' +
                '<div class="adm-tag-btns">' + tageBtns + '</div>' +
                '<div class="adm-zr-presets">' +
                    '<button type="button" class="adm-mini" data-preset="alle">Alle</button>' +
                    '<button type="button" class="adm-mini" data-preset="woche">Mo–Fr</button>' +
                    '<button type="button" class="adm-mini" data-preset="we">Wochenende</button>' +
                '</div>' +
            '</div>' +
            '<div class="adm-zr-zeit">' +
                '<label>von <input type="time" data-feld="von" value="' + escapeHtml(data.von || '') + '"></label>' +
                '<label>bis <input type="time" data-feld="bis" value="' + escapeHtml(data.bis || '') + '"></label>' +
                '<label>Priorität <input type="number" data-feld="prio" value="' + (data.prio || 0) + '" step="1" style="width:5em"></label>' +
            '</div>' +
            '<button type="button" class="adm-mini adm-mini-rot adm-zr-weg" title="Eintrag entfernen">×</button>';
        return zeile;
    }

    function neueZeile(data) { liste.appendChild(baueZeile(data)); }

    document.getElementById('zeitplan-hinzu').addEventListener('click', function () { neueZeile({}); });

    liste.addEventListener('click', function (e) {
        var zeile = e.target.closest('.adm-zeitregel');
        if (!zeile) { return; }
        if (e.target.closest('.adm-zr-weg')) { zeile.remove(); return; }
        var tagBtn = e.target.closest('.adm-tag-btn');
        if (tagBtn) {
            var an = tagBtn.classList.toggle('an');
            tagBtn.setAttribute('aria-pressed', an ? 'true' : 'false');
            return;
        }
        var preset = e.target.getAttribute('data-preset');
        if (preset && PRESETS[preset]) {
            var set = PRESETS[preset];
            zeile.querySelectorAll('.adm-tag-btn').forEach(function (b) {
                var an = set.indexOf(parseInt(b.getAttribute('data-tag'), 10)) !== -1;
                b.classList.toggle('an', an);
                b.setAttribute('aria-pressed', an ? 'true' : 'false');
            });
        }
    });

    document.getElementById('zeitplan-form').addEventListener('submit', function () {
        liste.querySelectorAll('.adm-zeitregel').forEach(function (zeile, j) {
            ['playlist_id', 'von', 'bis', 'prio'].forEach(function (f) {
                var el = zeile.querySelector('[data-feld="' + f + '"]');
                if (el) { el.setAttribute('name', 'zeitplan[' + j + '][' + f + ']'); }
            });
            zeile.querySelectorAll('input.adm-zr-taghidden').forEach(function (h) { h.remove(); });
            zeile.querySelectorAll('.adm-tag-btn.an').forEach(function (b) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.className = 'adm-zr-taghidden';
                h.name = 'zeitplan[' + j + '][tage][]';
                h.value = b.getAttribute('data-tag');
                zeile.appendChild(h);
            });
        });
    });

    // Initial
    if (ZEITPLAN.length) { ZEITPLAN.forEach(neueZeile); } else { neueZeile({}); }
})();
</script>
<?php endif; ?>

<?php
admin_footer();
