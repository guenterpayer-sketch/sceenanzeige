<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
session_start();
if (!empty($_SESSION['tm_rolle'])) { echo 'schon eingeloggt: ' . $_SESSION['tm_rolle']; exit; }
$fehler = '';
$weiter = $_GET['weiter'] ?? $_POST['weiter'] ?? '/admin/bibliothek.php';
if (strpos($weiter, '/admin/') !== 0) { $weiter = '/admin/bibliothek.php'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eingabe = $_POST['passwort'] ?? '';
    if ($eingabe === ADMIN_PASSWORT) {
        $_SESSION['tm_rolle'] = 'admin';
        header('Location: ' . $weiter); exit;
    } elseif ($eingabe === REDAKTEUR_PASSWORT) {
        $_SESSION['tm_rolle'] = 'redakteur';
        header('Location: ' . $weiter); exit;
    } else {
        $fehler = 'Falsches Passwort.';
    }
}
echo 'schritt1-ok weiter=' . htmlspecialchars($weiter);
?>
<br><form method="post">
<input type="password" name="passwort">
<button>Login</button>
<?php if ($fehler): ?><p>FEHLER: <?= htmlspecialchars($fehler) ?></p><?php endif; ?>
</form>
