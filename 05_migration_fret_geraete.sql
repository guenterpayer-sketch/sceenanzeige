-- ============================================================================
-- Tanzschule Monitor-System
-- Migration: FRET-Geräte-Whitelist (Schritt 5b-Erweiterung)
-- ============================================================================
-- Speichert die von der FRET-API bekannten Computer und welche davon im
-- Backend auswählbar (freigegeben) sind. Nur freigegebene Geräte erscheinen
-- im Dropdown des fret-Modul-Editors.
--
-- WICHTIG:
--   * EINMALIG ausführen (z.B. über phpMyAdmin auf der Live-DB).
--   * uuid ist UNIQUE -> "Von FRET aktualisieren" nutzt INSERT ... ON DUPLICATE
--     KEY UPDATE und legt neue Geräte an bzw. frischt den FRET-Namen auf.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fret_geraete (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         VARCHAR(100) NOT NULL,            -- FRET-Computer-UUID
    fret_name    VARCHAR(190) DEFAULT NULL,        -- Name laut FRET-API
    anzeige_name VARCHAR(190) DEFAULT NULL,        -- frei wählbarer Name (z.B. "Saal 1")
    freigegeben  TINYINT(1) NOT NULL DEFAULT 0,    -- nur freigegebene sind im Modul auswählbar
    gesehen_am   DATETIME DEFAULT NULL,            -- zuletzt von der FRET-API gemeldet
    PRIMARY KEY (id),
    UNIQUE KEY uniq_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Danach: Bereich "FRET-Geräte" im Backend → "Von FRET aktualisieren", Geräte
-- benennen + freigeben. Der fret-Modul-Editor zeigt dann nur freigegebene
-- Geräte als Dropdown.
-- ============================================================================
