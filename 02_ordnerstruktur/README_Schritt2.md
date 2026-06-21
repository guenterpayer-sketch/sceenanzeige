# Schritt 2: Datei- und Ordnerstruktur + .htaccess

Dieses Verzeichnis enthält die Grundstruktur für alle vier Subdomains gemäß
Abschnitt 2 und 4 der Projektdokumentation, bereit zum Upload auf all-inkl.

## Struktur

```
screen.tcpayer.de/
├── .htaccess              ← Sicherheits-/CORS-Header für das gesamte Backend
├── config.php             ← DB-Zugang (geschützt durch .htaccess, vor Upload befüllen)
├── modules/
│   ├── registry.php       ← Liste aller Inhalts-Module
│   ├── bild/
│   ├── stundenplan/
│   ├── ankuendigung/
│   ├── community/
│   ├── uhrzeit/
│   └── song/
├── layouts/
│   ├── registry.php       ← Liste aller Layouts
│   ├── 1-spaltig/
│   ├── 2-spaltig-60-40/
│   ├── 2-spaltig-50-50/
│   └── 3-spaltig-gleich/
├── proxies/                ← nc.php, song.php (Schritt 4)
├── includes/                ← PHP-Helper/Klassen (kein Direktzugriff, per .htaccess gesperrt)
├── assets/
│   ├── css/
│   └── js/
└── uploads/
    └── .htaccess           ← PHP-Ausführung deaktiviert, nur Bild-Dateitypen erlaubt

saal1.tcpayer.de/
├── .htaccess
└── index.html              ← Platzhalter, SAAL_ID = 1 fest hartkodiert

saal2.tcpayer.de/  (SAAL_ID = 2, sonst identisch)
saal3.tcpayer.de/  (SAAL_ID = 3, sonst identisch)
```

## all-inkl KAS — Einrichtung

1. Im KAS unter **Domains/Subdomains** vier Subdomains anlegen:
   `screen`, `saal1`, `saal2`, `saal3` (jeweils `.tcpayer.de`).
2. Jeder Subdomain im KAS **ihren eigenen Ordner** zuweisen — Ordnername
   identisch zur Subdomain (z.B. `screen.tcpayer.de/`), wie in Abschnitt 2
   der Doku festgelegt.
3. Inhalte dieses Pakets 1:1 in die jeweiligen Ordner hochladen (FTP/SFTP).
4. Unter **Datenbanken** im KAS eine MySQL-Datenbank anlegen, Zugangsdaten
   in `screen.tcpayer.de/config.php` eintragen.
5. SQL-Schema aus Schritt 1 (`01_schema.sql`) über phpMyAdmin oder die
   all-inkl-Konsole einspielen.
6. PHP-Version pro Subdomain im KAS auf **PHP 8** stellen (Bereich
   "PHP-Einstellungen" je Subdomain).

## Sicherheitsmaßnahmen in den .htaccess-Dateien

- `screen.tcpayer.de/.htaccess`: sperrt `config.php` und `includes/` gegen
  Direktaufruf, setzt CORS-Header (damit Saal-Subdomains Bilder/Proxy-Daten
  laden dürfen).
- `screen.tcpayer.de/uploads/.htaccess`: deaktiviert PHP-Ausführung in
  diesem Ordner komplett und lässt nur Bild-Dateitypen zu — wichtig, weil
  hier Nutzer-Uploads landen (Schutz gegen hochgeladene Schadskripte).
- `saalX.tcpayer.de/.htaccess`: minimal, sperrt nur versteckte Dateien und
  Verzeichnis-Listing — die Saal-Frontends sind bewusst schlank.

## Noch offen (spätere Schritte)

- `modules/<id>/module.json`, `backend.php`, `proxy.php`, `frontend.js` →
  Schritt 3 + 4
- `layouts/<id>/layout.json`, `template.html` → Schritt 3
- `proxies/nc.php`, `proxies/song.php` → Schritt 4
- Echte `index.html`-Logik je Saal → Schritt 9
- `config.php` mit echten DB-Zugangsdaten befüllen → beim tatsächlichen
  Deployment (Schritt 11)
