<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
$cart = load_cart($pdo, $currentUser);
$cartItems = fetch_cart_items($pdo, $prefix, $cart);
$cartCount = array_sum($cart);
$favoriteIds = fetch_favorite_ids($pdo, $currentUser);
$erpCategories = fetch_store_categories($pdo, $prefix, $entity);
$categories = array_values(array_filter(array_map(static fn($item) => display_name((string) ($item['label'] ?? '')), $erpCategories)));
if (!$categories) {
    $categories = ['Consolas', 'Videojuegos', 'Accesorios', 'Coleccionables', 'Gaming'];
}
$category = trim((string) ($_GET['c'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'recent');
$min = max(0, (float) ($_GET['min'] ?? 0));
$max = max(0, (float) ($_GET['max'] ?? 0));
$availability = trim((string) ($_GET['availability'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$platform = trim((string) ($_GET['platform'] ?? ''));
$condition = trim((string) ($_GET['condition'] ?? ''));
$brand = trim((string) ($_GET['brand'] ?? ''));
$pricedOnly = ($_GET['priced'] ?? '') === '1';
$imageOnly = ($_GET['image'] ?? '') === '1';
$products = fetch_store_products($pdo, $prefix, $entity);
$baseResults = $category !== '' ? filter_store_products($products, '', $category, $min, $max, $availability === 'available', $sort) : [];
$typeFacets = facet_counts($baseResults, 'product_inferred_type');
$platformFacets = facet_counts($baseResults, 'product_inferred_platform');
$conditionFacets = facet_counts($baseResults, 'product_inferred_condition');
$brandFacets = facet_counts($baseResults, 'product_inferred_brand');
$results = apply_store_extended_filters($baseResults, [
    'priced' => $pricedOnly,
    'image' => $imageOnly,
    'availability' => $availability,
    'type' => $type,
    'platform' => $platform,
    'condition' => $condition,
    'brand' => $brand,
]);
$discountProducts = array_values(array_filter($baseResults, static fn($product) => product_discount_percent($product) > 0));
$ratedProducts = $baseResults;
usort($ratedProducts, static fn($a, $b) => (float) ($b['avg_rating'] ?? 0) <=> (float) ($a['avg_rating'] ?? 0));
$recommendedProducts = array_slice($baseResults, 0, 12);
$pagination = paginate_items($results, $page, 20);
$pageProducts = $pagination['items'];
if ($category !== '') {
    save_store_search($pdo, $currentUser, '', $category, count($results));
}
$pageParams = ['c' => $category, 'min' => $min > 0 ? (int) $min : '', 'max' => $max > 0 ? (int) $max : '', 'availability' => $availability, 'type' => $type, 'platform' => $platform, 'condition' => $condition, 'brand' => $brand, 'priced' => $pricedOnly ? '1' : '', 'image' => $imageOnly ? '1' : '', 'sort' => $sort];
$buildCategoryUrl = static function (array $extra) use ($pageParams): string {
    $params = array_merge($pageParams, $extra);
    unset($params['page']);
    return '/categoria.php?' . http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null && $value !== false));
};
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Categorias | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look listing-page">
<?php store_header('Categorias', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="page-hero compact-page-hero">
        <h1><?php echo $category !== '' ? e($category) : 'Categorias'; ?></h1>
        <p><?php echo $category !== '' ? count($results) . ' producto(s) en esta categoria. Mostrando ' . (int) $pagination['from'] . '-' . (int) $pagination['to'] . '.' : 'Explora el inventario real por departamentos.'; ?></p>
    </section>
    <?php if ($category === ''): ?>
        <section class="category-directory">
            <div class="category-directory-card">
                <h2>Categorias para comprar</h2>
                <p>Departamentos creados en Dolibarr, con imagen tomada del ultimo producto publicado.</p>
                <div class="category-directory-grid">
                    <?php foreach (($erpCategories ?: array_map(static fn($label) => ['label' => $label, 'product_count' => 0, 'sample_ref' => null], $categories)) as $itemRow): ?>
                        <?php
                        $item = display_name((string) ($itemRow['label'] ?? 'Categoria'));
                        $image = !empty($itemRow['sample_ref']) ? product_image_url((string) $itemRow['sample_ref'], 320) : null;
                        if (!$image) {
                            foreach ($products as $candidate) {
                                if (product_matches_category($candidate, $item)) {
                                    $image = product_image_url((string) $candidate['ref'], 320);
                                    break;
                                }
                            }
                        }
                        ?>
                        <a class="department-link category-directory-link" href="/categoria.php?c=<?php echo e(rawurlencode($item)); ?>">
                            <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($item); ?>" loading="lazy"><?php endif; ?>
                            <span class="category-count"><?php echo (int) ($itemRow['product_count'] ?? 0); ?> productos</span>
                            <strong><?php echo e($item); ?></strong>
                            <em>Ver productos</em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="catalog-layout">
            <button class="mobile-filter-toggle" type="button" data-filter-toggle>Filtros y ordenar</button>
            <aside class="catalog-filters" data-filter-panel>
                <div class="filter-heading"><strong>Filtros</strong><a href="/categoria.php?c=<?php echo e(rawurlencode($category)); ?>">Limpiar</a></div>
                <form class="filter-form" method="get" action="/categoria.php">
                    <input type="hidden" name="c" value="<?php echo e($category); ?>">
                    <label>Precio minimo<input type="number" min="0" name="min" value="<?php echo $min > 0 ? e((string) (int) $min) : ''; ?>"></label>
                    <label>Precio maximo<input type="number" min="0" name="max" value="<?php echo $max > 0 ? e((string) (int) $max) : ''; ?>"></label>
                    <label>Disponibilidad
                        <select name="availability">
                            <option value="">Todas</option>
                            <option value="available" <?php echo $availability === 'available' ? 'selected' : ''; ?>>Disponible en stock</option>
                            <option value="consult" <?php echo $availability === 'consult' ? 'selected' : ''; ?>>Consultar disponibilidad</option>
                        </select>
                    </label>
                    <div class="facet-block"><h2>Tipo</h2><?php foreach (array_slice($typeFacets, 0, 8, true) as $value => $count): ?><a class="<?php echo $type === $value ? 'active' : ''; ?>" href="<?php echo e($buildCategoryUrl(['type' => $type === $value ? '' : $value])); ?>"><?php echo e(filter_label('type', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?></div>
                    <div class="facet-block"><h2>Plataforma</h2><?php foreach (array_slice($platformFacets, 0, 8, true) as $value => $count): ?><a class="<?php echo $platform === $value ? 'active' : ''; ?>" href="<?php echo e($buildCategoryUrl(['platform' => $platform === $value ? '' : $value])); ?>"><?php echo e(filter_label('platform', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?></div>
                    <div class="facet-block"><h2>Condicion</h2><?php foreach ($conditionFacets as $value => $count): ?><a class="<?php echo $condition === $value ? 'active' : ''; ?>" href="<?php echo e($buildCategoryUrl(['condition' => $condition === $value ? '' : $value])); ?>"><?php echo e(filter_label('condition', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?></div>
                    <?php if ($brandFacets): ?><div class="facet-block"><h2>Marca / saga</h2><?php foreach (array_slice($brandFacets, 0, 10, true) as $value => $count): ?><a class="<?php echo $brand === $value ? 'active' : ''; ?>" href="<?php echo e($buildCategoryUrl(['brand' => $brand === $value ? '' : $value])); ?>"><?php echo e(filter_label('brand', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?></div><?php endif; ?>
                    <label class="check-filter"><input type="checkbox" name="priced" value="1" <?php echo $pricedOnly ? 'checked' : ''; ?>> Solo con precio</label>
                    <label class="check-filter"><input type="checkbox" name="image" value="1" <?php echo $imageOnly ? 'checked' : ''; ?>> Solo con imagen</label>
                    <label>Ordenar
                        <select name="sort">
                            <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Mas recientes</option>
                            <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Menor precio</option>
                            <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Mayor precio</option>
                            <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Nombre</option>
                            <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Mejor calificados</option>
                            <option value="stock" <?php echo $sort === 'stock' ? 'selected' : ''; ?>>Mayor disponibilidad</option>
                            <option value="discount" <?php echo $sort === 'discount' ? 'selected' : ''; ?>>Con descuento primero</option>
                            <option value="questions" <?php echo $sort === 'questions' ? 'selected' : ''; ?>>Mas consultados</option>
                        </select>
                    </label>
                    <button type="submit">Aplicar filtros</button>
                </form>
            </aside>
            <div class="catalog-main">
                <div class="result-toolbar">
                    <span>Pagina <?php echo (int) $pagination['page']; ?> de <?php echo (int) $pagination['pages']; ?> - <?php echo (int) $pagination['total']; ?> productos</span>
                    <div><a href="/buscar.php?c=<?php echo e(rawurlencode($category)); ?>">Buscar dentro de esta categoria</a><a href="<?php echo e($buildCategoryUrl(['sort' => 'price_asc'])); ?>">Menor precio</a><a href="<?php echo e($buildCategoryUrl(['sort' => 'price_desc'])); ?>">Mayor precio</a><a href="<?php echo e($buildCategoryUrl(['sort' => 'rating'])); ?>">Mejor calificados</a><a href="<?php echo e($buildCategoryUrl(['sort' => 'discount'])); ?>">Ofertas</a></div>
                </div>
                <?php if (!$results): ?><div class="empty-cart">No encontramos productos con esos filtros.</div><?php else: ?>
                    <section class="grid">
                        <?php foreach ($pageProducts as $product): render_store_product_card($product, $favoriteIds); endforeach; ?>
                    </section>
                    <?php render_pagination($pagination, $pageParams); ?>
                <?php endif; ?>
                <?php if ($discountProducts): ?>
                    <section class="category-extra-section">
                        <div class="section-title"><h2>Ofertas destacadas</h2><p>Promociones configuradas para esta categoria.</p></div>
                        <div class="product-rail"><?php foreach (array_slice($discountProducts, 0, 8) as $product): render_store_product_card($product, $favoriteIds); endforeach; ?></div>
                    </section>
                <?php endif; ?>
                <?php if ($ratedProducts): ?>
                    <section class="category-extra-section">
                        <div class="section-title"><h2>Tambien puede interesarte</h2><p>Productos relacionados con <?php echo e($category); ?>.</p></div>
                        <div class="product-rail"><?php foreach (array_slice($ratedProducts, 0, 8) as $product): render_store_product_card($product, $favoriteIds); endforeach; ?></div>
                    </section>
                <?php endif; ?>
                <section class="category-faq">
                    <h2>Preguntas frecuentes de <?php echo e($category); ?></h2>
                    <details open><summary>Como confirmo disponibilidad?</summary><p>Agrega el producto al carrito o consulta por WhatsApp. Station X valida stock real antes de cerrar la compra.</p></details>
                    <details><summary>Puedo retirar en tienda?</summary><p>Si. Puedes coordinar retiro en Tulua o solicitar domicilio local y envio nacional a confirmar.</p></details>
                    <details><summary>Los productos vienen con garantia?</summary><p>La garantia depende del tipo de producto y estado. En cada compra se confirma la cobertura antes de pagar.</p></details>
                </section>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php store_footer(); ?>
</body>
</html>
