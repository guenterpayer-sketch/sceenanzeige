-- Migration: reload_at für "Monitore neu laden"-Button
-- Einmalig in der Datenbank ausführen.
ALTER TABLE monitore ADD COLUMN reload_at DATETIME NULL DEFAULT NULL;
