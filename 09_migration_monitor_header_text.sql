-- ============================================================================
-- Migration 09 — Header-Text pro Monitor (Schritt 9: Monitor-Frontend)
-- ============================================================================
-- Zweck: Jeder Monitor bekommt einen konfigurierbaren Begrüßungstext, der
--   im Kiosk-Header mittig angezeigt wird (z.B. „Willkommen im Tanzcenter Payer").
--   NULL = kein Text; der Header bleibt leer in der Mitte.
--
-- Plattform: MySQL 8 / MariaDB (all-inkl). Setzt Migrationen 01–08 voraus.
-- ============================================================================

ALTER TABLE monitore
    ADD COLUMN header_text VARCHAR(255) DEFAULT NULL AFTER subdomain;
