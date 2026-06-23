-- ============================================================================
-- Migration 06 — Umbau auf monitor-zentrisches Modell
-- ============================================================================
-- ZUERST EINSPIELEN (vor dem Hochladen der zugehörigen PHP-Dateien).
--
-- Zweck: Die Zeitplanung wandert von der Playlist auf den Monitor. Statt
--   "Playlist hat Zeitregeln + ist Sälen zugewiesen" gilt künftig:
--   "Ein Monitor hat einen Zeitplan: Playlist X läuft Mo–Fr 18–23 Uhr (Prio N)".
--
-- Änderungen:
--   1. Tabelle saele -> monitore (Begriff "Monitor" statt "Saal").
--   2. Spalten saal_id -> monitor_id in einstellungen + ticker_playlist_saele,
--      FKs auf monitore neu gesetzt.
--   3. Alte playlist-zentrische Tabellen playlist_saele + playlist_zeitregeln
--      entfernt (durch monitor_zeitplan ersetzt).
--   4. Neue Tabelle monitor_zeitplan (Playlist je Monitor + Wochentag/Uhrzeit
--      + Priorität).
--
-- Plattform: MySQL 8 / MariaDB (all-inkl). NICHT idempotent — nur einmal
-- ausführen. Vorher ggf. Backup ziehen.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Child-FKs auf saele lösen, damit Tabellen-/Spaltenumbenennung frei ist
ALTER TABLE einstellungen        DROP FOREIGN KEY fk_einstellungen_saal;
ALTER TABLE ticker_playlist_saele DROP FOREIGN KEY fk_ticker_saele_saal;

-- 2. Alte playlist-zentrische Tabellen entfernen (werden durch monitor_zeitplan ersetzt)
DROP TABLE IF EXISTS playlist_saele;
DROP TABLE IF EXISTS playlist_zeitregeln;

-- 3. Tabelle saele -> monitore
RENAME TABLE saele TO monitore;

-- 4. Spalten saal_id -> monitor_id + FKs neu auf monitore
ALTER TABLE einstellungen
    CHANGE COLUMN saal_id monitor_id INT UNSIGNED NOT NULL;
ALTER TABLE einstellungen
    ADD CONSTRAINT fk_einstellungen_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id) ON DELETE CASCADE;

ALTER TABLE ticker_playlist_saele
    CHANGE COLUMN saal_id monitor_id INT UNSIGNED NOT NULL;
ALTER TABLE ticker_playlist_saele
    ADD CONSTRAINT fk_ticker_saele_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id) ON DELETE CASCADE;

-- 5. Neuer Pro-Monitor-Zeitplan (ersetzt playlist_saele + playlist_zeitregeln)
CREATE TABLE IF NOT EXISTS monitor_zeitplan (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id  INT UNSIGNED NOT NULL,
    playlist_id INT UNSIGNED NOT NULL,
    wochentage  VARCHAR(20) NOT NULL,      -- z.B. "1,2,3,4,5" (Mo-Fr), 1=Montag
    von_uhrzeit TIME NOT NULL,
    bis_uhrzeit TIME NOT NULL,
    prioritaet  INT NOT NULL DEFAULT 0,    -- höherer Wert gewinnt bei Überschneidung
    PRIMARY KEY (id),
    KEY idx_monitor (monitor_id),
    KEY idx_playlist (playlist_id),
    CONSTRAINT fk_zeitplan_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id) ON DELETE CASCADE,
    CONSTRAINT fk_zeitplan_playlist
        FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
