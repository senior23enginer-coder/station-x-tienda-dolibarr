<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

unset($_SESSION['sx_user']);
$_SESSION['sx_flash'] = 'Sesion cerrada.';

$target = '/cuenta.php';
if (isset($_GET['next']) && is_string($_GET['next'])) {
    $next = '/' . ltrim((string) $_GET['next'], '/');
    if (preg_match('#^/[a-z0-9/_\-.?=&]+$#i', $next)) {
        $target = $next;
    }
}

header('Location: ' . $target);
exit;
