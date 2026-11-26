<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
if (!$currentUser) {
    $_SESSION['sx_flash'] = 'Inicia sesion para ver pedidos.';
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
    <title>Mis pedidos | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Mis pedidos', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="meli-list-header"><div><h1>Mis pedidos</h1><p>Seguimiento de estado, pago y entrega.</p></div><span><?php echo count($orders); ?> pedidos</span></section>
    <?php if (!$orders): ?><div class="empty-cart visual-empty"><strong>Todavia no tienes pedidos.</strong><span>Agrega productos al carrito y crea tu primer pedido.</span><a href="/#catalogo">Ver productos</a></div><?php endif; ?>
    <section class="purchase-timeline">
        <?php foreach ($orders as $order): ?>
            <article class="purchase-card">
                <header><?php echo e(date('d/m/Y', strtotime((string) $order['created_at']))); ?></header>
                <div>
                    <strong><?php echo e(display_name((string) $order['status'])); ?></strong>
                    <h2><?php echo e($order['order_code'] ?: '#' . $order['id']); ?></h2>
                    <p>Pago <?php echo e(display_name((string) $order['payment_status'])); ?> &middot; Evidencia <?php echo e(display_name((string) ($order['payment_evidence_status'] ?? 'por_confirmar'))); ?> &middot; Entrega <?php echo e(display_name((string) $order['shipping_status'])); ?><?php if (!empty($order['dolibarr_invoice_ref'])): ?> &middot; Factura <?php echo e((string) $order['dolibarr_invoice_ref']); ?><?php endif; ?></p>
                    <span><?php echo e(money_value($order['total'])); ?></span>
                </div>
                <aside><a class="button primary" href="/pedido.php?id=<?php echo (int) $order['id']; ?>">Ver pedido</a><a class="button ghost" href="/ayuda.php">Necesito ayuda</a></aside>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
