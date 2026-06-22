<?php
/**
 * modules/registry.php
 *
 * Zentrale Liste aller AKTIVEN Inhalts-Module (Plugin-System, siehe
 * Abschnitt 4 der Projektdokumentation). Neue Module werden hier per
 * Eintrag registriert — bestehender Code muss dafür NICHT angefasst werden.
 *
 * Stand Schritt 4: Referenz-Module (uhrzeit, bild) plus die dynamischen
 * Module stundenplan, ankuendigung und song. Das community-Modul ist laut
 * Abschnitt 9 der Doku dauerhaft zurückgestellt und daher bewusst NICHT
 * eingetragen.
 */

declare(strict_types=1);

return [
    'uhrzeit',
    'bild',
    'stundenplan',
    'ankuendigung',
    'fret',
];
