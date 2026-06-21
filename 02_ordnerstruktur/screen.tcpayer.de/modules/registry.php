<?php
/**
 * modules/registry.php
 *
 * Zentrale Liste aller AKTIVEN Inhalts-Module (Plugin-System, siehe
 * Abschnitt 4 der Projektdokumentation). Neue Module werden hier per
 * Eintrag registriert — bestehender Code muss dafür NICHT angefasst werden.
 *
 * Stand Schritt 3: nur die beiden Referenz-Module sind fertig implementiert.
 * stundenplan/ankuendigung/song folgen in Schritt 4 und werden dann hier
 * ergänzt. Das community-Modul ist laut Abschnitt 9 der Doku dauerhaft
 * zurückgestellt und daher bewusst NICHT eingetragen.
 */

declare(strict_types=1);

return [
    'uhrzeit',
    'bild',
];
