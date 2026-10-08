-- ============================================================================
-- Migration 15 — Playlist-Ordner + Archiv (Schritt 36)
-- ============================================================================
-- VOR dem Deploy EINMALIG einspielen (phpMyAdmin). Staging und Live teilen
-- sich EINE Datenbank — einmal einspielen genügt für beide.
--
-- Der neue Code liest playlists.ordner_id / playlists.archiviert in
-- Playlist::listAll(). Fehlen die Spalten, bricht die Playlist-Übersicht
-- (und die Playlist-Auswahl im Zeitplan/Kalender) mit einem Fehler ab.
-- Die Monitore selbst sind NICHT betroffen (proxies/monitor.php nutzt
-- listAll() nicht). Der alte Code (Live vor dem Merge) ignoriert die neuen
-- Spalten — die Migration kann also gefahrlos schon vorher laufen.
--
-- Zweck:
--   * Ordner (eine Ebene), 1:1 wie mediathek_ordner: jede Playlist liegt in
--     genau EINEM Ordner oder in keinem („Ohne Ordner"). Gelöschte Ordner
--     lassen ihre Playlists in „Ohne Ordner" zurück (ON DELETE SET NULL).
--   * archiviert: blendet eine Playlist aus der Übersicht und aus allen
--     Auswahllisten aus, ohne sie zu löschen. Archivieren ist nur möglich,
--     solange die Playlist weder im Wochenplan noch in einem kommenden
--     Kalender-Termin steht (geprüft in Playlist::archivieren()).
--
-- Plattform: MySQL 8 / MariaDB (all-inkl). ADD COLUMN ist NICHT idempotent;
-- bei erneutem Lauf meldet die bereits existierende Spalte einen Fehler.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS playlist_ordner (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_playlist_ordner_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE playlists
    ADD COLUMN ordner_id  INT UNSIGNED DEFAULT NULL AFTER id,
    ADD COLUMN archiviert TINYINT(1) NOT NULL DEFAULT 0 AFTER aktiv,
    ADD KEY idx_playlists_ordner (ordner_id),
    ADD CONSTRAINT fk_playlists_ordner
        FOREIGN KEY (ordner_id) REFERENCES playlist_ordner (id)
        ON DELETE SET NULL;
