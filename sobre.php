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
    <title>Sobre Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Sobre Station X', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="about-hero">
        <div>
            <span>Tienda gamer en Tulua</span>
            <h1>Station X Tulua combina tienda, soporte y comunidad gamer local.</h1>
            <p>Venta de consolas, videojuegos, controles, accesorios, coleccionables y servicio tecnico con atencion cercana.</p>
        </div>
        <?php if ($banner = public_upload('banner')): ?><img src="<?php echo e($banner); ?>" alt="Station X Tulua"><?php endif; ?>
    </section>
    <section class="about-grid">
        <article><strong>Catalogo conectado</strong><span>Productos publicados desde el inventario existente.</span></article>
        <article><strong>Atencion directa</strong><span>WhatsApp, redes sociales y compra asistida.</span></article>
        <article><strong>Soporte gamer</strong><span>Mantenimiento, diagnostico y reparacion.</span></article>
    </section>
    <section class="store-info-layout">
        <article class="store-info-card">
            <h2>Visitanos</h2>
            <p>Carrera 25 #28-37, Tulua, Valle del Cauca.</p>
            <a href="https://www.google.com/maps/search/?api=1&query=Station%20X%20Tulua" target="_blank" rel="noopener">Abrir mapa</a>
        </article>
        <article class="store-info-card">
            <h2>Redes oficiales</h2>
            <p>Novedades, promociones, nuevos ingresos y contenido gamer.</p>
            <a href="https://www.instagram.com/stationxtulua/?hl=es" target="_blank" rel="noopener">Instagram</a>
            <a href="https://www.facebook.com/stationxtulua1/?locale=es_LA" target="_blank" rel="noopener">Facebook</a>
        </article>
        <article class="store-info-card">
            <h2>Comprar online</h2>
            <p>Explora el catalogo, guarda favoritos, compara y crea pedidos para confirmar con asesor.</p>
            <a href="/#catalogo">Ver catalogo</a>
        </article>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
