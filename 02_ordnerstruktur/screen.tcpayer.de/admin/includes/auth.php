<?php
/**
 * admin/includes/auth.php
 *
 * Session-Start + Login-Prüfung für alle Admin-Seiten.
 * Wird von bootstrap.php eingebunden — nicht direkt aufrufen.
 *
 * Rollen:
 *   'redakteur' → Bibliothek, Mediathek, Playlists, Ticker
 *   'admin'     → alles (zusätzlich Monitore, FRET-Geräte)
 */

declare(strict_types=1);

session_start();

if (empty($_SESSION['tm_rolle'])) {
    $weiter = $_SERVER['REQUEST_URI'] ?? '/admin/bibliothek.php';
    header('Location: /admin/login.php?weiter=' . urlencode($weiter));
    exit;
}

function tm_ist_admin(): bool
{
    return ($_SESSION['tm_rolle'] ?? '') === 'admin';
}

function tm_nur_admin(): void
{
    if (!tm_ist_admin()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Kein Zugriff</title>'
            . '<link rel="stylesheet" href="/assets/css/admin.css"></head><body>'
            . '<header class="adm-topbar"><div class="adm-brand">Tanzschule&nbsp;·&nbsp;Monitor-Backend</div></header>'
            . '<main class="adm-main"><h1>Kein Zugriff</h1>'
            . '<p>Diese Seite ist nur für Administratoren zugänglich.</p>'
            . '<p><a href="/admin/bibliothek.php">← Zurück zur Bibliothek</a></p>'
            . '</main></body></html>';
        exit;
    }
}
