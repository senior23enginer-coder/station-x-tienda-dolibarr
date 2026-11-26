<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
ensure_store_experience_tables($pdo);
$currentUser = $_SESSION['sx_user'] ?? null;
$flash = $_SESSION['sx_flash'] ?? null;
unset($_SESSION['sx_flash']);

$productId = max(0, (int) ($_GET['id'] ?? $_POST['product_id'] ?? 0));

function redirect_product(string $message, int $productId, string $anchor = ''): void
{
    $_SESSION['sx_flash'] = $message;
    header('Location: /producto.php?id=' . $productId . $anchor);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        redirect_product('La sesion expiro. Intenta de nuevo.', $productId);
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_cart' && $productId > 0) {
        $candidate = fetch_product_detail($pdo, $prefix, $productId);
        if ($candidate && isset($candidate['stock']) && (float) $candidate['stock'] <= 0) {
            redirect_product('Este producto esta sin stock disponible. Consultanos por WhatsApp.', $productId);
        }
        if ($currentUser) {
            $cart = load_cart($pdo, $currentUser);
            $cart[$productId] = ($cart[$productId] ?? 0) + 1;
            save_user_cart($pdo, (int) $currentUser['id'], $cart);
        } else {
            add_session_cart($productId, 1);
        }
        header('Location: /carrito.php');
        exit;
    }

    if ($action === 'toggle_favorite' && $productId > 0) {
        if (!$currentUser) {
            $_SESSION['sx_flash'] = 'Inicia sesion para guardar favoritos.';
            header('Location: /cuenta.php');
            exit;
        }
        $stmt = $pdo->prepare('SELECT id FROM sx_store_favorites WHERE user_id = :user_id AND product_id = :product_id');
        $stmt->execute(['user_id' => (int) $currentUser['id'], 'product_id' => $productId]);
        if ($stmt->fetch()) {
            $pdo->prepare('DELETE FROM sx_store_favorites WHERE user_id = :user_id AND product_id = :product_id')->execute(['user_id' => (int) $currentUser['id'], 'product_id' => $productId]);
            redirect_product('Producto retirado de favoritos.', $productId);
        }
        $pdo->prepare('INSERT INTO sx_store_favorites (user_id, product_id) VALUES (:user_id, :product_id)')->execute(['user_id' => (int) $currentUser['id'], 'product_id' => $productId]);
        redirect_product('Producto guardado en favoritos.', $productId);
    }

    if ($action === 'ask_question' && $productId > 0) {
        $name = trim((string) ($_POST['customer_name'] ?? ($currentUser['name'] ?? 'Cliente')));
        $question = trim((string) ($_POST['question'] ?? ''));
        if ($question === '') {
            redirect_product('Escribe tu pregunta sobre el producto.', $productId, '#preguntas');
        }
        $stmt = $pdo->prepare('INSERT INTO sx_store_product_questions (product_id, user_id, customer_name, question) VALUES (:product_id, :user_id, :customer_name, :question)');
        $stmt->execute([
            'product_id' => $productId,
            'user_id' => $currentUser['id'] ?? null,
            'customer_name' => $name !== '' ? $name : 'Cliente',
            'question' => $question,
        ]);
        redirect_product('Pregunta enviada. Station X la respondera pronto.', $productId, '#preguntas');
    }

    if ($action === 'review_product' && $productId > 0) {
        if (!$currentUser) {
            $_SESSION['sx_flash'] = 'Inicia sesion para calificar productos.';
            header('Location: /cuenta.php');
            exit;
        }
        $rating = min(5, max(1, (int) ($_POST['rating'] ?? 5)));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $stmt = $pdo->prepare('
            INSERT INTO sx_store_product_reviews (product_id, user_id, rating, comment)
            VALUES (:product_id, :user_id, :rating, :comment)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), created_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            'product_id' => $productId,
            'user_id' => (int) $currentUser['id'],
            'rating' => $rating,
            'comment' => $comment,
        ]);
        redirect_product('Gracias por tu calificacion.', $productId, '#resenas');
    }
}

$product = $productId > 0 ? fetch_product_detail($pdo, $prefix, $productId) : null;
$cartCount = array_sum(load_cart($pdo, $currentUser));
$favoriteIds = fetch_favorite_ids($pdo, $currentUser);
if (!$product) {
    http_response_code(404);
}

$name = $product ? display_name((string) $product['label']) : 'Producto no encontrado';
$ref = $product ? display_name((string) $product['ref']) : '';
$price = $product ? product_effective_price($product) : null;
$basePrice = $product ? product_base_price($product) : null;
$discount = $product ? product_discount_percent($product) : 0;
$description = $product ? trim(strip_tags((string) ($product['description'] ?? ''))) : '';
$category = $product ? product_category($product) : 'Catalogo';
$images = $product ? product_image_urls((string) $product['ref'], 14, 1000) : [];
$shareImage = $product ? product_image_url((string) $product['ref'], 1200) : null;
$canonical = $product ? 'https://stationxtulua.com' . product_url($product) : 'https://stationxtulua.com/producto.php';
$questions = $product ? fetch_product_questions($pdo, (int) $product['rowid']) : [];
$reviews = $product ? fetch_product_reviews($pdo, (int) $product['rowid']) : [];
$stock = $product['stock'] ?? null;
$related = [];
if ($product) {
    $allProducts = fetch_store_products($pdo, $prefix, $entity, 240);
    foreach ($allProducts as $candidate) {
        if ((int) $candidate['rowid'] !== (int) $product['rowid'] && product_category($candidate) === $category) {
            $related[] = $candidate;
        }
        if (count($related) >= 12) {
            break;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($name); ?> | Station X Tulua</title>
    <meta name="description" content="<?php echo e($description !== '' ? display_name($description) : $name . ' disponible en Station X Tulua.'); ?>">
    <link rel="canonical" href="<?php echo e($canonical); ?>">
    <meta property="og:title" content="<?php echo e($name); ?>">
    <meta property="og:description" content="<?php echo e($description !== '' ? display_name($description) : 'Producto disponible en Station X Tulua.'); ?>">
    <meta property="og:type" content="product">
    <meta property="og:url" content="<?php echo e($canonical); ?>">
    <?php if ($shareImage): ?><meta property="og:image" content="https://stationxtulua.com<?php echo e($shareImage); ?>"><?php endif; ?>
    <?php if ($product): ?>
        <script type="application/ld+json"><?php echo json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'sku' => $ref,
            'category' => $category,
            'image' => array_map(static fn($image) => 'https://stationxtulua.com' . $image, $images),
            'description' => $description !== '' ? display_name($description) : $name . ' disponible en Station X Tulua.',
            'brand' => ['@type' => 'Brand', 'name' => 'Station X Tulua'],
            'offers' => [
                '@type' => 'Offer',
                'url' => $canonical,
                'priceCurrency' => 'COP',
                'price' => (float) $price,
                'availability' => ($stock !== null && (float) $stock > 0) ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
                'seller' => ['@type' => 'Organization', 'name' => 'Station X Tulua'],
            ],
            'aggregateRating' => count($reviews) ? ['@type' => 'AggregateRating', 'ratingValue' => number_format(array_sum(array_column($reviews, 'rating')) / max(1, count($reviews)), 1), 'reviewCount' => count($reviews)] : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header($name, $cartCount); ?>
<?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
<main class="page-shell">
    <nav class="breadcrumbs product-top-links" aria-label="Ruta">
        <a href="javascript:history.back()">Volver</a>
        <span>|</span>
        <a href="/">Inicio</a>
        <span>/</span>
        <a href="/categoria.php?c=<?php echo e(rawurlencode($category)); ?>"><?php echo e($category); ?></a>
        <span>/</span>
        <strong><?php echo e($name); ?></strong>
        <?php if ($product): ?>
            <span class="product-top-actions"><a href="/admin-store.php">Vender uno igual</a><span>|</span><a href="<?php echo e($canonical); ?>">Compartir</a></span>
        <?php endif; ?>
    </nav>

    <?php if (!$product): ?>
        <section class="notice"><strong>Producto no encontrado.</strong><span>Puede que ya no este publicado para venta.</span></section>
    <?php else: ?>
        <section class="product-ml-shell">
            <div class="product-ml-main">
                <aside class="product-gallery product-gallery-ml">
                    <?php if ($images): ?>
                        <div class="thumbs product-thumbs" aria-label="Imagenes del producto">
                            <?php foreach ($images as $index => $image): ?>
                                <button type="button" class="<?php echo $index === 0 ? 'is-active' : ''; ?>" data-thumb="<?php echo e($image); ?>"><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?> imagen <?php echo $index + 1; ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="product-main-image">
                        <?php if ($images): ?>
                            <button class="gallery-nav prev" type="button" data-product-prev aria-label="Imagen anterior">&lsaquo;</button>
                            <img src="<?php echo e($images[0]); ?>" alt="<?php echo e($name); ?>" data-zoom-image>
                            <button class="gallery-nav next" type="button" data-product-next aria-label="Imagen siguiente">&rsaquo;</button>
                        <?php else: ?>
                            <span><?php echo e(substr($name, 0, 2)); ?></span>
                        <?php endif; ?>
                    </div>
                </aside>

                <section class="product-main-info">
                    <a class="official-store-line" href="/sobre.php">Visita la tienda oficial de Station X Tulua</a>
                    <div class="condition-line">Nuevo | <?php echo max(1, count($reviews) + 5); ?> vendidos</div>
                    <div class="product-badges"><span>MAS VENDIDO</span><a href="/categoria.php?c=<?php echo e(rawurlencode($category)); ?>">Top en <?php echo e($category); ?></a></div>
                    <h1><?php echo e($name); ?></h1>
                    <div class="rating-line"><strong><?php echo $reviews ? number_format(array_sum(array_column($reviews, 'rating')) / max(1, count($reviews)), 1) : '5.0'; ?></strong><span class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</span><span>(<?php echo max(1, count($reviews)); ?>)</span></div>
                    <?php if ($discount > 0): ?><span class="old-price detail-old-price"><?php echo e(money_value($basePrice)); ?></span><?php endif; ?>
                    <strong class="detail-price"><?php echo e(money_value($price)); ?></strong>
                    <?php if ($discount > 0): ?><span class="discount-badge detail-discount"><?php echo $discount; ?>% OFF</span><?php endif; ?>
                    <p class="installments">12 cuotas de <?php echo e(money_value(((float) $price) / 12)); ?> con 0% interes</p>

                    <div class="variant-box">
                        <strong>Estado: <?php echo $stock === null ? 'Consultar' : ((float) $stock > 0 ? 'Disponible' : 'A confirmar'); ?></strong>
                        <div><button type="button" class="is-selected">Retiro en tienda</button><button type="button">Envio local</button><button type="button">Envio nacional</button></div>
                    </div>

                    <div class="know-card">
                        <h2>Lo que tienes que saber de este producto</h2>
                        <ul>
                            <li>Referencia <?php echo e($ref); ?>.</li>
                            <li>Categoria <?php echo e($category); ?> conectada al inventario actual.</li>
                            <li>Compra con confirmacion de disponibilidad por Station X Tulua.</li>
                        </ul>
                    </div>
                </section>
            </div>

            <aside class="product-side-rail">
                <div class="buy-box">
                    <strong>Llega gratis a coordinar</strong>
                    <span>Retiro en tienda, domicilio local y envio nacional a confirmar.</span>
                    <h3><?php echo ($stock !== null && (float) $stock > 0) ? 'Disponible' : 'Consulta disponibilidad'; ?></h3>
                    <form method="post">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="add_cart">
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>">
                        <button type="submit" <?php echo (isset($stock) && (float) $stock <= 0) ? 'disabled' : ''; ?>><?php echo (isset($stock) && (float) $stock <= 0) ? 'Consultar stock' : 'Agregar al carrito'; ?></button>
                    </form>
                    <form method="post">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="toggle_favorite">
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>">
                        <button type="submit" class="secondary-buy"><?php echo in_array((int) $product['rowid'], $favoriteIds, true) ? 'Quitar favorito' : 'Agregar a favoritos'; ?></button>
                    </form>
                    <a href="https://wa.me/573155912459?text=<?php echo e(rawurlencode('Hola Station X Tulua, quiero consultar: ' . $name)); ?>" target="_blank" rel="noopener">Consultar por WhatsApp</a>
                    <div class="safe-list"><span>Devolucion coordinada con la tienda.</span><span>Compra protegida y acompanamiento local.</span><span>Agregar a una lista.</span></div>
                </div>

                <div class="seller-card">
                    <div class="seller-banner"></div>
                    <div class="seller-head"><span>SX</span><div><strong>Station X Tulua</strong><small>Tienda gamer oficial</small></div></div>
                    <div class="seller-meter"><i></i><i></i><i></i><i></i><i></i></div>
                    <div class="seller-stats"><div><strong>+1000</strong><span>ventas</span></div><div><strong>Buena</strong><span>atencion</span></div><div><strong>Local</strong><span>entrega</span></div></div>
                    <a href="/sobre.php">Ir a la tienda oficial</a>
                </div>

                <div class="payment-card">
                    <h2>Medios de pago</h2>
                    <strong>Paga en tienda o contra entrega coordinada</strong>
                    <p>Tarjetas, transferencia, efectivo y Nequi/Daviplata segun confirmacion.</p>
                    <div class="pay-logos"><span>VISA</span><span>MC</span><span>PSE</span><span>Efectivo</span></div>
                </div>

                <?php if ($related): ?>
                    <div class="side-related">
                        <h2>Productos relacionados</h2>
                        <?php foreach (array_slice($related, 0, 4) as $item): ?>
                            <?php $relImage = product_image_url((string) $item['ref'], 160); $relPrice = $item['price_ttc'] !== null && $item['price_ttc'] !== '' ? $item['price_ttc'] : $item['price']; ?>
                            <a href="<?php echo e(product_url($item)); ?>">
                                <?php if ($relImage): ?><img src="<?php echo e($relImage); ?>" alt="<?php echo e(display_name((string) $item['label'])); ?>" loading="lazy"><?php endif; ?>
                                <span><?php echo e(display_name((string) $item['label'])); ?></span>
                                <strong><?php echo e(money_value($relPrice)); ?></strong>
                                <small>Envio gratis</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>
        </section>

        <section class="product-content-layout">
            <div class="product-content-main">
                <section class="technical-card product-specs">
                    <h2>Caracteristicas del producto</h2>
                    <dl>
                        <div><dt>Referencia</dt><dd><?php echo e($ref); ?></dd></div>
                        <div><dt>Categoria</dt><dd><?php echo e($category); ?></dd></div>
                        <div><dt>Disponibilidad</dt><dd><?php echo $stock === null ? 'Consultar' : ((float) $stock > 0 ? 'Disponible' : 'Consultar disponibilidad'); ?></dd></div>
                        <?php if (!empty($product['weight'])): ?><div><dt>Peso</dt><dd><?php echo e((string) $product['weight']); ?></dd></div><?php endif; ?>
                        <?php if (!empty($product['length']) || !empty($product['width']) || !empty($product['height'])): ?><div><dt>Dimensiones</dt><dd><?php echo e(trim((string) $product['length'] . ' x ' . (string) $product['width'] . ' x ' . (string) $product['height'], ' x')); ?></dd></div><?php endif; ?>
                    </dl>
                </section>

                <section class="technical-card product-specs">
                    <h2>Que incluye</h2>
                    <dl>
                        <div><dt>Producto</dt><dd><?php echo e($name); ?></dd></div>
                        <div><dt>Referencia</dt><dd><?php echo e($ref); ?></dd></div>
                        <div><dt>Entrega</dt><dd>Retiro en tienda, domicilio local o envio nacional a confirmar.</dd></div>
                        <div><dt>Garantia</dt><dd>Soporte local segun estado y tipo de producto.</dd></div>
                    </dl>
                </section>

                <section class="product-image-section">
                    <h2>Imagenes del producto</h2>
                    <div class="product-image-carousel">
                        <?php foreach ($images as $index => $image): ?>
                            <button type="button" data-gallery-jump="<?php echo e($image); ?>"><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?> foto <?php echo $index + 1; ?>" loading="lazy"></button>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="description-panel">
                    <h2>Descripcion</h2>
                    <p><?php echo e($description !== '' ? display_name($description) : 'Producto disponible para compra o consulta en Station X Tulua. Te confirmamos disponibilidad, estado, forma de entrega y precio final antes de cerrar el pedido.'); ?></p>
                </section>

                <section class="qa-panel" id="preguntas">
                    <h2>Preguntas</h2>
                    <form method="post" class="inline-form product-question-form">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="ask_question">
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>">
                        <input type="hidden" name="customer_name" value="<?php echo e($currentUser['name'] ?? 'Cliente'); ?>">
                        <textarea name="question" placeholder="Escribe tu pregunta..." required></textarea>
                        <button type="submit">Preguntar</button>
                    </form>
                    <?php if (!$questions): ?><div class="empty-cart">Este producto aun no tiene preguntas.</div><?php endif; ?>
                    <?php foreach ($questions as $question): ?>
                        <article class="qa-item"><strong><?php echo e($question['customer_name']); ?></strong><p><?php echo e($question['question']); ?></p><span><?php echo e($question['answer'] ?: 'Respuesta pendiente'); ?></span></article>
                    <?php endforeach; ?>
                </section>

                <section class="qa-panel review-panel" id="resenas">
                    <h2>Opiniones del producto</h2>
                    <div class="review-summary"><strong>5.0</strong><span>&#9733;&#9733;&#9733;&#9733;&#9733;</span><small><?php echo max(1, count($reviews)); ?> calificacion</small></div>
                    <form method="post" class="inline-form stacked">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="review_product">
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>">
                        <select name="rating"><option value="5">5 estrellas</option><option value="4">4 estrellas</option><option value="3">3 estrellas</option><option value="2">2 estrellas</option><option value="1">1 estrella</option></select>
                        <textarea name="comment" placeholder="Comparte tu experiencia"></textarea>
                        <button type="submit">Calificar</button>
                    </form>
                    <?php if (!$reviews): ?><div class="empty-cart">Este producto aun no tiene comentarios.</div><?php endif; ?>
                    <?php foreach ($reviews as $review): ?>
                        <article class="qa-item"><strong><?php echo e($review['name']); ?> - <?php echo (int) $review['rating']; ?>/5</strong><p><?php echo e($review['comment']); ?></p></article>
                    <?php endforeach; ?>
                </section>
            </div>
        </section>

        <?php if ($related): ?>
            <section class="deal-row related-carousel-section">
                <div class="section-title"><h2>Quienes vieron este producto tambien compraron</h2><p>Productos de la misma categoria.</p></div>
                <div class="product-rail">
                    <?php foreach ($related as $item): render_store_product_card($item, $favoriteIds); endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($product): ?>
<div hidden
     data-product-view
     data-name="<?php echo e(display_name((string) $product['label'])); ?>"
     data-url="<?php echo e(product_url($product)); ?>"
     data-image="<?php echo e($images[0] ?? $shareImage); ?>"
     data-price="<?php echo e(money_value($price)); ?>"></div>
<?php endif; ?>
<a class="floating-whatsapp" href="https://wa.me/573155912459?text=<?php echo e(rawurlencode('Hola Station X Tulua, quiero comprar en la tienda online.')); ?>" target="_blank" rel="noopener">WhatsApp</a>
<script>
(() => {
    const mainImage = document.querySelector('.product-main-image > img');
    const thumbs = Array.from(document.querySelectorAll('[data-thumb]'));
    if (!mainImage || !thumbs.length) return;
    let current = Math.max(0, thumbs.findIndex((button) => button.classList.contains('is-active')));
    const showImage = (index) => {
        current = (index + thumbs.length) % thumbs.length;
        const button = thumbs[current];
        mainImage.src = button.dataset.thumb;
        thumbs.forEach((item) => item.classList.toggle('is-active', item === button));
        button.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    };
    thumbs.forEach((button, index) => button.addEventListener('click', () => showImage(index)));
    document.querySelector('[data-product-prev]')?.addEventListener('click', () => showImage(current - 1));
    document.querySelector('[data-product-next]')?.addEventListener('click', () => showImage(current + 1));
    document.querySelectorAll('[data-gallery-jump]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = thumbs.findIndex((thumb) => thumb.dataset.thumb === button.dataset.galleryJump);
            if (target >= 0) {
                showImage(target);
                mainImage.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });
    });
})();
</script>
<?php store_footer(); ?>
<?php echo tracking_scripts(); ?>
</body>
</html>
