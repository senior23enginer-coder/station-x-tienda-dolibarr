<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
$cartCount = array_sum(load_cart($pdo, $currentUser));
$favoriteIds = fetch_favorite_ids($pdo, $currentUser);
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'recent');
$products = fetch_store_products($pdo, $prefix, $entity, 120);
$products = filter_store_products($products, '', '', 0, 0, false, $sort);
$pagination = paginate_items($products, $page, 24);
$pageProducts = $pagination['items'];
$pageParams = ['sort' => $sort];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mas recientes | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Mas recientes', $cartCount); ?>
<main class="page-shell">
    <section class="page-hero compact-page-hero"><h1>Mas recientes</h1><p>Ultimos productos publicados en el inventario. Mostrando <?php echo (int) $pagination['from']; ?>-<?php echo (int) $pagination['to']; ?> de <?php echo (int) $pagination['total']; ?>.</p></section>
    <div class="result-toolbar">
        <span>Pagina <?php echo (int) $pagination['page']; ?> de <?php echo (int) $pagination['pages']; ?></span>
        <form method="get" action="/recientes.php">
            <select name="sort" onchange="this.form.submit()">
                <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Mas recientes</option>
                <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Menor precio</option>
                <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Mayor precio</option>
                <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Nombre</option>
            </select>
        </form>
    </div>
    <section class="grid">
        <?php foreach ($pageProducts as $product): render_store_product_card($product, $favoriteIds); endforeach; ?>
    </section>
    <?php render_pagination($pagination, $pageParams); ?>
</main>
<?php store_footer(); ?>
</body>
</html>
