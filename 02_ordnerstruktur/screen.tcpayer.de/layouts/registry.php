<?php
/**
 * layouts/registry.php
 *
 * Zentrale Liste aller verfügbaren Playlist-Layouts (siehe Abschnitt 4 und 6
 * der Projektdokumentation). Jeder Eintrag verweist auf einen Ordner unter
 * layouts/<id>/ mit:
 *   - layout.json     Metadaten (Spaltenanzahl, Default-Breiten, Label)
 *   - template.html   CSS-Grid-Definition für die Spalten
 *
 * Wird in Schritt 3 (Modul-Registry-Grundgerüst) inhaltlich befüllt.
 */

declare(strict_types=1);

return [
    '1-spaltig',
    '2-spaltig-60-40',
    '2-spaltig-50-50',
    '3-spaltig-gleich',
];
