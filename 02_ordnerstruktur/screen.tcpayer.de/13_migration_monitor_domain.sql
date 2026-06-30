-- Migration: Monitor-Subdomain → vollständige Domain
-- Bestehende Einträge in `monitore.subdomain` waren nur die Subdomain-Teile
-- (z.B. "saal1"). Sie werden auf die vollständige Domain aktualisiert.
-- Anpassen falls die echten Domains abweichen!

UPDATE monitore SET subdomain = CONCAT(subdomain, '.tcpayer.de')
WHERE subdomain NOT LIKE '%.%';
