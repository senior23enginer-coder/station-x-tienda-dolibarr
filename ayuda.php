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
    <title>Ayuda | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Ayuda', $cartCount, $cartItems); ?>
<main class="page-shell">
    <section class="help-ml-hero">
        <h1>Con que te ayudamos?</h1>
    </section>
    <section class="help-topic-list">
        <h2>Explora las preguntas frecuentes</h2>
        <a href="/ayuda.php#compras">Compras <span>&rsaquo;</span></a>
        <a href="/ayuda.php#cuenta">Configuracion de mi cuenta <span>&rsaquo;</span></a>
        <a href="/ayuda.php#envios">Envios y entregas <span>&rsaquo;</span></a>
        <a href="/ayuda.php#garantia">Garantia y cambios <span>&rsaquo;</span></a>
        <a href="/ayuda.php#pagos">Pagos y confirmacion <span>&rsaquo;</span></a>
    </section>
    <section class="faq-grid">
        <article id="pagos"><h2>Como se paga?</h2><p>La web registra el pedido. Station X confirma disponibilidad y despues te indica transferencia, Nequi, Daviplata o pago en tienda.</p></article>
        <article id="compras"><h2>Puedo apartar productos?</h2><p>Si. El pedido web funciona como reserva inicial mientras el asesor valida inventario y condiciones.</p></article>
        <article id="envios"><h2>Como veo mi pedido?</h2><p>Entra a tu cuenta y abre Mis pedidos. Cada pedido tiene seguimiento, productos y datos de entrega.</p></article>
        <article id="garantia"><h2>Como pregunto por un producto?</h2><p>En la ficha individual puedes dejar una pregunta o escribir por WhatsApp desde el boton de contacto.</p></article>
        <article id="cuenta"><h2>Hay favoritos?</h2><p>Si. Guarda productos desde el catalogo y revisalos despues en la pagina Favoritos.</p></article>
        <article><h2>Hay comparador?</h2><p>Si. Agrega productos a Comparar para revisar precio, categoria, disponibilidad y descripcion.</p></article>
    </section>
    <section class="help-contact-box"><strong>Necesitas mas ayuda?</strong><a href="https://wa.me/573155912459?text=Hola%20Station%20X%20Tulua%2C%20necesito%20ayuda%20con%20la%20tienda" target="_blank" rel="noopener">Contactanos</a>
    </section>
</main>
<?php store_footer(); ?>
</body>
</html>
