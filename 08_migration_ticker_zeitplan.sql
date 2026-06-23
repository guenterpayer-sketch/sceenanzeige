-- ============================================================================
-- Migration 08 — Ticker auf monitor-zentrischen Zeitplan umstellen (Schritt 8)
-- ============================================================================
-- ZUERST EINSPIELEN (vor dem Hochladen der zugehörigen PHP-Dateien).
--
-- Zweck: Der Ticker bekommt — analog zum Playlist-Zeitplan (Schritt 7) — einen
--   Pro-Monitor-Zeitplan. Statt "Ticker hat Zeitregeln + ist Monitoren
--   zugewiesen" gilt künftig:
--   "Ein Monitor hat einen Ticker-Zeitplan: Ticker X läuft an diesen
--    Wochentagen/Uhrzeiten." Mehrere gleichzeitig passende Ticker werden am
--   Monitor GEMISCHT (siehe CLAUDE.md Abschnitt 7) — daher KEIN Prioritätsfeld.
--
-- Änderungen:
--   1. Neue Tabelle ticker_zeitplan (monitor_id, ticker_playlist_id,
--      wochentage, von/bis-Uhrzeit OPTIONAL = NULL-fähig).
--   2. Alte Tabellen ticker_zeitregeln + ticker_playlist_saele entfernt
--      (durch ticker_zeitplan ersetzt).
--
-- Plattform: MySQL 8 / MariaDB (all-inkl). NICHT idempotent — nur einmal
-- ausführen. Vorher ggf. Backup ziehen.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Neuer Pro-Monitor-Ticker-Zeitplan (Uhrzeit optional, kein Prioritätsfeld)
CREATE TABLE IF NOT EXISTS ticker_zeitplan (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id         INT UNSIGNED NOT NULL,
    ticker_playlist_id INT UNSIGNED NOT NULL,
    wochentage         VARCHAR(20) NOT NULL,   -- z.B. "1,2,3,4,5" (Mo-Fr), 1=Montag
    von_uhrzeit        TIME DEFAULT NULL,      -- NULL = keine Uhrzeitgrenze (dauerhaft)
    bis_uhrzeit        TIME DEFAULT NULL,      -- NULL = keine Uhrzeitgrenze (dauerhaft)
    PRIMARY KEY (id),
    KEY idx_monitor (monitor_id),
    KEY idx_ticker (ticker_playlist_id),
    CONSTRAINT fk_ticker_zeitplan_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id) ON DELETE CASCADE,
    CONSTRAINT fk_ticker_zeitplan_ticker
        FOREIGN KEY (ticker_playlist_id) REFERENCES ticker_playlists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Alte ticker-zentrische Tabellen entfernen (durch ticker_zeitplan ersetzt)
DROP TABLE IF EXISTS ticker_playlist_saele;
DROP TABLE IF EXISTS ticker_zeitregeln;

SET FOREIGN_KEY_CHECKS = 1;
