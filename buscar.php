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

$query = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['c'] ?? ''));
$min = max(0, (float) ($_GET['min'] ?? 0));
$max = max(0, (float) ($_GET['max'] ?? 0));
$stockOnly = ($_GET['stock'] ?? '') === '1';
$pricedOnly = ($_GET['priced'] ?? '') === '1';
$questionsOnly = ($_GET['questions'] ?? '') === '1';
$imageOnly = ($_GET['image'] ?? '') === '1';
$reviewsOnly = ($_GET['reviews'] ?? '') === '1';
$availability = trim((string) ($_GET['availability'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$platform = trim((string) ($_GET['platform'] ?? ''));
$condition = trim((string) ($_GET['condition'] ?? ''));
$brand = trim((string) ($_GET['brand'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'recent');
$page = max(1, (int) ($_GET['page'] ?? 1));
$products = fetch_store_products($pdo, $prefix, $entity);
$erpCategories = fetch_store_categories($pdo, $prefix, $entity);
$categoryLabels = array_values(array_filter(array_map(static fn($item) => display_name((string) ($item['label'] ?? '')), $erpCategories)));
$categories = array_merge(['Todo'], $categoryLabels ?: ['Consolas', 'Videojuegos', 'Accesorios', 'Coleccionables', 'Gaming']);
$baseResults = filter_store_products($products, $query, $category, $min, $max, $stockOnly, $sort);
$typeFacets = facet_counts($baseResults, 'product_inferred_type');
$platformFacets = facet_counts($baseResults, 'product_inferred_platform');
$conditionFacets = facet_counts($baseResults, 'product_inferred_condition');
$brandFacets = facet_counts($baseResults, 'product_inferred_brand');
$results = apply_store_extended_filters($baseResults, [
    'priced' => $pricedOnly,
    'questions' => $questionsOnly,
    'reviews' => $reviewsOnly,
    'image' => $imageOnly,
    'availability' => $availability,
    'type' => $type,
    'platform' => $platform,
    'condition' => $condition,
    'brand' => $brand,
]);
$pagination = paginate_items($results, $page, 20);
$pageProducts = $pagination['items'];
save_store_search($pdo, $currentUser, $query, $category, count($results));
$pageParams = ['q' => $query, 'c' => $category, 'min' => $min > 0 ? (int) $min : '', 'max' => $max > 0 ? (int) $max : '', 'stock' => $stockOnly ? '1' : '', 'priced' => $pricedOnly ? '1' : '', 'questions' => $questionsOnly ? '1' : '', 'reviews' => $reviewsOnly ? '1' : '', 'image' => $imageOnly ? '1' : '', 'availability' => $availability, 'type' => $type, 'platform' => $platform, 'condition' => $condition, 'brand' => $brand, 'sort' => $sort];
$buildSearchUrl = static function (array $extra) use ($pageParams): string {
    $params = array_merge($pageParams, $extra);
    unset($params['page']);
    return '/buscar.php?' . http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null && $value !== false));
};
$activeFilters = [];
if ($query !== '') { $activeFilters[] = 'Busqueda: ' . $query; }
if ($category !== '') { $activeFilters[] = 'Categoria: ' . $category; }
if ($min > 0 || $max > 0) { $activeFilters[] = 'Precio: ' . ($min > 0 ? money_value($min) : '$0') . ' - ' . ($max > 0 ? money_value($max) : 'sin limite'); }
if ($stockOnly) { $activeFilters[] = 'Solo disponibles'; }
if ($pricedOnly) { $activeFilters[] = 'Con precio'; }
if ($questionsOnly) { $activeFilters[] = 'Con preguntas'; }
if ($reviewsOnly) { $activeFilters[] = 'Con resenas'; }
if ($imageOnly) { $activeFilters[] = 'Con imagen'; }
if ($availability !== '') { $activeFilters[] = 'Disponibilidad: ' . ($availability === 'available' ? 'Disponible' : 'Consultar'); }
if ($type !== '') { $activeFilters[] = 'Tipo: ' . filter_label('type', $type); }
if ($platform !== '') { $activeFilters[] = 'Plataforma: ' . filter_label('platform', $platform); }
if ($condition !== '') { $activeFilters[] = 'Condicion: ' . filter_label('condition', $condition); }
if ($brand !== '') { $activeFilters[] = 'Marca: ' . filter_label('brand', $brand); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buscar | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look listing-page">
<?php store_header('Buscar', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="page-hero compact-page-hero">
        <h1>Resultados de busqueda</h1>
        <p><?php echo count($results); ?> producto(s) encontrados<?php echo $query !== '' ? ' para "' . e($query) . '"' : ''; ?>. Mostrando <?php echo (int) $pagination['from']; ?>-<?php echo (int) $pagination['to']; ?>.</p>
    </section>
    <section class="search-control-panel">
        <div class="quick-filter-chips search-chips" aria-label="Filtros rapidos">
            <a class="<?php echo $max === 50000.0 ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['min' => '', 'max' => 50000])); ?>">Hasta $50.000</a>
            <a class="<?php echo $min === 50000.0 && $max === 150000.0 ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['min' => 50000, 'max' => 150000])); ?>">$50.000 a $150.000</a>
            <a class="<?php echo $min === 150000.0 ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['min' => 150000, 'max' => ''])); ?>">Mas de $150.000</a>
            <a class="<?php echo $availability === 'available' || $stockOnly ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['availability' => $availability === 'available' ? '' : 'available', 'stock' => ''])); ?>">Disponibles</a>
            <a class="<?php echo $availability === 'consult' ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['availability' => $availability === 'consult' ? '' : 'consult'])); ?>">A consultar</a>
            <a class="<?php echo $imageOnly ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['image' => $imageOnly ? '' : '1'])); ?>">Con imagen</a>
            <a class="<?php echo $pricedOnly ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['priced' => $pricedOnly ? '' : '1'])); ?>">Con precio</a>
        </div>
        <div class="category-chip-row" aria-label="Categorias">
            <?php foreach (array_slice($categories, 1) as $chip): ?><a class="<?php echo $category === $chip ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['c' => $chip])); ?>"><?php echo e($chip); ?></a><?php endforeach; ?>
        </div>
        <?php if ($activeFilters): ?>
            <div class="active-filter-row">
                <?php foreach ($activeFilters as $filter): ?><span><?php echo e($filter); ?></span><?php endforeach; ?>
                <a href="/buscar.php">Limpiar filtros</a>
            </div>
        <?php endif; ?>
    </section>
    <section class="catalog-layout">
        <button class="mobile-filter-toggle" type="button" data-filter-toggle>Filtros y ordenar</button>
        <aside class="catalog-filters" data-filter-panel>
            <div class="filter-heading"><strong>Filtros</strong><a href="/buscar.php">Limpiar</a></div>
            <form class="filter-form" method="get" action="/buscar.php">
                <label>Buscar<input type="search" name="q" value="<?php echo e($query); ?>" placeholder="Producto, referencia o descripcion"></label>
                <label>Categoria
                    <select name="c">
                        <option value="">Todas</option>
                        <?php foreach (array_slice($categories, 1) as $item): ?><option value="<?php echo e($item); ?>" <?php echo $category === $item ? 'selected' : ''; ?>><?php echo e($item); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Precio minimo<input type="number" min="0" name="min" value="<?php echo $min > 0 ? e((string) (int) $min) : ''; ?>"></label>
                <label>Precio maximo<input type="number" min="0" name="max" value="<?php echo $max > 0 ? e((string) (int) $max) : ''; ?>"></label>
                <label>Disponibilidad
                    <select name="availability">
                        <option value="">Todas</option>
                        <option value="available" <?php echo $availability === 'available' ? 'selected' : ''; ?>>Disponible en stock</option>
                        <option value="consult" <?php echo $availability === 'consult' ? 'selected' : ''; ?>>Consultar disponibilidad</option>
                    </select>
                </label>
                <div class="facet-block">
                    <h2>Tipo de producto</h2>
                    <?php foreach (array_slice($typeFacets, 0, 8, true) as $value => $count): ?><a class="<?php echo $type === $value ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['type' => $type === $value ? '' : $value])); ?>"><?php echo e(filter_label('type', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?>
                </div>
                <div class="facet-block">
                    <h2>Plataforma</h2>
                    <?php foreach (array_slice($platformFacets, 0, 8, true) as $value => $count): ?><a class="<?php echo $platform === $value ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['platform' => $platform === $value ? '' : $value])); ?>"><?php echo e(filter_label('platform', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?>
                </div>
                <div class="facet-block">
                    <h2>Condicion</h2>
                    <?php foreach ($conditionFacets as $value => $count): ?><a class="<?php echo $condition === $value ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['condition' => $condition === $value ? '' : $value])); ?>"><?php echo e(filter_label('condition', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?>
                </div>
                <?php if ($brandFacets): ?>
                    <div class="facet-block">
                        <h2>Marca / saga</h2>
                        <?php foreach (array_slice($brandFacets, 0, 10, true) as $value => $count): ?><a class="<?php echo $brand === $value ? 'active' : ''; ?>" href="<?php echo e($buildSearchUrl(['brand' => $brand === $value ? '' : $value])); ?>"><?php echo e(filter_label('brand', (string) $value)); ?> <span><?php echo (int) $count; ?></span></a><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <label class="check-filter"><input type="checkbox" name="priced" value="1" <?php echo $pricedOnly ? 'checked' : ''; ?>> Solo con precio</label>
                <label class="check-filter"><input type="checkbox" name="image" value="1" <?php echo $imageOnly ? 'checked' : ''; ?>> Solo con imagen</label>
                <label class="check-filter"><input type="checkbox" name="questions" value="1" <?php echo $questionsOnly ? 'checked' : ''; ?>> Con preguntas</label>
                <label class="check-filter"><input type="checkbox" name="reviews" value="1" <?php echo $reviewsOnly ? 'checked' : ''; ?>> Con resenas</label>
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
            <?php if (!$results): ?>
                <div class="empty-cart">No encontramos productos con esos filtros.</div>
            <?php else: ?>
                <div class="result-toolbar">
                    <span>Pagina <?php echo (int) $pagination['page']; ?> de <?php echo (int) $pagination['pages']; ?> &middot; <?php echo (int) $pagination['total']; ?> resultados</span>
                    <div>
                        <a href="<?php echo e($buildSearchUrl(['sort' => 'price_asc'])); ?>">Menor precio</a>
                        <a href="<?php echo e($buildSearchUrl(['sort' => 'price_desc'])); ?>">Mayor precio</a>
                        <a href="<?php echo e($buildSearchUrl(['sort' => 'rating'])); ?>">Mejor calificados</a>
                        <a href="<?php echo e($buildSearchUrl(['sort' => 'discount'])); ?>">Ofertas</a>
                        <a href="/recientes.php">Ver mas recientes</a>
                    </div>
                </div>
                <section class="grid">
                    <?php foreach ($pageProducts as $product): render_store_product_card($product, $favoriteIds); endforeach; ?>
                </section>
                <?php render_pagination($pagination, $pageParams); ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
