<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require dirname(__DIR__) . '/config.php';

session_start();

// Bereits eingeloggt → weiterleiten
if (!empty($_SESSION['tm_rolle'])) {
    header('Location: /admin/bibliothek.php');
    exit;
}

$fehler = '';
$weiter = $_GET['weiter'] ?? $_POST['weiter'] ?? '/admin/bibliothek.php';

// Nur auf /admin/ beschränken (Sicherheit: kein Open Redirect)
if (strpos($weiter, '/admin/') !== 0) {
    $weiter = '/admin/bibliothek.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eingabe = $_POST['passwort'] ?? '';
    if ($eingabe === ADMIN_PASSWORT) {
        $_SESSION['tm_rolle'] = 'admin';
        header('Location: ' . $weiter);
        exit;
    } elseif ($eingabe === REDAKTEUR_PASSWORT) {
        $_SESSION['tm_rolle'] = 'redakteur';
        header('Location: ' . $weiter);
        exit;
    } else {
        $fehler = 'Falsches Passwort.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Monitor-Backend</title>
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="adm-login-body">
<div class="adm-login-box">
    <div class="adm-login-brand">Tanzschule · Monitor-Backend</div>
    <form method="post" action="/admin/login.php">
        <input type="hidden" name="weiter" value="<?= htmlspecialchars($weiter) ?>">
        <?php if ($fehler): ?>
            <div class="adm-login-fehler"><?= htmlspecialchars($fehler) ?></div>
        <?php endif; ?>
        <label for="passwort">Passwort</label>
        <input type="password" id="passwort" name="passwort" autofocus autocomplete="current-password">
        <button type="submit">Anmelden</button>
    </form>
</div>
</body>
</html>
