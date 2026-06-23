<?php
/**
 * admin/ticker-edit.php
 *
 * Editor zum Anlegen/Bearbeiten eines Tickers (Schritt 8):
 *   - Aufruf neu:        ticker-edit.php
 *   - Aufruf bearbeiten: ticker-edit.php?id=<ticker_id>
 *
 * Umfang (CLAUDE.md Abschnitt 7): Name, Aktiv, Texteinträge (Textzeile +
 * Anzeigedauer, Reihenfolge per ↑/↓ und Drag & Drop). Inhalt ist bewusst NUR
 * manuell erfasster Text.
 *
 * NICHT hier: Zeitplanung + Monitor-Zuordnung — die sind monitor-zentrisch und
 * liegen unter Monitore → „Zeitplan" (Abschnitt „Ticker-Zeitplan").
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$fehler = [];

// --- Kontext: neu oder bearbeiten ---
$ticker = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $ticker = TickerPlaylist::find($id);
    if (!$ticker) {
        http_response_code(404);
        admin_header('Ticker', 'ticker');
        echo '<p class="adm-flash adm-flash-fehler">Ticker nicht gefunden.</p>';
        admin_footer();
        exit;
    }
}
$istNeu = ($ticker === null);

// --- Vorbelegung ---
$werteName  = $ticker['name'] ?? '';
$werteAktiv = $istNeu ? true : (bool)$ticker['aktiv'];

// Texteinträge für das JS (POST-Eingaben erhalten, sonst aus DB)
$eintraegeFuerJs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['eintrag'] ?? []) as $row) {
        $text = (string)($row['text'] ?? '');
        if (trim($text) === '') { continue; }
        $eintraegeFuerJs[] = [
            'text'      => $text,
            'dauer_sek' => max(1, (int)($row['dauer_sek'] ?? 8)),
        ];
    }
} elseif (!$istNeu) {
    foreach (TickerPlaylist::listEintraege($id) as $row) {
        $eintraegeFuerJs[] = [
            'text'      => $row['text'],
            'dauer_sek' => (int)$row['dauer_sek'],
        ];
    }
}

// --- Speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $werteName  = trim((string)($_POST['name'] ?? ''));
    $werteAktiv = !empty($_POST['aktiv']);

    if ($werteName === '') {
        $fehler[] = 'Bitte einen Namen für den Ticker angeben.';
    } elseif (TickerPlaylist::nameExistiert($werteName, $istNeu ? null : $id)) {
        $fehler[] = 'Es gibt bereits einen Ticker mit diesem Namen. Bitte einen anderen Namen wählen.';
    }

    $eintraege = [];
    foreach (($_POST['eintrag'] ?? []) as $row) {
        $text = trim((string)($row['text'] ?? ''));
        if ($text === '') { continue; }
        $eintraege[] = ['text' => $text, 'dauer_sek' => (int)($row['dauer_sek'] ?? 8)];
    }
    if (empty($eintraege)) {
        $fehler[] = 'Bitte mindestens eine Textzeile angeben.';
    }

    if (empty($fehler)) {
        if ($istNeu) {
            $id = TickerPlaylist::create($werteName);
        } else {
            TickerPlaylist::update($id, $werteName);
        }
        TickerPlaylist::setAktiv($id, $werteAktiv);
        TickerPlaylist::ersetzeEintraege($id, $eintraege);

        header('Location: ticker.php?gespeichert=1');
        exit;
    }
}

admin_header(($istNeu ? 'Neuer Ticker' : 'Ticker bearbeiten'), 'ticker');
?>

<p><a href="ticker.php" class="adm-zurueck">← zurück zu den Tickern</a></p>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>

<form method="post" id="ticker-form">
    <input type="hidden" name="aktion" value="speichern">

    <div class="adm-card">
        <div class="field">
            <label for="name">Name des Tickers</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($werteName) ?>" required>
        </div>
        <div class="field field-bool">
            <label for="aktiv">
                <input type="checkbox" id="aktiv" name="aktiv" value="1" <?= $werteAktiv ? 'checked' : '' ?>>
                Aktiv (deaktiviert = pausiert, ohne zu löschen)
            </label>
        </div>
    </div>

    <div class="adm-card">
        <h2>Textzeilen</h2>
        <p class="adm-hilfe">
            Eine oder mehrere Textzeilen mit Anzeigedauer (Sekunden). Reihenfolge
            per ↑/↓ oder Ziehen am Griff (⠿). Die Zeilen laufen am Monitor
            nacheinander im Footer (Schritt 9).
        </p>
        <div id="ticker-liste" class="adm-tickerliste"></div>
        <button type="button" id="ticker-hinzu" class="adm-btn">+ Textzeile hinzufügen</button>
    </div>

    <p class="adm-hilfe">
        <strong>Wann</strong> dieser Ticker auf <strong>welchem Monitor</strong>
        läuft, legst du im Bereich <a href="monitore.php">Monitore</a> →
        „Zeitplan" (Abschnitt „Ticker-Zeitplan") fest.
    </p>

    <div class="adm-aktionsleiste">
        <button type="submit" class="adm-btn-primary">Speichern</button>
        <a href="ticker.php" class="adm-btn adm-btn-grau">Abbrechen</a>
    </div>
</form>

<script>
(function () {
    var START = <?= json_encode($eintraegeFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var liste = document.getElementById('ticker-liste');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function baueZeile(data) {
        data = data || {};
        var z = document.createElement('div');
        z.className = 'adm-ticker-zeile';
        z.innerHTML =
            '<span class="adm-eintrag-griff" title="Ziehen zum Umsortieren">⠿</span>' +
            '<label class="adm-ticker-text">Text' +
                '<textarea data-feld="text" rows="2">' + escapeHtml(data.text || '') + '</textarea>' +
            '</label>' +
            '<label class="adm-ticker-dauer">Dauer (Sek.)' +
                '<input type="number" data-feld="dauer_sek" min="1" step="1" value="' + (parseInt(data.dauer_sek, 10) || 8) + '">' +
            '</label>' +
            '<span class="adm-eintrag-steuer">' +
                '<button type="button" class="adm-mini" data-akt="hoch" title="nach oben">↑</button>' +
                '<button type="button" class="adm-mini" data-akt="runter" title="nach unten">↓</button>' +
                '<button type="button" class="adm-mini adm-mini-rot" data-akt="weg" title="entfernen">×</button>' +
            '</span>';
        return z;
    }

    function neueZeile(data) { liste.appendChild(baueZeile(data)); }

    document.getElementById('ticker-hinzu').addEventListener('click', function () { neueZeile({}); });

    // ↑ / ↓ / entfernen
    liste.addEventListener('click', function (e) {
        var zeile = e.target.closest('.adm-ticker-zeile');
        if (!zeile) { return; }
        var akt = e.target.getAttribute('data-akt');
        if (akt === 'weg') {
            var text = (zeile.querySelector('[data-feld="text"]').value || '').trim();
            if (text === '' || confirm('Diese Textzeile entfernen?')) { zeile.remove(); }
            return;
        }
        if (akt === 'hoch' && zeile.previousElementSibling) {
            liste.insertBefore(zeile, zeile.previousElementSibling);
        }
        if (akt === 'runter' && zeile.nextElementSibling) {
            liste.insertBefore(zeile.nextElementSibling, zeile);
        }
    });

    // ---- Drag & Drop (nur über den Griff ⠿) ----
    var gezogen = null, griffZeile = null;

    liste.addEventListener('mousedown', function (e) {
        if (e.target.closest('.adm-eintrag-griff')) {
            griffZeile = e.target.closest('.adm-ticker-zeile');
            if (griffZeile) { griffZeile.setAttribute('draggable', 'true'); }
        }
    });
    document.addEventListener('mouseup', function () {
        if (griffZeile && griffZeile !== gezogen) { griffZeile.removeAttribute('draggable'); }
        griffZeile = null;
    });

    function zeileNach(y) {
        var els = Array.prototype.slice.call(
            liste.querySelectorAll('.adm-ticker-zeile:not(.wird-gezogen)')
        );
        var naechste = { offset: Number.NEGATIVE_INFINITY, element: null };
        els.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > naechste.offset) { naechste = { offset: offset, element: child }; }
        });
        return naechste.element;
    }

    liste.addEventListener('dragstart', function (e) {
        var zeile = e.target.closest('.adm-ticker-zeile');
        if (!zeile || zeile.getAttribute('draggable') !== 'true') { return; }
        gezogen = zeile;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', ''); } catch (ex) {}
        setTimeout(function () { zeile.classList.add('wird-gezogen'); }, 0);
    });
    liste.addEventListener('dragover', function (e) {
        if (!gezogen) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var nach = zeileNach(e.clientY);
        if (nach) { liste.insertBefore(gezogen, nach); } else { liste.appendChild(gezogen); }
    });
    liste.addEventListener('dragend', function () {
        if (gezogen) { gezogen.classList.remove('wird-gezogen'); gezogen.removeAttribute('draggable'); }
        gezogen = null;
    });

    // Vor dem Absenden: Feldnamen sequenziell vergeben (= Reihenfolge)
    document.getElementById('ticker-form').addEventListener('submit', function () {
        liste.querySelectorAll('.adm-ticker-zeile').forEach(function (zeile, i) {
            zeile.querySelectorAll('[data-feld]').forEach(function (feld) {
                feld.setAttribute('name', 'eintrag[' + i + '][' + feld.getAttribute('data-feld') + ']');
            });
        });
    });

    // Initial
    if (START.length) { START.forEach(neueZeile); } else { neueZeile({}); }
})();
</script>

<?php
admin_footer();
