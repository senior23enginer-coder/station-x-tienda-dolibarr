<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
ensure_store_experience_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    $stmt = $pdo->prepare('INSERT INTO sx_store_leads (name, email, phone, interest, source) VALUES (:name, :email, :phone, :interest, :source)');
    $stmt->execute([
        'name' => trim((string) ($_POST['name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'interest' => trim((string) ($_POST['interest'] ?? 'Promociones')),
        'source' => 'newsletter',
    ]);
    $_SESSION['sx_flash'] = 'Gracias. Te contactaremos con novedades y promociones.';
}
header('Location: /');
exit;
