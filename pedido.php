<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
if (!$currentUser) {
    $_SESSION['sx_flash'] = 'Inicia sesion para ver el pedido.';
    header('Location: /cuenta.php');
    exit;
}

$orderId = max(0, (int) ($_GET['id'] ?? $_POST['order_id'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $_SESSION['sx_flash'] = 'La sesion expiro.';
        header('Location: /pedidos.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'cancel_order') {
        $guard = $pdo->prepare('SELECT status, payment_status, dolibarr_payment_id, stock_discounted_at FROM sx_store_orders WHERE id = :id AND user_id = :user_id');
        $guard->execute(['id' => $orderId, 'user_id' => (int) $currentUser['id']]);
        $guardOrder = $guard->fetch();
        if (!$guardOrder || ($guardOrder['payment_status'] ?? '') === 'pagado' || !empty($guardOrder['dolibarr_payment_id']) || !empty($guardOrder['stock_discounted_at'])) {
            $_SESSION['sx_flash'] = 'Este pedido ya tiene movimientos aplicados. Contacta a Station X para revisar la cancelacion.';
            header('Location: /pedido.php?id=' . $orderId);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE sx_store_orders SET status = 'cancelado', dolibarr_sync_note = 'Cancelado por cliente antes de pago/inventario' WHERE id = :id AND user_id = :user_id AND status IN ('nuevo', 'pendiente')");
        $stmt->execute(['id' => $orderId, 'user_id' => (int) $currentUser['id']]);
        $_SESSION['sx_flash'] = $stmt->rowCount() > 0 ? 'Pedido cancelado.' : 'El pedido ya no se puede cancelar desde la web.';
        header('Location: /pedido.php?id=' . $orderId);
        exit;
    }
}

$order = fetch_order_detail($pdo, $currentUser, $orderId);
$evidenceFiles = $order ? fetch_order_evidence($pdo, (int) $order['id'], (string) ($order['payment_evidence_file'] ?? '')) : [];
$cart = load_cart($pdo, $currentUser);
$cartItems = fetch_cart_items($pdo, $prefix, $cart);
$cartCount = array_sum($cart);
$flash = $_SESSION['sx_flash'] ?? null;
unset($_SESSION['sx_flash']);
$steps = ['nuevo' => 'Creado', 'confirmado' => 'Confirmado', 'preparando' => 'Preparando', 'enviado' => 'Enviado', 'entregado' => 'Entregado'];
$currentStep = $order ? ($order['shipping_status'] === 'entregado' ? 'entregado' : ($order['shipping_status'] === 'enviado' ? 'enviado' : ($order['shipping_status'] === 'preparando' ? 'preparando' : $order['status']))) : 'nuevo';
$stepDates = $order ? [
    'nuevo' => (string) ($order['created_at'] ?? ''),
    'confirmado' => (string) ($order['confirmed_at'] ?? ''),
    'preparando' => (string) ($order['preparing_at'] ?? ''),
    'enviado' => (string) ($order['shipped_at'] ?? ''),
    'entregado' => (string) ($order['delivered_at'] ?? ''),
] : [];
$paymentMethod = $order ? (string) ($order['payment_method'] ?? '') : '';
$paymentDetails = [
    'transferencia' => [
        'title' => 'Transferencia bancaria',
        'copy' => 'Usa estos datos para pagar y conserva el comprobante.',
        'image' => '/assets/uploads/payment-methods/station-valeria-payment.jpeg',
        'lines' => [
            ['Banco', 'Bancolombia / Bre-B'],
            ['Nombre', 'Station Valeria'],
            ['Llave Bre-B', '@stationvaleria'],
            ['Numero', '87400064111'],
        ],
    ],
    'nequi' => [
        'title' => 'Nequi / Daviplata',
        'copy' => 'Usa estos datos para pago movil y conserva el comprobante.',
        'image' => '/assets/uploads/payment-methods/station-valeria-payment.jpeg',
        'lines' => [
            ['Nombre', 'Station Valeria'],
            ['Llave Bre-B', '@stationvaleria'],
            ['Nequi', '0092193762'],
            ['Referencia', (string) ($order['order_code'] ?? 'Tu pedido')],
        ],
    ],
    'datafono' => [
        'title' => 'Pago con tarjeta en tienda',
        'copy' => 'El pago se confirma al retirar o comprar presencialmente.',
        'lines' => [
            ['Lugar', 'Station X Tulua'],
            ['Estado', 'Pendiente hasta pago en tienda'],
        ],
    ],
];
$selectedPaymentDetail = $paymentDetails[$paymentMethod] ?? null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle de pedido | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Pedido', $cartCount, $cartItems); ?>
<?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
<main class="page-shell">
    <?php if (!$order): ?>
        <section class="notice"><strong>Pedido no encontrado.</strong><span>Revisa tu historial de pedidos.</span></section>
    <?php else: ?>
        <section class="order-hero">
            <div>
                <span>Pedido creado <?php echo e(date('d/m/Y H:i', strtotime((string) $order['created_at']))); ?></span>
                <h1><?php echo e($order['order_code'] ?: '#' . $order['id']); ?></h1>
                <p>Total <?php echo e(money_value($order['total'])); ?> - Pago <?php echo e($order['payment_status']); ?> - Entrega <?php echo e($order['shipping_status']); ?></p>
            </div>
            <strong><?php echo e(display_name((string) $order['status'])); ?></strong>
        </section>
        <section class="order-summary-cards">
            <article><span>Entrega</span><strong><?php echo e(display_name((string) $order['delivery_method'])); ?></strong><small><?php echo e($order['address'] ?: 'Retiro en tienda'); ?></small></article>
            <article><span>Pago</span><strong><?php echo e(display_name((string) $order['payment_method'])); ?></strong><small><?php echo e(display_name((string) $order['payment_status'])); ?></small></article>
            <article>
                <span>Evidencia</span>
                <strong><?php echo e(display_name((string) ($order['payment_evidence_status'] ?? 'por_confirmar'))); ?></strong>
                <small><?php echo e($order['payment_evidence_note'] ?: 'Por confirmar'); ?></small>
                <?php if ($evidenceFiles): ?><a class="order-card-link" href="#evidencias-pago">Ver <?php echo count($evidenceFiles); ?> evidencia(s)</a><?php endif; ?>
            </article>
            <article><span>Factura ERP</span><strong><?php echo e($order['dolibarr_invoice_ref'] ?: 'Borrador pendiente'); ?></strong><small>Debe validarse en Dolibarr antes de aprobar estado.</small></article>
            <article><span>Contacto</span><strong><?php echo e($order['customer_name']); ?></strong><small><?php echo e($order['customer_phone']); ?></small></article>
        </section>
        <section class="order-status-grid">
            <article><span>Estado del pedido</span><strong><?php echo e(display_name((string) $order['status'])); ?></strong></article>
            <article><span>Estado de pago</span><strong><?php echo e(display_name((string) $order['payment_status'])); ?></strong></article>
            <article><span>Estado de entrega</span><strong><?php echo e(display_name((string) $order['shipping_status'])); ?></strong></article>
            <article><span>Estado evidencia</span><strong><?php echo e(display_name((string) ($order['payment_evidence_status'] ?? 'por_confirmar'))); ?></strong></article>
        </section>
        <?php if ($selectedPaymentDetail): ?>
            <section class="checkout-payment-info order-payment-info">
                <?php if (!empty($selectedPaymentDetail['image'])): ?>
                    <img class="payment-method-image" src="<?php echo e((string) $selectedPaymentDetail['image']); ?>" alt="Datos de pago Station Valeria">
                <?php endif; ?>
                <div>
                    <span>Datos de pago</span>
                    <strong><?php echo e($selectedPaymentDetail['title']); ?></strong>
                    <p><?php echo e($selectedPaymentDetail['copy']); ?></p>
                </div>
                <div class="payment-data-grid">
                    <?php foreach ($selectedPaymentDetail['lines'] as $line): ?>
                        <div><span><?php echo e($line[0]); ?></span><strong><?php echo e($line[1]); ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <section class="order-detail-layout">
            <div class="checkout">
                <h2>Seguimiento</h2>
                <ol class="timeline">
                    <?php $active = true; foreach ($steps as $key => $label): ?>
                        <?php if ($key === $currentStep) { $active = false; } ?>
                        <?php $stepDate = trim((string) ($stepDates[$key] ?? '')); ?>
                        <li class="<?php echo ($active || $key === $currentStep) ? 'done' : ''; ?>"><strong><?php echo e($label); ?></strong><small><?php echo $stepDate !== '' ? e(date('d/m/Y H:i', strtotime($stepDate))) : 'Por confirmar'; ?></small></li>
                    <?php endforeach; ?>
                </ol>
                <section class="store-message-box">
                    <h2>Mensajes de la tienda</h2>
                    <?php if (!empty($order['store_public_note'])): ?>
                        <p><?php echo nl2br(e((string) $order['store_public_note'])); ?></p>
                    <?php else: ?>
                        <p>Cuando Station X confirme tu compra o necesite enviarte una nota, aparecera aqui.</p>
                    <?php endif; ?>
                    <?php if (!empty($order['tracking_url'])): ?><a href="<?php echo e((string) $order['tracking_url']); ?>" target="_blank" rel="noopener">Ver seguimiento</a><?php endif; ?>
                </section>
                <?php if ($evidenceFiles): ?>
                    <section class="store-message-box" id="evidencias-pago">
                        <h2>Evidencias de pago</h2>
                        <div class="order-evidence-gallery">
                            <?php foreach ($evidenceFiles as $evidence): ?>
                                <a href="<?php echo e((string) $evidence['file_path']); ?>" target="_blank" rel="noopener">
                                    <img src="<?php echo e((string) $evidence['file_path']); ?>" alt="Evidencia de pago">
                                    <span><?php echo e(display_name((string) ($evidence['uploaded_by'] ?? 'web'))); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <h2>Productos</h2>
                <div class="orders-list">
                    <?php foreach ($order['items'] as $item): ?>
                        <?php $image = product_image_url((string) $item['product_ref'], 180); $productLink = '/producto/' . (int) $item['product_id'] . '-' . product_slug((string) $item['product_name']); ?>
                        <article class="order-product-line">
                            <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($item['product_name']); ?>"><?php endif; ?>
                            <strong><a href="<?php echo e($productLink); ?>"><?php echo e($item['product_name']); ?></a></strong>
                            <span><?php echo (int) $item['quantity']; ?> x <?php echo e(money_value($item['price'])); ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="buy-box">
                <strong>Datos del pedido</strong>
                <span><?php echo e($order['customer_name']); ?></span>
                <span><?php echo e($order['customer_phone']); ?></span>
                <span><?php echo e($order['address'] ?: 'Retiro en tienda'); ?></span>
                <?php if (in_array($order['status'], ['nuevo', 'pendiente'], true)): ?>
                    <form method="post" onsubmit="return confirm('Confirmas que quieres cancelar este pedido?');"><?php echo csrf_input(); ?><input type="hidden" name="action" value="cancel_order"><input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>"><button class="secondary-buy" type="submit">Cancelar pedido</button></form>
                <?php endif; ?>
            </aside>
        </section>
    <?php endif; ?>
</main>
<?php store_footer(); ?>
</body>
</html>
