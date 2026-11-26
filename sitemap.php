<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$products = fetch_store_products($pdo, $prefix, $entity, 5000);
$categories = fetch_store_categories($pdo, $prefix, $entity);

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://stationxtulua.com/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>
    <url><loc>https://stationxtulua.com/categoria.php</loc><changefreq>daily</changefreq><priority>0.8</priority></url>
    <url><loc>https://stationxtulua.com/recientes.php</loc><changefreq>daily</changefreq><priority>0.7</priority></url>
    <?php foreach ($categories as $category): ?>
        <url><loc>https://stationxtulua.com/categoria.php?c=<?php echo e(rawurlencode(display_name((string) $category['label']))); ?></loc><changefreq>daily</changefreq><priority>0.7</priority></url>
    <?php endforeach; ?>
    <?php foreach ($products as $product): ?>
        <url><loc>https://stationxtulua.com<?php echo e(product_url($product)); ?></loc><changefreq>weekly</changefreq><priority>0.8</priority></url>
    <?php endforeach; ?>
</urlset>
