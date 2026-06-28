-- Migration 10: Playlist-Rotation im Monitor-Zeitplan
-- Wie lange eine Playlist läuft bevor zur nächsten rotiert wird (bei gleicher Priorität).
-- Standard: 300 Sekunden (5 Minuten).

ALTER TABLE monitor_zeitplan
    ADD COLUMN dauer_sek INT NOT NULL DEFAULT 300 AFTER prioritaet;
