<?php
/**
 * admin/playlist.php
 *
 * Editor zum Anlegen/Bearbeiten einer Playlist (Schritt 6, Playlist-Editor).
 *   - Aufruf neu:        playlist.php
 *   - Aufruf bearbeiten: playlist.php?id=<playlist_id>
 *
 * Umfang (CLAUDE.md Abschnitt 6): Name, Aktiv, Layout-Auswahl (Spaltenanzahl),
 * Spaltenbreiten (2-spaltig: gekoppelter Regler; 3-spaltig: fest gleich),
 * Header(Uhrzeit)/Footer(Ticker)-Schalter, pro Spalte Modul-Instanzen aus der
 * Bibliothek zuweisen (Picker + ↑/↓-Reihenfolge), schematische Vorschau.
 *
 * NICHT hier: Zeitplanung + Monitor-Zuordnung (monitor-zentrisch, jetzt unter
 * Monitore → „Zeitplan"), Monitor-Rendering (Schritt 9), Live-Vorschau-iFrame
 * (Schritt 10). layout_override pro Instanz ist bewusst auf Schritt 9 vertagt
 * (Spalte bleibt in der DB ungenutzt).
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$fehler = [];

// --- Kontext: neu oder bearbeiten ---
$playlist = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $playlist = Playlist::find($id);
    if (!$playlist) {
        http_response_code(404);
        admin_header('Playlist', 'playlists');
        echo '<p class="adm-flash adm-flash-fehler">Playlist nicht gefunden.</p>';
        admin_footer();
        exit;
    }
}
$istNeu = ($playlist === null);

$layouts = LayoutRegistry::getAll();

// --- Vorbelegung ---
$werteName   = $playlist['name'] ?? '';
$werteAktiv  = $istNeu ? true : (bool)$playlist['aktiv'];
$layoutRow   = $istNeu ? null : Playlist::ladeLayout($id);
$werteLayout = Playlist::layoutIdAus($layoutRow) ?? '1-spaltig';
$werteB1     = (int)($layoutRow['spalte1_breite'] ?? $layouts[$werteLayout]['default_breiten'][0] ?? 100);
$werteHeader = $istNeu ? true : (bool)($layoutRow['header_uhrzeit'] ?? 1);
$werteFooter = $istNeu ? true : (bool)($layoutRow['footer_ticker'] ?? 1);

// Bereits zugewiesene Spalten-Inhalte (für das JS)
$inhalteFuerJs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['inhalt'] ?? []) as $row) {
        $mid = (int)($row['modul_instanz_id'] ?? 0);
        if ($mid <= 0) { continue; }
        $inst = ModulInstanz::find($mid);
        if (!$inst) { continue; }
        $meta = ModuleRegistry::exists($inst['modul_typ']) ? ModuleRegistry::load($inst['modul_typ']) : [];
        $inhalteFuerJs[] = [
            'spalte'           => max(1, min(3, (int)($row['spalte'] ?? 1))),
            'modul_instanz_id' => $mid,
            'name'             => $inst['name'],
            'modul_typ'        => $inst['modul_typ'],
            'typ_label'        => $meta['label'] ?? $inst['modul_typ'],
            'icon'             => $meta['icon'] ?? '',
            'aktiv'            => (bool)$inst['aktiv'],
        ];
    }
} elseif (!$istNeu) {
    foreach (Playlist::listSpaltenInhalte($id) as $row) {
        $meta = ModuleRegistry::exists($row['modul_typ']) ? ModuleRegistry::load($row['modul_typ']) : [];
        $inhalteFuerJs[] = [
            'spalte'           => (int)$row['spalte'],
            'modul_instanz_id' => (int)$row['modul_instanz_id'],
            'name'             => $row['instanz_name'],
            'modul_typ'        => $row['modul_typ'],
            'typ_label'        => $meta['label'] ?? $row['modul_typ'],
            'icon'             => $meta['icon'] ?? '',
            'aktiv'            => (bool)$row['instanz_aktiv'],
        ];
    }
}

// --- Speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $werteName   = trim((string)($_POST['name'] ?? ''));
    $werteAktiv  = !empty($_POST['aktiv']);
    $werteHeader = !empty($_POST['header_uhrzeit']);
    $werteFooter = !empty($_POST['footer_ticker']);

    $werteLayout = (string)($_POST['layout_id'] ?? '1-spaltig');
    if (!LayoutRegistry::exists($werteLayout)) { $werteLayout = '1-spaltig'; }
    $lmeta   = LayoutRegistry::load($werteLayout);
    $spalten = (int)$lmeta['spalten'];

    if ($spalten === 1) {
        $breiten = [100];
        $werteB1 = 100;
    } elseif ($spalten === 2) {
        $werteB1 = (int)($_POST['spalte1_breite'] ?? $lmeta['default_breiten'][0]);
        $werteB1 = max(10, min(90, $werteB1));
        $breiten = [$werteB1, 100 - $werteB1];
    } else { // 3 Spalten: immer gleichmäßig
        $breiten = LayoutRegistry::gleichBreiten(3);
        $werteB1 = $breiten[0];
    }

    if ($werteName === '') {
        $fehler[] = 'Bitte einen Namen für die Playlist angeben.';
    } elseif (Playlist::nameExistiert($werteName, $istNeu ? null : $id)) {
        $fehler[] = 'Es gibt bereits eine Playlist mit diesem Namen. Bitte einen anderen Namen wählen.';
    }

    if (empty($fehler)) {
        if ($istNeu) {
            $id = Playlist::create($werteName);
        } else {
            Playlist::update($id, $werteName);
        }
        Playlist::setAktiv($id, $werteAktiv);
        Playlist::speichereLayout($id, $spalten, $breiten, $werteHeader, $werteFooter);

        // Nur Inhalte in aktiven Spalten (1..$spalten) übernehmen
        $inhalte = [];
        foreach (($_POST['inhalt'] ?? []) as $row) {
            $mid = (int)($row['modul_instanz_id'] ?? 0);
            $sp  = (int)($row['spalte'] ?? 1);
            if ($mid > 0 && $sp >= 1 && $sp <= $spalten) {
                $inhalte[] = ['spalte' => $sp, 'modul_instanz_id' => $mid];
            }
        }
        Playlist::ersetzeSpaltenInhalte($id, $inhalte);

        header('Location: playlists.php?gespeichert=1');
        exit;
    }
}

admin_header(($istNeu ? 'Neue Playlist' : 'Playlist bearbeiten'), 'playlists');

/** Emoji-Icon je module.json-icon (gleich wie in bibliothek.php). */
function pl_modul_icon(string $icon): string
{
    return [
        'clock' => '🕒', 'image' => '🖼️', 'calendar' => '📅',
        'megaphone' => '📢', 'music' => '🎵',
    ][$icon] ?? '🧩';
}
?>

<p><a href="playlists.php" class="adm-zurueck">← zurück zu den Playlists</a></p>

<?php foreach ($fehler as $f): ?>
    <div class="adm-flash adm-flash-fehler"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>

<form method="post" id="playlist-form">
    <input type="hidden" name="aktion" value="speichern">

    <div class="adm-card">
        <div class="field">
            <label for="name">Name der Playlist</label>
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
        <h2>Layout</h2>
        <div class="adm-layoutwahl">
            <?php foreach ($layouts as $lid => $lm): ?>
                <label class="adm-layoutopt">
                    <input type="radio" name="layout_id" value="<?= htmlspecialchars($lid) ?>"
                           data-spalten="<?= (int)$lm['spalten'] ?>"
                           data-default-b1="<?= (int)($lm['default_breiten'][0] ?? 100) ?>"
                           data-frei="<?= !empty($lm['breiten_frei']) ? '1' : '0' ?>"
                           <?= $lid === $werteLayout ? 'checked' : '' ?>>
                    <span class="adm-layout-mini" data-spalten="<?= (int)$lm['spalten'] ?>">
                        <?php for ($i = 0; $i < (int)$lm['spalten']; $i++): ?>
                            <span style="flex:<?= (int)($lm['default_breiten'][$i] ?? 1) ?>"></span>
                        <?php endfor; ?>
                    </span>
                    <span class="adm-layout-label"><?= htmlspecialchars($lm['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="field" id="breiten-block">
            <label for="spalte1_breite">Spaltenbreite</label>
            <div class="adm-breitenregler">
                <input type="range" id="spalte1_breite" name="spalte1_breite"
                       min="10" max="90" step="5" value="<?= (int)$werteB1 ?>">
                <span class="adm-breiten-anzeige" id="breiten-anzeige"></span>
            </div>
            <p class="adm-hilfe" id="breiten-hinweis"></p>
        </div>

        <div class="field field-bool">
            <label for="header_uhrzeit">
                <input type="checkbox" id="header_uhrzeit" name="header_uhrzeit" value="1" <?= $werteHeader ? 'checked' : '' ?>>
                Header anzeigen (Uhrzeit / Datum oben)
            </label>
        </div>
        <div class="field field-bool">
            <label for="footer_ticker">
                <input type="checkbox" id="footer_ticker" name="footer_ticker" value="1" <?= $werteFooter ? 'checked' : '' ?>>
                Footer-Ticker aktiv (Ticker-Inhalte siehe Bereich „Ticker")
            </label>
        </div>

        <h2>Vorschau (schematisch)</h2>
        <p class="adm-hilfe">Nur die Proportionen — das echte Modul-Rendering folgt in einem späteren Schritt.</p>
        <div class="adm-vorschau" id="vorschau">
            <div class="adm-vorschau-header" id="vorschau-header">Uhrzeit / Datum</div>
            <div class="adm-vorschau-spalten" id="vorschau-spalten"></div>
            <div class="adm-vorschau-footer" id="vorschau-footer">Ticker</div>
        </div>
    </div>

    <div class="adm-card">
        <h2>Spalten-Inhalte</h2>
        <p class="adm-hilfe">
            Pro Spalte eine oder mehrere Modul-Instanzen aus der Bibliothek. Reihenfolge per ↑/↓.
            Die Instanzen rotieren später unabhängig (Schritt 9).
        </p>
        <div class="adm-spalten" id="spalten"></div>
    </div>

    <p class="adm-hilfe">
        <strong>Wann</strong> diese Playlist auf <strong>welchem Monitor</strong> läuft,
        legst du im Bereich <a href="monitore.php">Monitore</a> → „Zeitplan" fest.
    </p>

    <div class="adm-aktionsleiste">
        <button type="submit" class="adm-btn-primary">Speichern</button>
        <a href="playlists.php" class="adm-btn adm-btn-grau">Abbrechen</a>
    </div>
</form>

<!-- Instanz-Picker-Dialog -->
<div id="picker-overlay" class="adm-overlay" hidden>
    <div class="adm-dialog adm-dialog-breit">
        <h3>Modul-Instanz wählen</h3>
        <div class="adm-picker-filter">
            <select id="picker-typ"><option value="">Alle Modularten</option></select>
        </div>
        <div id="picker-liste" class="adm-picker-instanzen"></div>
        <div class="adm-dialog-aktionen">
            <a class="adm-btn adm-btn-grau" href="bibliothek.php" target="_blank" rel="noopener">Neue Instanz anlegen ↗</a>
            <button type="button" id="picker-abbrechen" class="adm-btn-grau">Schließen</button>
        </div>
    </div>
</div>

<script>
(function () {
    var START = <?= json_encode($inhalteFuerJs, JSON_UNESCAPED_UNICODE) ?>;
    var ICONS = { clock:'🕒', image:'🖼️', calendar:'📅', megaphone:'📢', music:'🎵' };

    var spaltenWrap   = document.getElementById('spalten');
    var breitenBlock  = document.getElementById('breiten-block');
    var slider        = document.getElementById('spalte1_breite');
    var anzeige       = document.getElementById('breiten-anzeige');
    var breitenHinweis= document.getElementById('breiten-hinweis');
    var vorschauSp    = document.getElementById('vorschau-spalten');
    var vHeader       = document.getElementById('vorschau-header');
    var vFooter       = document.getElementById('vorschau-footer');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }
    function icon(name) { return ICONS[name] || '🧩'; }

    function aktivesLayout() {
        var r = document.querySelector('input[name="layout_id"]:checked');
        if (!r) { r = document.querySelector('input[name="layout_id"]'); r.checked = true; }
        return r;
    }
    function anzSpalten() { return parseInt(aktivesLayout().getAttribute('data-spalten'), 10) || 1; }

    // ---- Breiten je Layout ----
    function breiten() {
        var n = anzSpalten();
        if (n === 1) { return [100]; }
        if (n === 2) { var b1 = parseInt(slider.value, 10) || 50; return [b1, 100 - b1]; }
        return [34, 33, 33]; // 3 Spalten immer gleich
    }

    function aktualisiereBreitenUI() {
        var n = anzSpalten();
        var frei = aktivesLayout().getAttribute('data-frei') === '1';
        breitenBlock.style.display = (n === 2 && frei) ? '' : 'none';
        if (n === 2) {
            var b = breiten();
            anzeige.textContent = b[0] + ' % / ' + b[1] + ' %';
            breitenHinweis.textContent = 'Regler verschiebt das Verhältnis der beiden Spalten.';
        } else if (n === 3) {
            breitenHinweis.textContent = '';
        }
    }

    // ---- Schematische Vorschau ----
    function aktualisiereVorschau() {
        var b = breiten();
        vorschauSp.innerHTML = '';
        b.forEach(function (br, i) {
            var sp = document.createElement('div');
            sp.className = 'adm-vorschau-spalte';
            sp.style.flex = br;
            sp.textContent = 'Spalte ' + (i + 1) + ' · ' + br + ' %';
            vorschauSp.appendChild(sp);
        });
        vHeader.style.display = document.getElementById('header_uhrzeit').checked ? '' : 'none';
        vFooter.style.display = document.getElementById('footer_ticker').checked ? '' : 'none';
    }

    // ---- Spalten-Editor ----
    function baueSpalten() {
        var n = anzSpalten();
        // Bestehende Einträge je Spalte einsammeln, um sie beim Umbau zu erhalten
        var vorhandene = {};
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(function (col) {
            var s = col.getAttribute('data-spalte');
            vorhandene[s] = Array.prototype.slice.call(col.querySelectorAll('.adm-spalte-eintrag'));
        });

        spaltenWrap.innerHTML = '';
        spaltenWrap.setAttribute('data-anzahl', n);
        for (var s = 1; s <= n; s++) {
            var col = document.createElement('div');
            col.className = 'adm-spalte';
            col.setAttribute('data-spalte', String(s));
            col.innerHTML =
                '<div class="adm-spalte-kopf">Spalte ' + s + '</div>' +
                '<div class="adm-spalte-liste"></div>' +
                '<button type="button" class="adm-btn adm-spalte-add">+ Instanz hinzufügen</button>';
            spaltenWrap.appendChild(col);

            var liste = col.querySelector('.adm-spalte-liste');
            if (vorhandene[s]) {
                vorhandene[s].forEach(function (e) { liste.appendChild(e); });
            }
            pruefeLeer(col);
        }
        // Einträge aus weggefallenen Spalten (n+1..3) in die letzte Spalte schieben,
        // damit nichts unbemerkt verloren geht.
        for (var k = n + 1; k <= 3; k++) {
            if (vorhandene[k] && vorhandene[k].length) {
                var ziel = spaltenWrap.querySelector('.adm-spalte[data-spalte="' + n + '"] .adm-spalte-liste');
                vorhandene[k].forEach(function (e) { ziel.appendChild(e); });
            }
        }
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(pruefeLeer);
    }

    function pruefeLeer(col) {
        var liste = col.querySelector('.adm-spalte-liste');
        var hatLeer = liste.querySelector('.adm-spalte-leer');
        var hatEintrag = liste.querySelector('.adm-spalte-eintrag');
        if (!hatEintrag && !hatLeer) {
            var p = document.createElement('p');
            p.className = 'adm-spalte-leer';
            p.textContent = 'Noch keine Instanz.';
            liste.appendChild(p);
        } else if (hatEintrag && hatLeer) {
            hatLeer.remove();
        }
    }

    function baueEintrag(data) {
        var e = document.createElement('div');
        e.className = 'adm-spalte-eintrag' + (data.aktiv === false ? ' inaktiv' : '');
        e.setAttribute('data-mid', data.modul_instanz_id);
        e.innerHTML =
            '<input type="hidden" data-feld="modul_instanz_id" value="' + escapeHtml(data.modul_instanz_id) + '">' +
            '<input type="hidden" data-feld="spalte" value="">' +
            '<span class="adm-eintrag-griff" title="Ziehen zum Verschieben (auch zwischen Spalten)">⠿</span>' +
            '<span class="adm-eintrag-icon">' + icon(data.icon) + '</span>' +
            '<span class="adm-eintrag-text">' +
                '<span class="adm-eintrag-name">' + escapeHtml(data.name) +
                    (data.aktiv === false ? ' <span class="adm-badge-pause">pausiert</span>' : '') + '</span>' +
                '<span class="adm-eintrag-typ">' + escapeHtml(data.typ_label || data.modul_typ) + '</span>' +
            '</span>' +
            '<span class="adm-eintrag-steuer">' +
                '<button type="button" class="adm-mini" data-akt="hoch" title="nach oben">↑</button>' +
                '<button type="button" class="adm-mini" data-akt="runter" title="nach unten">↓</button>' +
                '<button type="button" class="adm-mini adm-mini-rot" data-akt="weg" title="entfernen">×</button>' +
            '</span>';
        return e;
    }

    function fuegeEinSpalte(spalteNr, data) {
        var col = spaltenWrap.querySelector('.adm-spalte[data-spalte="' + spalteNr + '"]');
        if (!col) { return false; }
        var liste = col.querySelector('.adm-spalte-liste');
        // Doppelte Instanz in derselben Spalte vermeiden
        if (liste.querySelector('.adm-spalte-eintrag[data-mid="' + data.modul_instanz_id + '"]')) {
            return false;
        }
        liste.appendChild(baueEintrag(data));
        pruefeLeer(col);
        return true;
    }

    // Klick-Delegation im Spalten-Bereich
    spaltenWrap.addEventListener('click', function (e) {
        var add = e.target.closest('.adm-spalte-add');
        if (add) {
            var col = add.closest('.adm-spalte');
            oeffnePicker(parseInt(col.getAttribute('data-spalte'), 10));
            return;
        }
        var eintrag = e.target.closest('.adm-spalte-eintrag');
        if (!eintrag) { return; }
        var akt = e.target.getAttribute('data-akt');
        var liste = eintrag.parentElement;
        if (akt === 'weg')    { eintrag.remove(); pruefeLeer(liste.closest('.adm-spalte')); }
        if (akt === 'hoch'   && eintrag.previousElementSibling && eintrag.previousElementSibling.classList.contains('adm-spalte-eintrag')) {
            liste.insertBefore(eintrag, eintrag.previousElementSibling);
        }
        if (akt === 'runter' && eintrag.nextElementSibling) {
            liste.insertBefore(eintrag.nextElementSibling, eintrag);
        }
    });

    // ---- Drag & Drop (Einträge innerhalb + zwischen Spalten verschieben) ----
    // Nur über den Griff (⠿) ziehbar, damit Klicks auf ↑/↓/× nicht ziehen.
    var gezogen = null, urParent = null, urNext = null, griffEintrag = null;

    spaltenWrap.addEventListener('mousedown', function (e) {
        if (e.target.closest('.adm-eintrag-griff')) {
            griffEintrag = e.target.closest('.adm-spalte-eintrag');
            if (griffEintrag) { griffEintrag.setAttribute('draggable', 'true'); }
        }
    });
    // Aufräumen: nach Klick/Drag draggable wieder zurücknehmen
    document.addEventListener('mouseup', function () {
        if (griffEintrag && griffEintrag !== gezogen) {
            griffEintrag.removeAttribute('draggable');
        }
        griffEintrag = null;
    });

    function getEintragNach(liste, y) {
        var els = Array.prototype.slice.call(
            liste.querySelectorAll('.adm-spalte-eintrag:not(.wird-gezogen)')
        );
        var naechste = { offset: Number.NEGATIVE_INFINITY, element: null };
        els.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > naechste.offset) {
                naechste = { offset: offset, element: child };
            }
        });
        return naechste.element; // null = ans Ende anhängen
    }

    spaltenWrap.addEventListener('dragstart', function (e) {
        var eintrag = e.target.closest('.adm-spalte-eintrag');
        if (!eintrag || eintrag.getAttribute('draggable') !== 'true') { return; }
        gezogen  = eintrag;
        urParent = eintrag.parentElement;
        urNext   = eintrag.nextElementSibling;
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', eintrag.getAttribute('data-mid')); } catch (ex) {}
        setTimeout(function () { eintrag.classList.add('wird-gezogen'); }, 0);
    });

    spaltenWrap.addEventListener('dragover', function (e) {
        if (!gezogen) { return; }
        var liste = e.target.closest('.adm-spalte-liste');
        if (!liste) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var nach = getEintragNach(liste, e.clientY);
        if (nach) { liste.insertBefore(gezogen, nach); }
        else      { liste.appendChild(gezogen); }
        pruefeLeer(liste.closest('.adm-spalte')); // Platzhalter im Ziel entfernen
    });

    spaltenWrap.addEventListener('drop', function (e) {
        if (!gezogen) { return; }
        e.preventDefault();
        var zielListe = gezogen.parentElement;
        var mid = gezogen.getAttribute('data-mid');
        // Doppelte Instanz in derselben Spalte verhindern → zurück an Ursprung
        var doppelt = Array.prototype.slice.call(
            zielListe.querySelectorAll('.adm-spalte-eintrag')
        ).some(function (el) { return el !== gezogen && el.getAttribute('data-mid') === mid; });
        if (doppelt) {
            if (urParent) { urParent.insertBefore(gezogen, urNext); }
            alert('Diese Instanz ist in der Zielspalte bereits enthalten.');
        }
    });

    spaltenWrap.addEventListener('dragend', function () {
        if (gezogen) {
            gezogen.classList.remove('wird-gezogen');
            gezogen.removeAttribute('draggable');
        }
        gezogen = urParent = urNext = null;
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(pruefeLeer);
    });

    // Layout-Wechsel
    function markiereLayout() {
        document.querySelectorAll('.adm-layoutopt').forEach(function (lbl) {
            var inp = lbl.querySelector('input[name="layout_id"]');
            lbl.classList.toggle('aktiv', !!(inp && inp.checked));
        });
    }
    document.querySelectorAll('input[name="layout_id"]').forEach(function (r) {
        r.addEventListener('change', function () {
            // Default-Breite des gewählten Layouts in den Regler übernehmen
            var db1 = parseInt(r.getAttribute('data-default-b1'), 10);
            if (!isNaN(db1)) { slider.value = db1; }
            markiereLayout();
            aktualisiereBreitenUI();
            baueSpalten();
            aktualisiereVorschau();
        });
    });
    slider.addEventListener('input', function () { aktualisiereBreitenUI(); aktualisiereVorschau(); });
    document.getElementById('header_uhrzeit').addEventListener('change', aktualisiereVorschau);
    document.getElementById('footer_ticker').addEventListener('change', aktualisiereVorschau);

    // ---- Picker ----
    var overlay = document.getElementById('picker-overlay');
    var liste   = document.getElementById('picker-liste');
    var selTyp  = document.getElementById('picker-typ');
    var zielSpalte = null;
    var typenGeladen = false;

    function oeffnePicker(spalteNr) { zielSpalte = spalteNr; overlay.hidden = false; ladePicker(); }
    function schliessePicker() { overlay.hidden = true; zielSpalte = null; }
    document.getElementById('picker-abbrechen').addEventListener('click', schliessePicker);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { schliessePicker(); } });
    selTyp.addEventListener('change', ladePicker);

    function ladePicker() {
        var p = new URLSearchParams();
        if (selTyp.value) { p.set('typ', selTyp.value); }
        liste.innerHTML = '<p class="adm-leer">Lade …</p>';
        fetch('api/instanz-list.php?' + p.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { liste.innerHTML = '<p class="adm-leer">Fehler beim Laden.</p>'; return; }
                if (!typenGeladen) {
                    data.typen.forEach(function (t) {
                        var opt = document.createElement('option');
                        opt.value = t.id; opt.textContent = t.label + ' (' + t.anzahl + ')';
                        selTyp.appendChild(opt);
                    });
                    typenGeladen = true;
                }
                if (data.instanzen.length === 0) {
                    liste.innerHTML = '<p class="adm-leer">Keine Instanzen. In der <a href="bibliothek.php">Bibliothek</a> anlegen.</p>';
                    return;
                }
                liste.innerHTML = '';
                data.instanzen.forEach(function (inst) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'adm-picker-instanz' + (inst.aktiv ? '' : ' inaktiv');
                    btn.innerHTML =
                        '<span class="adm-eintrag-icon">' + icon(inst.icon) + '</span>' +
                        '<span class="adm-eintrag-text">' +
                            '<span class="adm-eintrag-name">' + escapeHtml(inst.name) +
                                (inst.aktiv ? '' : ' <span class="adm-badge-pause">pausiert</span>') + '</span>' +
                            '<span class="adm-eintrag-typ">' + escapeHtml(inst.typ_label) + '</span>' +
                        '</span>';
                    btn.addEventListener('click', function () {
                        var ok = fuegeEinSpalte(zielSpalte, {
                            modul_instanz_id: inst.id, name: inst.name, modul_typ: inst.modul_typ,
                            typ_label: inst.typ_label, icon: inst.icon, aktiv: inst.aktiv
                        });
                        if (!ok) { alert('Diese Instanz ist in dieser Spalte bereits enthalten.'); return; }
                        schliessePicker();
                    });
                    liste.appendChild(btn);
                });
            })
            .catch(function () { liste.innerHTML = '<p class="adm-leer">Netzwerkfehler.</p>'; });
    }

    // ---- Vor dem Absenden: Spalte + Feldnamen sequenziell vergeben ----
    document.getElementById('playlist-form').addEventListener('submit', function () {
        var i = 0;
        spaltenWrap.querySelectorAll('.adm-spalte').forEach(function (col) {
            var s = col.getAttribute('data-spalte');
            col.querySelectorAll('.adm-spalte-eintrag').forEach(function (eintrag) {
                eintrag.querySelector('[data-feld="spalte"]').value = s;
                eintrag.querySelectorAll('[data-feld]').forEach(function (feld) {
                    feld.setAttribute('name', 'inhalt[' + i + '][' + feld.getAttribute('data-feld') + ']');
                });
                i++;
            });
        });
    });

    // ---- Initialaufbau ----
    markiereLayout();
    aktualisiereBreitenUI();
    baueSpalten();
    // Startdaten in ihre Spalten einsortieren
    START.forEach(function (d) { fuegeEinSpalte(Math.min(d.spalte, anzSpalten()), d); });
    aktualisiereVorschau();
})();
</script>

<?php
admin_footer();
