<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
$cartCount = array_sum(load_cart($pdo, $currentUser));

$ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
if (!$ids) {
    $ids = $_SESSION['sx_compare'] ?? [];
}
if ((int) ($_GET['add'] ?? 0) > 0) {
    $ids[] = (int) $_GET['add'];
}
if ((int) ($_GET['remove'] ?? 0) > 0) {
    $remove = (int) $_GET['remove'];
    $ids = array_values(array_filter($ids, static fn($id) => (int) $id !== $remove));
}
if (($_GET['clear'] ?? '') === '1') {
    $ids = [];
}
$ids = array_slice(array_values(array_unique($ids)), 0, 4);
$_SESSION['sx_compare'] = $ids;
$products = [];
foreach ($ids as $id) {
    $product = fetch_product_detail($pdo, $prefix, $id);
    if ($product) {
        $products[] = $product;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comparar productos | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Comparar', $cartCount); ?>
<main class="page-shell">
    <section class="page-hero compact-page-hero"><h1>Comparar productos</h1><p>Compara hasta 4 productos por precio, categoria y disponibilidad.</p><?php if ($products): ?><a class="button ghost" href="/comparar.php?clear=1">Limpiar comparador</a><?php endif; ?></section>
    <?php if (!$products): ?><div class="empty-cart">Agrega productos desde el boton Comparar en el catalogo.</div><?php endif; ?>
    <?php if ($products): ?>
        <section class="compare-grid">
            <?php foreach ($products as $product): ?>
                <?php $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price']; $image = product_image_url((string) $product['ref'], 420); ?>
                <article>
                    <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e(display_name((string) $product['label'])); ?>"><?php endif; ?>
                    <h2><?php echo e(display_name((string) $product['label'])); ?></h2>
                    <strong><?php echo e(money_value($price)); ?></strong>
                    <span><?php echo e(product_category($product)); ?></span>
                    <span><?php echo (float) ($product['stock'] ?? 0) > 0 ? 'Disponible' : 'Consultar'; ?></span>
                    <a href="<?php echo e(product_url($product)); ?>">Ver detalle</a>
                    <a href="/comparar.php?remove=<?php echo (int) $product['rowid']; ?>">Quitar</a>
                </article>
            <?php endforeach; ?>
        </section>
        <section class="compare-table">
            <div><strong>Producto</strong><?php foreach ($products as $product): ?><span><?php echo e(display_name((string) $product['label'])); ?></span><?php endforeach; ?></div>
            <div><strong>Precio</strong><?php foreach ($products as $product): ?><span><?php echo e(money_value($product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'])); ?></span><?php endforeach; ?></div>
            <div><strong>Categoria</strong><?php foreach ($products as $product): ?><span><?php echo e(product_category($product)); ?></span><?php endforeach; ?></div>
            <div><strong>Disponibilidad</strong><?php foreach ($products as $product): ?><span><?php echo (float) ($product['stock'] ?? 0) > 0 ? 'Disponible' : 'Consultar'; ?></span><?php endforeach; ?></div>
        </section>
    <?php endif; ?>
</main>
<?php store_footer(); ?>
</body>
</html>
