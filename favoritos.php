<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
if (!$currentUser) {
    $_SESSION['sx_flash'] = 'Inicia sesion para ver favoritos.';
    header('Location: /cuenta.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['sx_flash'] = 'La sesion expiro.';
        header('Location: /favoritos.php');
        exit;
    }
    ensure_store_experience_tables($pdo);
    $action = (string) ($_POST['action'] ?? 'remove');
    if ($action === 'create_list') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            $stmt = $pdo->prepare('INSERT INTO sx_store_favorite_lists (user_id, name) VALUES (:user_id, :name)');
            $stmt->execute(['user_id' => (int) $currentUser['id'], 'name' => $name]);
            $_SESSION['sx_flash'] = 'Lista creada.';
        }
    } elseif ($action === 'add_to_list') {
        $listId = (int) ($_POST['list_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM sx_store_favorite_lists WHERE id = :id AND user_id = :user_id');
        $check->execute(['id' => $listId, 'user_id' => (int) $currentUser['id']]);
        if ($check->fetch()) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO sx_store_favorite_list_items (list_id, product_id) VALUES (:list_id, :product_id)');
            $stmt->execute(['list_id' => $listId, 'product_id' => $productId]);
            $_SESSION['sx_flash'] = 'Producto agregado a la lista.';
        }
    } else {
        $pdo->prepare('DELETE FROM sx_store_favorites WHERE user_id = :user_id AND product_id = :product_id')->execute([
            'user_id' => (int) $currentUser['id'],
            'product_id' => (int) ($_POST['product_id'] ?? 0),
        ]);
        $_SESSION['sx_flash'] = 'Producto quitado de favoritos.';
    }
    header('Location: /favoritos.php');
    exit;
}
$cart = load_cart($pdo, $currentUser);
$cartItems = fetch_cart_items($pdo, $prefix, $cart);
$cartCount = array_sum($cart);
$favoriteIds = fetch_favorite_ids($pdo, $currentUser);
$favorites = fetch_user_favorites($pdo, $prefix, $currentUser);
ensure_store_experience_tables($pdo);
$listStmt = $pdo->prepare('SELECT l.*, COUNT(i.id) AS item_count FROM sx_store_favorite_lists l LEFT JOIN sx_store_favorite_list_items i ON i.list_id = l.id WHERE l.user_id = :user_id GROUP BY l.id, l.user_id, l.name, l.created_at ORDER BY l.id DESC');
$listStmt->execute(['user_id' => (int) $currentUser['id']]);
$favoriteLists = $listStmt->fetchAll();
$flash = $_SESSION['sx_flash'] ?? null;
unset($_SESSION['sx_flash']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Favoritos | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Favoritos', $cartCount, $cartItems); ?>
<?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
<main class="page-shell">
    <nav class="breadcrumbs" aria-label="Ruta"><a href="/">Compras</a><span>/</span><strong>Favoritos</strong></nav>
    <section class="meli-list-header">
        <div>
            <h1>Favoritos</h1>
            <div class="meli-tabs"><a class="active" href="/favoritos.php">Mis favoritos</a><a href="#listas">Listas (<?php echo count($favoriteLists); ?>)</a></div>
        </div>
        <form class="create-list-form" method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="create_list"><input name="name" placeholder="Nueva lista"><button class="button primary" type="submit">Crear lista</button></form>
    </section>
    <section class="favorite-list-panel" id="listas">
        <?php if (!$favoriteLists): ?><span>Crea listas como Regalos, Comprar luego o Consolas.</span><?php endif; ?>
        <?php foreach ($favoriteLists as $list): ?><article><strong><?php echo e($list['name']); ?></strong><span><?php echo (int) $list['item_count']; ?> productos</span></article><?php endforeach; ?>
    </section>
    <?php if (!$favorites): ?>
        <div class="empty-cart visual-empty"><strong>Aun no tienes favoritos.</strong><span>Guarda productos desde el catalogo para verlos aqui.</span><a href="/#catalogo">Explorar catalogo</a></div>
    <?php else: ?>
        <section class="meli-list">
            <?php foreach ($favorites as $product): ?>
                <?php
                $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'];
                $name = display_name((string) $product['label']);
                $image = product_image_url((string) $product['ref'], 260);
                ?>
                <article class="meli-list-item">
                    <a class="meli-list-image" href="<?php echo e(product_url($product)); ?>"><?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>"><?php endif; ?></a>
                    <div>
                        <h2><a href="<?php echo e(product_url($product)); ?>"><?php echo e($name); ?></a></h2>
                        <strong><?php echo e(money_value($price)); ?></strong>
                        <span>Envio a confirmar</span>
                        <small><?php echo e(product_category($product)); ?></small>
                        <div class="inline-actions">
                            <a href="<?php echo e(product_url($product)); ?>">Ver producto</a>
                            <?php if ($favoriteLists): ?>
                                <form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="add_to_list"><input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>"><select name="list_id"><?php foreach ($favoriteLists as $list): ?><option value="<?php echo (int) $list['id']; ?>"><?php echo e($list['name']); ?></option><?php endforeach; ?></select><button type="submit">Agregar a lista</button></form>
                            <?php endif; ?>
                            <form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>"><button type="submit">Eliminar</button></form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php store_footer(); ?>
</body>
</html>
