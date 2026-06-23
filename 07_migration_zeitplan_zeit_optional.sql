-- ============================================================================
-- Migration 07 — Uhrzeit im Monitor-Zeitplan optional machen
-- ============================================================================
-- ZUERST EINSPIELEN (vor dem Hochladen der zugehörigen PHP-Dateien).
--
-- Zweck: Ein Zeitplan-Eintrag ohne Uhrzeit-Fenster läuft künftig „dauerhaft"
--   (ganztags an den gewählten Wochentagen) statt eine von/bis-Uhrzeit zu
--   erzwingen. von_uhrzeit/bis_uhrzeit dürfen daher NULL sein.
--
-- Plattform: MySQL 8 / MariaDB (all-inkl). Setzt Migration 06 voraus.
-- ============================================================================

ALTER TABLE monitor_zeitplan
    MODIFY von_uhrzeit TIME DEFAULT NULL,
    MODIFY bis_uhrzeit TIME DEFAULT NULL;
