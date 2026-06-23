-- ============================================================================
-- Tanzschule Monitor-System
-- Schritt 1: Datenbankschema (vollständig, gemäß Projektdokumentation Abschnitt 8)
-- Zielplattform: all-inkl (MySQL / MariaDB, PHP 8)
-- ============================================================================
-- Hinweis: Reihenfolge der CREATE TABLE Statements berücksichtigt Foreign-Key-
-- Abhängigkeiten. Engine InnoDB für FK-Unterstützung, utf8mb4 für volle
-- Unicode-Unterstützung (Emojis, Sonderzeichen in Texten/Songtiteln etc.).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Monitore (je Monitor eine Subdomain; früher "Säle" genannt)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monitore (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    subdomain   VARCHAR(100) NOT NULL,          -- z.B. "saal1" (ohne Domain)
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_subdomain (subdomain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Einstellungen pro Monitor (NC-API-Key, Song-API-URL)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS einstellungen (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id    INT UNSIGNED NOT NULL,
    nc_api_key    VARCHAR(255) DEFAULT NULL,
    song_api_url  VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monitor (monitor_id),
    CONSTRAINT fk_einstellungen_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Modul-Instanzen (Bibliothek, wiederverwendbare Bausteine)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS modul_instanzen (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    modul_typ     VARCHAR(50)  NOT NULL,        -- z.B. "bild", "stundenplan", "ankuendigung"
    name          VARCHAR(150) NOT NULL,        -- z.B. "Veranstaltung", "Workshoptermine"
    einstellungen JSON DEFAULT NULL,            -- modul-spezifische Konfiguration
    erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_modul_typ (modul_typ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Unter-Inhalte einer Modul-Instanz (z.B. einzelne Bilder, Ankündigungstexte)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS modul_instanz_inhalte (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    modul_instanz_id  INT UNSIGNED NOT NULL,
    dateiname         VARCHAR(255) DEFAULT NULL,   -- relevant für Bild-Modul
    text_inhalt       TEXT DEFAULT NULL,           -- relevant für Ankündigung-Modul
    ablaufdatum       DATE DEFAULT NULL,           -- optional, z.B. für Ankündigungen
    reihenfolge       INT UNSIGNED NOT NULL DEFAULT 0,
    dauer_sek         INT UNSIGNED NOT NULL DEFAULT 10,
    PRIMARY KEY (id),
    KEY idx_modul_instanz (modul_instanz_id),
    CONSTRAINT fk_inhalte_modul_instanz
        FOREIGN KEY (modul_instanz_id) REFERENCES modul_instanzen (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Playlists (Hauptfläche)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playlists (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    aktiv       TINYINT(1) NOT NULL DEFAULT 1,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Playlist-Layout (1:1 zu Playlist)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playlist_layout (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    playlist_id     INT UNSIGNED NOT NULL,
    spalten_anzahl  TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1, 2 oder 3
    spalte1_breite  TINYINT UNSIGNED DEFAULT NULL,         -- Prozent
    spalte2_breite  TINYINT UNSIGNED DEFAULT NULL,
    spalte3_breite  TINYINT UNSIGNED DEFAULT NULL,
    header_uhrzeit  TINYINT(1) NOT NULL DEFAULT 1,
    footer_ticker   TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_playlist (playlist_id),
    CONSTRAINT fk_layout_playlist
        FOREIGN KEY (playlist_id) REFERENCES playlists (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_spalten_anzahl CHECK (spalten_anzahl BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Monitor-Zeitplan (monitor-zentrisch): welche Playlist läuft wann auf
--    welchem Monitor. Ersetzt die früheren playlist_zeitregeln + playlist_saele.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monitor_zeitplan (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id  INT UNSIGNED NOT NULL,
    playlist_id INT UNSIGNED NOT NULL,
    wochentage  VARCHAR(20) NOT NULL,      -- z.B. "1,2,3,4,5" (Mo-Fr), 1=Montag
    von_uhrzeit TIME DEFAULT NULL,         -- NULL = keine Uhrzeitgrenze (dauerhaft)
    bis_uhrzeit TIME DEFAULT NULL,         -- NULL = keine Uhrzeitgrenze (dauerhaft)
    prioritaet  INT NOT NULL DEFAULT 0,    -- höherer Wert gewinnt; Einträge ohne Uhrzeit gelten als Fallback (niedrigste Priorität)
    PRIMARY KEY (id),
    KEY idx_monitor (monitor_id),
    KEY idx_playlist (playlist_id),
    CONSTRAINT fk_zeitplan_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_zeitplan_playlist
        FOREIGN KEY (playlist_id) REFERENCES playlists (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. Playlist-Spalten-Inhalte (Verweis auf Modul-Instanzen je Spalte)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playlist_spalten_inhalte (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    playlist_id      INT UNSIGNED NOT NULL,
    spalte           TINYINT UNSIGNED NOT NULL,     -- 1, 2 oder 3
    reihenfolge      INT UNSIGNED NOT NULL DEFAULT 0,
    modul_instanz_id INT UNSIGNED NOT NULL,
    layout_override  VARCHAR(100) DEFAULT NULL,     -- NULL = Standard-Layout der Playlist gilt
    PRIMARY KEY (id),
    KEY idx_playlist_spalte (playlist_id, spalte),
    KEY idx_modul_instanz (modul_instanz_id),
    CONSTRAINT fk_spalteninhalte_playlist
        FOREIGN KEY (playlist_id) REFERENCES playlists (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_spalteninhalte_modul_instanz
        FOREIGN KEY (modul_instanz_id) REFERENCES modul_instanzen (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_spalte CHECK (spalte BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. Ticker-Playlists (eigenständiges Footer-System, siehe Abschnitt 7)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticker_playlists (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    aktiv       TINYINT(1) NOT NULL DEFAULT 1,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. Ticker-Texteinträge
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticker_eintraege (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticker_playlist_id INT UNSIGNED NOT NULL,
    text              TEXT NOT NULL,
    reihenfolge       INT UNSIGNED NOT NULL DEFAULT 0,
    dauer_sek         INT UNSIGNED NOT NULL DEFAULT 8,
    PRIMARY KEY (id),
    KEY idx_ticker_playlist (ticker_playlist_id),
    CONSTRAINT fk_ticker_eintraege_playlist
        FOREIGN KEY (ticker_playlist_id) REFERENCES ticker_playlists (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. Ticker-Zeitplan (monitor-zentrisch, Schritt 8): welcher Ticker läuft wann
--     auf welchem Monitor. Ersetzt die früheren ticker_zeitregeln +
--     ticker_playlist_saele. KEIN Prioritätsfeld — mehrere gleichzeitig aktive
--     Ticker werden am Monitor GEMISCHT (siehe Abschnitt 7).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticker_zeitplan (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id         INT UNSIGNED NOT NULL,
    ticker_playlist_id INT UNSIGNED NOT NULL,
    wochentage         VARCHAR(20) NOT NULL,   -- z.B. "1,2,3,4,5" (Mo-Fr), 1=Montag
    von_uhrzeit        TIME DEFAULT NULL,       -- NULL = keine Uhrzeitgrenze (dauerhaft)
    bis_uhrzeit        TIME DEFAULT NULL,       -- NULL = keine Uhrzeitgrenze (dauerhaft)
    PRIMARY KEY (id),
    KEY idx_monitor (monitor_id),
    KEY idx_ticker (ticker_playlist_id),
    CONSTRAINT fk_ticker_zeitplan_monitor
        FOREIGN KEY (monitor_id) REFERENCES monitore (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ticker_zeitplan_ticker
        FOREIGN KEY (ticker_playlist_id) REFERENCES ticker_playlists (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Optional: Beispiel-Seed für die Monitore (Subdomains aus Abschnitt 2)
-- Bei Bedarf einkommentieren oder im Backend (Bereich "Monitore") anlegen.
-- ============================================================================
-- INSERT INTO monitore (name, subdomain) VALUES
--   ('Saal 1', 'saal1'),
--   ('Saal 2', 'saal2'),
--   ('Saal 3', 'saal3');
