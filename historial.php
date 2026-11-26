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
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Historial', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="page-hero compact-page-hero">
        <h1>Tu historial</h1>
        <p>Productos vistos y busquedas recientes guardadas en este navegador.</p>
    </section>
    <section class="market-shelf recent-viewed-module history-page-module" data-recent-viewed>
        <div class="section-title">
            <h2>Productos vistos recientemente</h2>
            <a href="/buscar.php">Seguir comprando</a>
        </div>
        <div class="product-rail recent-viewed-track" data-recent-viewed-track></div>
        <div class="empty-cart" data-history-empty>Cuando visites productos apareceran aqui.</div>
    </section>
    <section class="market-shelf">
        <div class="section-title">
            <h2>Busquedas recientes</h2>
            <a href="/busquedas.php">Ver mis busquedas guardadas</a>
        </div>
        <div class="search-history-list" data-search-history></div>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
