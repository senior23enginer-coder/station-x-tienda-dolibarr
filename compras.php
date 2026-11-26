<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
if (!$currentUser) {
    $_SESSION['sx_flash'] = 'Inicia sesion para ver tus compras.';
    header('Location: /cuenta.php');
    exit;
}
$cart = load_cart($pdo, $currentUser);
$cartItems = fetch_cart_items($pdo, $prefix, $cart);
$cartCount = array_sum($cart);
$orders = fetch_user_orders_full($pdo, $currentUser);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis compras | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Mis compras', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="meli-list-header">
        <div>
            <h1>Compras</h1>
            <form class="meli-history-search" action="/compras.php"><input type="search" placeholder="Busca por compra, marca o producto"></form>
        </div>
        <span><?php echo count($orders); ?> compras</span>
    </section>
    <section class="review-callout"><strong>Productos esperan tu opinion</strong><a href="/compras.php">Opinar</a></section>
    <?php if (!$orders): ?><div class="empty-cart visual-empty"><strong>Todavia no tienes compras registradas.</strong><span>Cuando crees un pedido aparecera aqui.</span><a href="/#catalogo">Comprar ahora</a></div><?php endif; ?>
    <section class="purchase-timeline">
        <?php foreach ($orders as $order): ?>
            <article class="purchase-card">
                <header><?php echo e(date('d/m/Y', strtotime((string) $order['created_at']))); ?></header>
                <div>
                    <strong><?php echo e($order['shipping_status'] === 'entregado' ? 'Entregado' : 'Pedido registrado'); ?></strong>
                    <h2><?php echo e($order['order_code'] ?: '#' . $order['id']); ?></h2>
                    <p>Compra <?php echo e($order['status']); ?> &middot; Pago <?php echo e($order['payment_status']); ?> &middot; Entrega <?php echo e($order['shipping_status']); ?></p>
                    <span><?php echo e(money_value($order['total'])); ?></span>
                </div>
                <aside><a class="button primary" href="/pedido.php?id=<?php echo (int) $order['id']; ?>">Ver compra</a><a class="button ghost" href="/#catalogo">Volver a comprar</a></aside>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
