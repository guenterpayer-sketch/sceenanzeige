-- Migration 11: header_uhrzeit → header_sichtbar
-- Umbenennung widerspiegelt: das Feld steuert die Sichtbarkeit des gesamten
-- Header-Balkens (Logo, Text, Uhrzeit), nicht nur die Uhrzeit-Anzeige.
-- Benötigt MySQL 8.0+ oder MariaDB 10.5.2+.

ALTER TABLE playlist_layout
    RENAME COLUMN header_uhrzeit TO header_sichtbar;
