-- ============================================================================
-- Tanzschule Monitor-System
-- Migration: Mediathek-Ordner + Tags (Schritt 5a.1 / 5a.2)
-- ============================================================================
-- Ergänzt die in Schritt 5a gebaute Mediathek um eine Sortierstruktur:
--   * Ordner (eine Ebene): jedes Bild liegt in genau EINEM Ordner (optional).
--   * Tags (n:m): ein Bild kann mehrere frei vergebbare Schlagworte haben.
--
-- WICHTIG:
--   * EINMALIG ausführen (z.B. über phpMyAdmin auf der Live-DB).
--   * Setzt die Migration 03_migration_abschnitt16.sql voraus (Tabelle
--     `mediathek` muss existieren).
--   * MySQL 8 / MariaDB (all-inkl). ADD COLUMN ist NICHT idempotent; bei
--     erneutem Lauf meldet die bereits existierende Spalte einen Fehler.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Ordner (eine Ebene). Bilder eines gelöschten Ordners landen wieder in
--    "Ohne Ordner" (ordner_id = NULL), nicht gelöscht (ON DELETE SET NULL).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mediathek_ordner (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ordner_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mediathek
    ADD COLUMN ordner_id INT UNSIGNED DEFAULT NULL AFTER id,
    ADD KEY idx_mediathek_ordner (ordner_id),
    ADD CONSTRAINT fk_mediathek_ordner
        FOREIGN KEY (ordner_id) REFERENCES mediathek_ordner (id)
        ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- 2. Tags (n:m). mediathek_tag verknüpft Bilder mit Schlagworten; beim Löschen
--    eines Bilds oder Tags werden die Verknüpfungen automatisch entfernt.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mediathek_tags (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tag_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mediathek_tag (
    mediathek_id INT UNSIGNED NOT NULL,
    tag_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (mediathek_id, tag_id),
    KEY idx_mtag_tag (tag_id),
    CONSTRAINT fk_mtag_media FOREIGN KEY (mediathek_id) REFERENCES mediathek (id)       ON DELETE CASCADE,
    CONSTRAINT fk_mtag_tag   FOREIGN KEY (tag_id)       REFERENCES mediathek_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Nach dieser Migration kann die Mediathek-Galerie nach Ordner gefiltert,
-- per Namens-Suche durchsucht und nach Tags gefiltert werden. Verwaiste Tags
-- (ohne Zuordnung) werden vom Backend beim Speichern automatisch aufgeräumt.
-- ============================================================================
