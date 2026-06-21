-- ============================================================================
-- Tanzschule Monitor-System
-- Migration: Abschnitt-16-Korrekturen (Konzept-Stand nach Überarbeitung)
-- ============================================================================
-- Bringt die bereits LIVE eingespielte Datenbank (d04768bb) vom ursprünglichen
-- Schritt-1-Schema (01_schema.sql) auf den aktuellen Konzeptstand gemäß
-- Abschnitt 16 der Projektdokumentation + Notiz_Schritt5_Mediathek.md.
--
-- WICHTIG:
--   * EINMALIG ausführen (z.B. über phpMyAdmin auf der Live-DB).
--   * 01_schema.sql bleibt unverändert als Dokumentation des Urstands —
--     diese Datei beschreibt nur die Differenz dazu.
--   * MySQL 8 / MariaDB (all-inkl). ADD/CHANGE COLUMN sind NICHT idempotent;
--     bei erneutem Lauf melden bereits existierende Spalten einen Fehler.
--   * `dateiname` in modul_instanz_inhalte bleibt vorerst erhalten und wird
--     erst in Schritt 5 (Mediathek-Umstellung) entfernt — bis dahin laufen
--     dateiname (Altbestand) und mediathek_id (neu) parallel.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. einstellungen: zwei getrennte Nimbuscloud-API-Keys
--    (Abschnitt 9: Stundenplan-Key aktiv, Stammdaten-Key zurückgestellt)
--    Der bisherige `nc_api_key` wird zum Stundenplan-Key (aktiv genutzt).
-- ----------------------------------------------------------------------------
ALTER TABLE einstellungen
    CHANGE COLUMN nc_api_key nc_api_key_stundenplan VARCHAR(255) DEFAULT NULL;

ALTER TABLE einstellungen
    ADD COLUMN nc_api_key_stammdaten VARCHAR(255) DEFAULT NULL AFTER nc_api_key_stundenplan;

-- ----------------------------------------------------------------------------
-- 2. modul_instanzen: aktiv-Flag (pausiert die GESAMTE Instanz ohne Löschen)
-- ----------------------------------------------------------------------------
ALTER TABLE modul_instanzen
    ADD COLUMN aktiv TINYINT(1) NOT NULL DEFAULT 1 AFTER einstellungen;

-- ----------------------------------------------------------------------------
-- 3. mediathek: zentrale Bild-Verwaltung mit Duplikat-Erkennung
--    (Abschnitt 5 / Notiz_Schritt5_Mediathek.md)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mediathek (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dateiname      VARCHAR(255) NOT NULL,        -- tatsächlicher Dateiname in uploads/
    original_name  VARCHAR(255) DEFAULT NULL,    -- ursprünglicher Upload-Name, für die Anzeige
    datei_hash     CHAR(64) NOT NULL,            -- SHA-256 des Dateiinhalts (Duplikat-Erkennung)
    breite         SMALLINT UNSIGNED DEFAULT NULL,
    hoehe          SMALLINT UNSIGNED DEFAULT NULL,
    hochgeladen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_hash (datei_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. modul_instanz_inhalte: mediathek_id, aktiv, gueltig_bis
--    (Abschnitt 5: generische Eintrags-Felder; ablaufdatum -> gueltig_bis)
-- ----------------------------------------------------------------------------
ALTER TABLE modul_instanz_inhalte
    ADD COLUMN mediathek_id INT UNSIGNED DEFAULT NULL AFTER modul_instanz_id;

-- Umbenennung des Datums-Feldes (Bedeutung unverändert: Datum, ab dem der
-- Eintrag automatisch nicht mehr angezeigt wird; NULL = unbegrenzt).
ALTER TABLE modul_instanz_inhalte
    CHANGE COLUMN ablaufdatum gueltig_bis DATE DEFAULT NULL;

ALTER TABLE modul_instanz_inhalte
    ADD COLUMN aktiv TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE modul_instanz_inhalte
    ADD KEY idx_mediathek (mediathek_id),
    ADD CONSTRAINT fk_inhalte_mediathek
        FOREIGN KEY (mediathek_id) REFERENCES mediathek (id)
        ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- Hinweis: Nach dieser Migration entspricht die DB dem Konzeptstand aus
-- Abschnitt 8 (in der überarbeiteten Fassung). Die endgültige Entfernung von
-- modul_instanz_inhalte.dateiname erfolgt in Schritt 5, sobald der Upload auf
-- die Mediathek umgestellt ist und Altbestände migriert wurden.
-- ============================================================================
