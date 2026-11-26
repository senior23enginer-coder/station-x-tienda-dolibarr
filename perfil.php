<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$pdo && ensure_store_experience_tables($pdo);
$currentUser = $_SESSION['sx_user'] ?? null;
if (!$currentUser) {
    $_SESSION['sx_flash'] = 'Inicia sesion para ver tu perfil.';
    header('Location: /cuenta.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['sx_flash'] = 'La sesion expiro. Intenta de nuevo.';
        header('Location: /perfil.php');
        exit;
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    if (($_POST['action'] ?? '') === 'save_profile' && $name !== '') {
        $stmt = $pdo->prepare('UPDATE sx_store_users SET name = :name, phone = :phone WHERE id = :id');
        $stmt->execute(['name' => $name, 'phone' => $phone, 'id' => (int) $currentUser['id']]);
        $_SESSION['sx_user']['name'] = $name;
        $_SESSION['sx_user']['phone'] = $phone;
        $_SESSION['sx_flash'] = 'Perfil actualizado.';
    }
    if (($_POST['action'] ?? '') === 'save_address') {
        $stmt = $pdo->prepare('INSERT INTO sx_store_addresses (user_id, label, address, city, phone, is_default) VALUES (:user_id, :label, :address, :city, :phone, :is_default)');
        $stmt->execute([
            'user_id' => (int) $currentUser['id'],
            'label' => trim((string) ($_POST['label'] ?? 'Casa')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? 'Tulua')),
            'phone' => trim((string) ($_POST['address_phone'] ?? '')),
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
        ]);
        $_SESSION['sx_flash'] = 'Direccion guardada.';
    }
    header('Location: /perfil.php');
    exit;
}

$currentUser = $_SESSION['sx_user'];
$cart = load_cart($pdo, $currentUser);
$cartItems = fetch_cart_items($pdo, $prefix, $cart);
$cartCount = array_sum($cart);
$flash = $_SESSION['sx_flash'] ?? null;
$addresses = fetch_user_addresses($pdo, $currentUser);
unset($_SESSION['sx_flash']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi perfil | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Mi perfil', $cartCount, $cartItems); ?>
<?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
<main class="page-shell">
    <section class="profile-ml-hero">
        <div class="profile-avatar"><?php echo e(strtoupper(substr((string) $currentUser['name'], 0, 2))); ?></div>
        <div><h1><?php echo e($currentUser['name']); ?></h1><p><?php echo e($currentUser['email']); ?></p></div>
    </section>
    <section class="account-dashboard">
        <aside class="account-side-menu">
            <strong>Mi cuenta</strong>
            <a class="active" href="/perfil.php">Mi perfil</a>
            <a href="/compras.php">Compras</a>
            <a href="/pedidos.php">Pedidos</a>
            <a href="/favoritos.php">Favoritos</a>
            <a href="/busquedas.php">Busquedas guardadas</a>
            <a href="/ayuda.php">Ayuda</a>
        </aside>
        <div class="account-card-grid">
        <form class="form-card profile-card account-tile wide" method="post">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save_profile">
            <strong>Informacion de tu perfil</strong>
            <span>Datos personales y de contacto.</span>
            <input type="text" name="name" value="<?php echo e($currentUser['name']); ?>" placeholder="Nombre completo" required>
            <input type="email" value="<?php echo e($currentUser['email']); ?>" disabled>
            <input type="tel" name="phone" value="<?php echo e($currentUser['phone'] ?? ''); ?>" placeholder="Telefono / WhatsApp">
            <button type="submit">Guardar cambios</button>
        </form>
        <a class="account-tile" href="/pedidos.php"><strong>Pedidos</strong><span>Seguimiento de estado, pago y entrega.</span></a>
        <a class="account-tile" href="/favoritos.php"><strong>Favoritos</strong><span>Productos guardados.</span></a>
        <a class="account-tile" href="/busquedas.php"><strong>Busquedas</strong><span>Historial de consultas.</span></a>
        <form class="form-card profile-card account-tile wide" method="post">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save_address">
            <strong>Direccion de entrega</strong>
            <span>Direcciones guardadas en tu cuenta.</span>
            <input name="label" placeholder="Casa, trabajo, local" required>
            <input name="address" placeholder="Direccion completa" required>
            <input name="city" placeholder="Ciudad" value="Tulua" required>
            <input name="address_phone" placeholder="Telefono de contacto">
            <label><input type="checkbox" name="is_default"> Direccion principal</label>
            <button type="submit">Guardar direccion</button>
        </form>
        <div class="history-list account-tile wide">
            <strong>Direcciones guardadas</strong>
            <?php foreach ($addresses as $address): ?>
                <a><strong><?php echo e($address['label']); ?></strong><span><?php echo e($address['address']); ?> - <?php echo e($address['city']); ?></span><small><?php echo $address['is_default'] ? 'Principal' : 'Guardada'; ?></small></a>
            <?php endforeach; ?>
        </div>
        </div>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
