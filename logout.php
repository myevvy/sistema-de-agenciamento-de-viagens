<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';

authStart();
$submitted = (string) ($_POST['csrf_token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $submitted)) {
    authLogout();
    header('Location: login.php');
    exit;
}
header('Location: painel.php');
exit;
