<?php
declare(strict_types=1);

$section = $_GET['seccion'] ?? 'terminos';
$pages = [
    'terminos' => [
        'title' => 'Terminos y condiciones',
        'items' => [
            'Los pedidos creados en la tienda quedan sujetos a confirmacion de disponibilidad.',
            'Los precios publicados pueden cambiar si el producto fue actualizado en inventario.',
            'Station X Tulua confirma pago, entrega y garantia antes de cerrar la venta.',
            'Los productos usados pueden tener condiciones particulares informadas antes de entregar.',
        ],
    ],
    'privacidad' => [
        'title' => 'Politica de privacidad',
        'items' => [
            'Los datos de cuenta y pedido se usan para atender compras, soporte y entrega.',
            'No se publican datos personales de clientes.',
            'El cliente puede solicitar actualizacion o eliminacion de sus datos contactando a Station X Tulua.',
            'La tienda usa sesiones para carrito, cuenta y seguridad de formularios.',
        ],
    ],
    'garantia' => [
        'title' => 'Garantia, cambios y devoluciones',
        'items' => [
            'La garantia depende de si el producto es nuevo, usado, accesorio o servicio tecnico.',
            'Todo reclamo debe incluir el numero de pedido y evidencia del inconveniente.',
            'No se cubren danos por golpes, humedad, manipulacion indebida o uso fuera de especificacion.',
            'Los cambios se validan con revision tecnica y disponibilidad de inventario.',
        ],
    ],
];

$page = $pages[$section] ?? $pages['terminos'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'); ?> | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
    <header class="topbar">
        <a class="brand" href="/"><span class="brand-mark">SX</span><span><strong>Station X Tulua</strong><small>Marketplace gamer</small></span></a>
        <nav class="nav"><a href="/">Tienda</a><a href="/legal.php?seccion=privacidad">Privacidad</a><a href="/legal.php?seccion=garantia">Garantia</a></nav>
    </header>
    <main>
        <section class="checkout legal-page">
            <div class="section-title">
                <h1><?php echo htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Informacion comercial de Station X Tulua.</p>
            </div>
            <div class="policies legal-list">
                <?php foreach ($page['items'] as $item): ?>
                    <article><strong>Station X</strong><span><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></span></article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <footer class="footer professional-footer">
        <div><strong>Station X Tulua</strong><span>Consolas, videojuegos, accesorios y soporte gamer.</span></div>
        <div><strong>Tienda</strong><a href="/">Inicio</a><a href="/categoria.php">Categorias</a><a href="/recientes.php">Mas recientes</a></div>
        <div><strong>Soporte</strong><a href="/ayuda.php">Ayuda</a><a href="/sobre.php">Sobre Station X</a><a href="/legal.php?seccion=garantia">Garantia</a></div>
        <div><strong>Legal</strong><a href="/legal.php?seccion=terminos">Terminos</a><a href="/legal.php?seccion=privacidad">Privacidad</a></div>
    </footer>
</body>
</html>
