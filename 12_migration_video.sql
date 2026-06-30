-- ============================================================================
-- Tanzschule Monitor-System
-- Migration: Video-Modul (eigene Videothek + modul_instanz_inhalte-Erweiterung)
-- ============================================================================
-- Neues Modul "video": eigene Videodateien (Upload, mit SHA-256-Duplikat-
-- Erkennung analog zur Mediathek) ODER Embed-Link (YouTube/PeerTube).
-- Bewusst eine EIGENE Tabelle `video_dateien` statt Erweiterung von
-- `mediathek` (die ist fest auf Bild-Semantik zugeschnitten).
--
-- WICHTIG:
--   * EINMALIG ausführen (z.B. über phpMyAdmin auf der Live-DB).
--   * Setzt voraus, dass `modul_instanz_inhalte` bereits die Spalte
--     `gueltig_bis` hat (aktueller Stand laut 01_schema.sql).
--   * MySQL 8 / MariaDB (all-inkl). ADD COLUMN ist NICHT idempotent; bei
--     erneutem Lauf meldet eine bereits existierende Spalte einen Fehler.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Videothek: eigene Videodateien (mp4/webm), analog zur Mediathek mit
--    SHA-256-Duplikat-Erkennung. Die Laufzeit (dauer_sek) wird beim Upload
--    im Browser aus den Video-Metadaten gelesen (kein ffprobe auf all-inkl
--    verfügbar) und dient nur als grobe Schätzung für die Spalten-Synchro-
--    nisation; die tatsächliche Weiterschaltung im Monitor erfolgt über das
--    "ended"-Event.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_dateien (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dateiname      VARCHAR(255) NOT NULL,        -- tatsächlicher Dateiname in uploads/
    original_name  VARCHAR(255) DEFAULT NULL,    -- ursprünglicher Upload-Name, für die Anzeige
    datei_hash     CHAR(64) NOT NULL,            -- SHA-256 des Dateiinhalts (Duplikat-Erkennung)
    dauer_sek      SMALLINT UNSIGNED DEFAULT NULL, -- vom Browser ermittelte Laufzeit (Schätzwert)
    hochgeladen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_video_hash (datei_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. modul_instanz_inhalte erweitern: Verweis auf eine eigene Videodatei
--    ODER eine Embed-URL (YouTube/PeerTube, Typ wird im Frontend aus der URL
--    erkannt). Genau eines der beiden Felder ist pro Video-Eintrag gesetzt.
-- ----------------------------------------------------------------------------
ALTER TABLE modul_instanz_inhalte
    ADD COLUMN video_datei_id INT UNSIGNED DEFAULT NULL AFTER mediathek_id,
    ADD COLUMN video_embed_url VARCHAR(500) DEFAULT NULL AFTER video_datei_id,
    ADD KEY idx_inhalte_video (video_datei_id),
    ADD CONSTRAINT fk_inhalte_video
        FOREIGN KEY (video_datei_id) REFERENCES video_dateien (id)
        ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Nach dieser Migration: modules/video/module.json + frontend.js registrieren
-- (modules/registry.php), admin/videothek.php (eigener "Videos"-Menüpunkt,
-- NICHT Teil der Mediathek) für Upload/Verwaltung der eigenen Videodateien,
-- admin/instanz.php um den dritten has_inhalte-Zweig "video" erweitern.
-- ============================================================================
