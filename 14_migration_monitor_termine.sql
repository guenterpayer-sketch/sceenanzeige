-- ============================================================================
-- Migration 14 — Kalender-Termine (Schritt 29, Etappe A)
-- ============================================================================
-- ZUERST EINSPIELEN (vor bzw. direkt nach dem Deploy der zugehörigen
-- PHP-Dateien — der Code behandelt eine fehlende Tabelle übergangsweise wie
-- „keine Termine", die Monitore laufen also auch dazwischen weiter).
--
-- Zweck: Zweite Planungs-Ebene NEBEN dem wochentagsbasierten Regelbetrieb
-- (monitor_zeitplan). Ein Termin gilt für ein konkretes Datum bzw. einen
-- Datumsbereich (z.B. Ferien) und sticht den Regelbetrieb in seinem
-- Zeitfenster aus:
--   - datum_von = datum_bis          → Einzeltermin an einem Tag
--   - von/bis_uhrzeit beide NULL     → ganztags (wie beim Regelbetrieb)
--   - prioritaet/dauer_sek           → gleiche Bedeutung wie monitor_zeitplan
--     (unter mehreren gleichzeitigen Terminen gewinnt die höchste Priorität,
--      bei Gleichstand rotieren die Playlists mit dauer_sek)
-- Ein Termin für mehrere Monitore wird als eine Zeile je Monitor gespeichert.
--
-- Auswahl-Logik: zentral in Monitor::aktuellePlaylistFuer() — Termine werden
-- VOR dem Regelbetrieb geprüft; gibt es JETZT mindestens einen passenden
-- Termin, wird der Regelbetrieb komplett ignoriert.
--
-- Plattform: MySQL 8 / MariaDB (all-inkl). Idempotent (CREATE IF NOT EXISTS).
-- ============================================================================

CREATE TABLE IF NOT EXISTS monitor_termine (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id  INT UNSIGNED NOT NULL,
    playlist_id INT UNSIGNED NOT NULL,
    datum_von   DATE NOT NULL,
    datum_bis   DATE NOT NULL,
    von_uhrzeit TIME NULL DEFAULT NULL,     -- beide NULL = ganztags
    bis_uhrzeit TIME NULL DEFAULT NULL,
    prioritaet  INT NOT NULL DEFAULT 1,     -- höherer Wert gewinnt bei Überschneidung
    dauer_sek   INT NOT NULL DEFAULT 300,   -- Rotationsdauer bei mehreren gleichzeitigen Playlists
    erstellt_am TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_termine_monitor_datum (monitor_id, datum_von, datum_bis),
    KEY idx_termine_playlist (playlist_id),
    CONSTRAINT fk_termine_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id) ON DELETE CASCADE,
    CONSTRAINT fk_termine_playlist
        FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
