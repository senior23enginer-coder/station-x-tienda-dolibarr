<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
$flash = $_SESSION['sx_flash'] ?? null;
unset($_SESSION['sx_flash']);
ensure_store_experience_tables($pdo);
$shippingRates = fetch_shipping_rates($pdo);
$paymentMethods = fetch_payment_methods($pdo);

function redirect_cart(string $message, string $target = '/carrito.php'): void
{
    $_SESSION['sx_flash'] = $message;
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        redirect_cart('La sesion expiro. Intenta de nuevo.');
    }
    if (($_POST['action'] ?? '') === 'coupon') {
        $_SESSION['sx_coupon'] = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
        redirect_cart($_SESSION['sx_coupon'] !== '' ? 'Cupon aplicado si esta activo.' : 'Cupon retirado.');
    }
    if (($_POST['action'] ?? '') === 'update_cart') {
        $cart = [];
        foreach (($_POST['qty'] ?? []) as $productId => $quantity) {
            $qty = max(0, (int) $quantity);
            if ($qty > 0) {
                $cart[(int) $productId] = $qty;
            }
        }
        if ($currentUser) {
            save_user_cart($pdo, (int) $currentUser['id'], $cart);
        } else {
            $_SESSION['sx_cart'] = $cart;
        }
        redirect_cart('Carrito actualizado.');
    }
    if (($_POST['action'] ?? '') === 'remove_item') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $cart = load_cart($pdo, $currentUser);
        unset($cart[$productId]);
        if ($currentUser) {
            save_user_cart($pdo, (int) $currentUser['id'], $cart);
        } else {
            $_SESSION['sx_cart'] = $cart;
        }
        redirect_cart('Producto quitado del carrito.');
    }
    if (($_POST['action'] ?? '') === 'checkout') {
        if (!$currentUser) {
            redirect_cart('Inicia sesion o registrate para crear el pedido. Tu carrito queda guardado.', '/cuenta.php');
        }
        $cart = load_cart($pdo, $currentUser);
        $items = fetch_cart_items($pdo, $prefix, $cart);
        if (!$items) {
            redirect_cart('Agrega productos antes de finalizar.');
        }
        $unavailable = unavailable_cart_items($items);
        if ($unavailable) {
            redirect_cart('Ajusta cantidades: ' . display_name((string) $unavailable[0]['label']) . ' tiene stock disponible de ' . (int) $unavailable[0]['stock'] . '.');
        }
        $name = trim((string) ($currentUser['name'] ?? ''));
        $phone = trim((string) ($currentUser['phone'] ?? ''));
        $email = trim((string) ($currentUser['email'] ?? ''));
        if ($name === '' || $phone === '') {
            redirect_cart('Completa nombre y telefono/WhatsApp en tu cuenta antes de crear el pedido.', '/perfil.php');
        }
        $subtotal = array_sum(array_map(static fn($item) => (float) $item['unit_price'] * (int) $item['quantity'], $items));
        $couponCode = strtoupper(trim((string) ($_SESSION['sx_coupon'] ?? '')));
        $discount = 0.0;
        if ($couponCode !== '') {
            $couponStmt = $pdo->prepare('SELECT * FROM sx_store_coupons WHERE code = :code AND enabled = 1');
            $couponStmt->execute(['code' => $couponCode]);
            $coupon = $couponStmt->fetch();
            if ($coupon) {
                $discount = $coupon['discount_type'] === 'percent' ? $subtotal * ((float) $coupon['discount_value'] / 100) : (float) $coupon['discount_value'];
                $discount = min($subtotal, max(0, $discount));
            }
        }
        $deliveryCode = (string) ($_POST['delivery_method'] ?? '');
        $paymentCode = (string) ($_POST['payment_method'] ?? '');
        $delivery = find_store_option($shippingRates, $deliveryCode);
        $payment = find_store_option($paymentMethods, $paymentCode);
        if (!$delivery || !$payment) {
            redirect_cart('Selecciona un metodo de entrega y pago disponible.');
        }
        $shippingAmount = (float) ($delivery['price'] ?? 0);
        $total = max(0, $subtotal - $discount + $shippingAmount);
        $evidenceStatus = (string) ($_POST['payment_evidence_status'] ?? 'enviada_whatsapp');
        if (!in_array($evidenceStatus, ['enviada_whatsapp', 'enviada_web'], true)) {
            $evidenceStatus = 'enviada_whatsapp';
        }
        $evidenceNote = trim((string) ($_POST['payment_evidence_note'] ?? ''));
        $address = $deliveryCode === 'retiro' ? '' : trim((string) ($_POST['address'] ?? ''));
        if ($deliveryCode !== 'retiro' && $currentUser && (int) ($_POST['address_id'] ?? 0) > 0) {
            $addressStmt = $pdo->prepare('SELECT address, city FROM sx_store_addresses WHERE id = :id AND user_id = :user_id');
            $addressStmt->execute(['id' => (int) $_POST['address_id'], 'user_id' => (int) $currentUser['id']]);
            $savedAddress = $addressStmt->fetch();
            if ($savedAddress) {
                $address = $savedAddress['address'] . ', ' . $savedAddress['city'];
            }
        }
        $orderCode = 'SX-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        try {
            $evidenceFiles = save_payment_evidence_uploads('payment_evidence_file', $orderCode);
        } catch (RuntimeException $error) {
            redirect_cart($error->getMessage());
        }
        $evidenceFile = $evidenceFiles[0] ?? null;
        if ($evidenceStatus === 'enviada_web' && !$evidenceFile) {
            redirect_cart('Adjunta al menos una imagen de evidencia de pago o elige evidencia por WhatsApp.');
        }
        if ($evidenceFile) {
            $evidenceStatus = 'enviada_web';
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('
            INSERT INTO sx_store_orders (order_code, user_id, customer_name, customer_email, customer_phone, delivery_method, payment_method, address, notes, subtotal, discount_amount, shipping_amount, total, payment_evidence_status, payment_evidence_note, payment_evidence_file)
            VALUES (:code, :user_id, :name, :email, :phone, :delivery, :payment, :address, :notes, :subtotal, :discount, :shipping, :total, :evidence_status, :evidence_note, :evidence_file)
        ');
        $stmt->execute([
            'code' => $orderCode,
            'user_id' => $currentUser['id'] ?? null,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'delivery' => (string) $delivery['code'],
            'payment' => (string) $payment['code'],
            'address' => $address,
            'notes' => trim((string) ($_POST['notes'] ?? '')) . ($discount > 0 ? "\nCupon {$couponCode}: -" . money_value($discount) : '') . "\nEntrega: " . $delivery['label'] . ' (' . money_value($shippingAmount) . ')' . "\nPago: " . $payment['label'],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shippingAmount,
            'total' => $total,
            'evidence_status' => $evidenceStatus,
            'evidence_note' => $evidenceNote,
            'evidence_file' => $evidenceFile,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO sx_store_order_items (order_id, product_id, product_ref, product_name, price, quantity) VALUES (:order_id, :product_id, :ref, :name, :price, :quantity)');
        foreach ($items as $item) {
            $itemStmt->execute(['order_id' => $orderId, 'product_id' => (int) $item['rowid'], 'ref' => (string) $item['ref'], 'name' => display_name((string) $item['label']), 'price' => (float) $item['unit_price'], 'quantity' => (int) $item['quantity']]);
        }
        insert_order_evidence($pdo, $orderId, $evidenceFiles, 'customer', $evidenceNote);
        create_dolibarr_draft_invoice($pdo, $prefix, $entity, $orderId, $orderCode, [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
        ], $items, $subtotal, $discount, $shippingAmount, $total);
        $pdo->commit();
        if ($currentUser) {
            save_user_cart($pdo, (int) $currentUser['id'], []);
        } else {
            $_SESSION['sx_cart'] = [];
        }
        unset($_SESSION['sx_coupon']);
        redirect_cart('Pedido ' . $orderCode . ' creado. Te contactaremos para confirmar.');
    }
}

$cart = load_cart($pdo, $currentUser);
$items = fetch_cart_items($pdo, $prefix, $cart);
$cartCount = array_sum($cart);
$subtotal = array_sum(array_map(static fn($item) => (float) $item['unit_price'] * (int) $item['quantity'], $items));
$couponCode = strtoupper(trim((string) ($_SESSION['sx_coupon'] ?? '')));
$discount = 0.0;
if ($couponCode !== '') {
    try {
        ensure_store_experience_tables($pdo);
        $stmt = $pdo->prepare('SELECT * FROM sx_store_coupons WHERE code = :code AND enabled = 1');
        $stmt->execute(['code' => $couponCode]);
        $coupon = $stmt->fetch();
        if ($coupon) {
            $discount = $coupon['discount_type'] === 'percent' ? $subtotal * ((float) $coupon['discount_value'] / 100) : (float) $coupon['discount_value'];
            $discount = min($subtotal, max(0, $discount));
        }
    } catch (Throwable $ignored) {
    }
}
$defaultShipping = find_store_option($shippingRates, 'retiro') ?? ($shippingRates[0] ?? ['price' => 0]);
$shippingAmount = (float) ($defaultShipping['price'] ?? 0);
$payableTotal = max(0, $subtotal - $discount + $shippingAmount);
$unavailable = unavailable_cart_items($items);
$addresses = $currentUser ? fetch_user_addresses($pdo, $currentUser) : [];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Carrito | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Carrito', $cartCount, $items); ?>
<?php if ($flash): ?><div class="flash <?php echo strpos($flash, 'Pedido ') === 0 ? 'order-created-flash' : ''; ?>"><?php echo e($flash); ?><?php if (strpos($flash, 'Pedido ') === 0): ?><a href="/pedidos.php">Ver mis pedidos</a><?php endif; ?></div><?php endif; ?>
<main class="page-shell">
    <section class="page-hero"><h1>Carrito</h1><p>Revisa cantidades y finaliza tu pedido.</p></section>
    <section class="account-checkout">
        <div class="cart-panel">
            <h2>Productos</h2>
            <?php if (!$items): ?>
                <div class="empty-cart">Tu carrito esta vacio. <a href="/#catalogo">Ver productos</a></div>
            <?php else: ?>
                <form method="post" class="cart-list">
                    <?php echo csrf_input(); ?><input type="hidden" name="action" value="update_cart">
                    <?php foreach ($items as $item): ?>
                        <?php $image = product_image_url((string) $item['ref'], 150); ?>
                        <div class="cart-line pro-cart-line">
                            <a class="cart-line-image" href="<?php echo e(product_url($item)); ?>">
                                <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e(display_name((string) $item['label'])); ?>"><?php else: ?><span>SX</span><?php endif; ?>
                            </a>
                            <div>
                                <strong><a href="<?php echo e(product_url($item)); ?>"><?php echo e(display_name((string) $item['label'])); ?></a></strong>
                                <small><?php echo e(display_name((string) $item['ref'])); ?></small>
                            </div>
                            <span><?php echo e(money_value($item['unit_price'])); ?></span>
                            <input type="number" min="0" name="qty[<?php echo (int) $item['rowid']; ?>]" value="<?php echo (int) $item['quantity']; ?>" aria-label="Cantidad">
                            <button class="cart-remove-button" type="submit" name="remove_item" value="<?php echo (int) $item['rowid']; ?>" onclick="this.form.querySelector('[name=action]').value='remove_item'; this.form.querySelector('[name=product_id]').value=this.value;">Quitar</button>
                        </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="product_id" value="">
                    <button type="submit">Actualizar carrito</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="checkout">
            <h2>Resumen</h2>
            <div class="summary-lines">
                <div><span>Productos</span><strong><?php echo e(money_value($subtotal)); ?></strong></div>
                <div><span>Descuento<?php echo $couponCode !== '' ? ' ' . e($couponCode) : ''; ?></span><strong>-<?php echo e(money_value($discount)); ?></strong></div>
                <div><span>Envio</span><strong data-shipping-estimate><?php echo e(money_value($shippingAmount)); ?></strong></div>
                <div class="summary-total"><span>Total</span><strong data-payable-total data-base-total="<?php echo e((string) max(0, $subtotal - $discount)); ?>"><?php echo e(money_value($payableTotal)); ?></strong></div>
            </div>
            <form method="post" class="coupon-form">
                <?php echo csrf_input(); ?><input type="hidden" name="action" value="coupon">
                <input name="coupon" placeholder="Cupon promocional" value="<?php echo e($couponCode); ?>">
                <button type="submit">Aplicar</button>
            </form>
            <?php if (!$currentUser): ?>
                <div class="checkout-auth-cta">
                    <div>
                        <strong>Para finalizar tu pedido</strong>
                        <span>Inicia sesion o crea tu cuenta. Tu carrito queda guardado mientras continuas.</span>
                    </div>
                    <div class="checkout-auth-actions">
                        <a class="checkout-auth-login" href="/cuenta.php#login">Iniciar sesion</a>
                        <a class="checkout-auth-register" href="/cuenta.php#registro">Crear cuenta</a>
                    </div>
                </div>
            <?php else: ?>
            <form method="post" class="checkout-form" enctype="multipart/form-data">
                <?php echo csrf_input(); ?><input type="hidden" name="action" value="checkout">
                <div class="checkout-account-summary">
                    <span>Cliente</span>
                    <strong><?php echo e((string) ($currentUser['name'] ?? '')); ?></strong>
                    <small><?php echo e((string) ($currentUser['email'] ?? '')); ?><?php echo !empty($currentUser['phone']) ? ' &middot; ' . e((string) $currentUser['phone']) : ''; ?></small>
                    <a href="/perfil.php">Editar datos de cuenta</a>
                </div>
                <select name="delivery_method" data-checkout-delivery>
                    <?php foreach ($shippingRates as $rate): ?><option value="<?php echo e((string) $rate['code']); ?>" data-price="<?php echo e((string) $rate['price']); ?>"><?php echo e($rate['label'] . ' - ' . money_value($rate['price'])); ?></option><?php endforeach; ?>
                </select>
                <select name="payment_method" data-checkout-payment>
                    <?php foreach ($paymentMethods as $method): ?>
                        <?php $paymentCode = (string) $method['code']; $needsEvidence = in_array($paymentCode, ['transferencia', 'nequi'], true); ?>
                        <option value="<?php echo e($paymentCode); ?>" data-instructions="<?php echo e((string) ($method['instructions'] ?? '')); ?>" data-url="<?php echo e((string) ($method['payment_url'] ?? '')); ?>" data-requires-evidence="<?php echo $needsEvidence ? '1' : '0'; ?>"><?php echo e((string) $method['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="checkout-copy" data-payment-instructions></small>
                <section class="checkout-payment-info" data-payment-info hidden>
                    <img class="payment-method-image" src="/assets/uploads/payment-methods/station-valeria-payment.jpeg" alt="Datos de pago Station Valeria" data-payment-info-image hidden>
                    <div>
                        <span data-payment-info-kicker>Datos de pago</span>
                        <strong data-payment-info-title></strong>
                        <p data-payment-info-copy></p>
                    </div>
                    <div class="payment-data-grid" data-payment-info-lines></div>
                </section>
                <select class="checkout-wide-field" name="payment_evidence_status">
                    <option value="enviada_whatsapp">Evidencia por WhatsApp</option>
                    <option value="enviada_web">Subir evidencia de pago</option>
                </select>
                <?php if ($addresses): ?>
                    <select name="address_id" data-checkout-address-field>
                        <option value="">Usar direccion escrita manualmente</option>
                        <?php foreach ($addresses as $address): ?><option value="<?php echo (int) $address['id']; ?>"><?php echo e($address['label'] . ' - ' . $address['address']); ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <input type="text" name="address" placeholder="Direccion si aplica" data-checkout-address-field>
                <input class="checkout-wide-field" type="text" name="payment_evidence_note" placeholder="Referencia o nota de evidencia de pago">
                <label class="checkout-file-field" data-payment-evidence-field hidden>
                    <span>Adjuntar evidencias de pago</span>
                    <input type="file" name="payment_evidence_file[]" accept="image/*" multiple>
                    <small>Sube una o varias fotos/capturas del comprobante. En movil puedes elegir galeria o tomar foto. JPG, PNG o WEBP, maximo 5 MB por imagen.</small>
                </label>
                <textarea name="notes" placeholder="Notas del pedido"></textarea>
                <button type="submit" <?php echo (!$items || $unavailable) ? 'disabled' : ''; ?>>Crear pedido</button>
                <?php if ($unavailable): ?><span class="stock-warning">Ajusta stock: <?php echo e(display_name((string) $unavailable[0]['label'])); ?> tiene <?php echo (int) $unavailable[0]['stock']; ?> unidad(es).</span><?php endif; ?>
                <?php if (defined('SX_EXTERNAL_PAYMENT_URL') && SX_EXTERNAL_PAYMENT_URL !== ''): ?>
                    <a href="<?php echo e(SX_EXTERNAL_PAYMENT_URL); ?>" target="_blank" rel="noopener">Pagar en pasarela segura</a>
                <?php endif; ?>
                <a href="https://wa.me/573155912459?text=<?php echo e($items ? cart_whatsapp_text($items, (float) $payableTotal) : rawurlencode('Hola Station X Tulua, quiero comprar.')); ?>" target="_blank" rel="noopener">Confirmar por WhatsApp</a>
            </form>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php store_footer(); ?>
<script>
const delivery = document.querySelector('[data-checkout-delivery]');
const estimate = document.querySelector('[data-shipping-estimate]');
const payable = document.querySelector('[data-payable-total]');
const payment = document.querySelector('[data-checkout-payment]');
const instructions = document.querySelector('[data-payment-instructions]');
const paymentInfo = document.querySelector('[data-payment-info]');
const paymentInfoImage = document.querySelector('[data-payment-info-image]');
const paymentInfoTitle = document.querySelector('[data-payment-info-title]');
const paymentInfoCopy = document.querySelector('[data-payment-info-copy]');
const paymentInfoLines = document.querySelector('[data-payment-info-lines]');
const evidenceStatus = document.querySelector('[name="payment_evidence_status"]');
const evidenceField = document.querySelector('[data-payment-evidence-field]');
const addressFields = document.querySelectorAll('[data-checkout-address-field]');
const money = (value) => '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(value);
const paymentDetails = {
    transferencia: {
        title: 'Transferencia bancaria',
        copy: 'Usa estos datos para transferir y luego envia o sube la evidencia.',
        image: '/assets/uploads/payment-methods/station-valeria-payment.jpeg',
        lines: [
            ['Banco', 'Bancolombia / Bre-B'],
            ['Nombre', 'Station Valeria'],
            ['Llave Bre-B', '@stationvaleria'],
            ['Numero', '87400064111']
        ]
    },
    nequi: {
        title: 'Nequi / Daviplata',
        copy: 'Realiza el pago movil y conserva la captura del comprobante.',
        image: '/assets/uploads/payment-methods/station-valeria-payment.jpeg',
        lines: [
            ['Nombre', 'Station Valeria'],
            ['Llave Bre-B', '@stationvaleria'],
            ['Nequi', '0092193762'],
            ['Referencia', 'Tu numero de pedido']
        ]
    },
    datafono: {
        title: 'Pago con tarjeta en tienda',
        copy: 'Disponible para retiro presencial. El pago se confirma en caja.',
        lines: [
            ['Lugar', 'Station X Tulua'],
            ['Estado inicial', 'Pendiente hasta pago en tienda']
        ]
    }
};
if (delivery && estimate && payable) {
    const syncDelivery = () => {
        const shipping = Number(delivery.selectedOptions[0]?.dataset.price || 0);
        const base = Number(payable.dataset.baseTotal || 0);
        estimate.textContent = money(shipping);
        payable.textContent = money(base + shipping);
        const isPickup = delivery.value === 'retiro';
        addressFields.forEach((field) => {
            field.hidden = isPickup;
            field.disabled = isPickup;
            if (isPickup) {
                field.value = '';
            }
        });
    };
    delivery.addEventListener('change', syncDelivery);
    syncDelivery();
}
if (payment && instructions) {
    const syncPayment = () => {
        const selected = payment.selectedOptions[0];
        const detail = paymentDetails[payment.value];
        instructions.textContent = selected?.dataset.instructions || '';
        if (paymentInfo && paymentInfoTitle && paymentInfoCopy && paymentInfoLines) {
            paymentInfo.hidden = !detail;
            if (paymentInfoImage) {
                paymentInfoImage.hidden = !detail?.image;
                paymentInfoImage.src = detail?.image || '';
            }
            paymentInfoTitle.textContent = detail?.title || '';
            paymentInfoCopy.textContent = detail?.copy || '';
            paymentInfoLines.innerHTML = '';
            (detail?.lines || []).forEach(([label, value]) => {
                const item = document.createElement('div');
                const itemLabel = document.createElement('span');
                const itemValue = document.createElement('strong');
                itemLabel.textContent = label;
                itemValue.textContent = value;
                item.append(itemLabel, itemValue);
                paymentInfoLines.append(item);
            });
        }
    };
    payment.addEventListener('change', syncPayment);
    syncPayment();
}
if (evidenceStatus && evidenceField) {
    const syncEvidence = () => {
        const shouldUpload = evidenceStatus.value === 'enviada_web';
        evidenceField.hidden = !shouldUpload;
        evidenceField.querySelectorAll('input').forEach((input) => {
            input.disabled = !shouldUpload;
        });
    };
    evidenceStatus.addEventListener('change', syncEvidence);
    syncEvidence();
}
</script>
<?php echo tracking_scripts(); ?>
</body>
</html>
