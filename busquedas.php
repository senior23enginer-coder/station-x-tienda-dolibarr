<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$currentUser = $_SESSION['sx_user'] ?? null;
$cartCount = array_sum(load_cart($pdo, $currentUser));
$searches = fetch_user_searches($pdo, $currentUser);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis busquedas | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Mis busquedas', $cartCount); ?>
<main class="page-shell">
    <section class="page-hero compact-page-hero"><h1>Mis busquedas</h1><p><?php echo $currentUser ? 'Historial asociado a tu cuenta.' : 'Historial guardado en este navegador, sin iniciar sesion.'; ?></p></section>
    <?php if (!$currentUser): ?>
        <section class="history-list search-history-list" data-search-history></section>
    <?php else: ?>
        <?php if (!$searches): ?><div class="empty-cart">Aun no tienes busquedas guardadas.</div><?php endif; ?>
        <section class="history-list">
            <?php foreach ($searches as $search): ?>
                <a href="/buscar.php?q=<?php echo e(rawurlencode((string) $search['query'])); ?><?php echo $search['category'] ? '&c=' . e(rawurlencode((string) $search['category'])) : ''; ?>">
                    <strong><?php echo e((string) $search['query']); ?></strong>
                    <span><?php echo e((string) ($search['category'] ?: 'Todas las categorias')); ?> - <?php echo (int) $search['result_count']; ?> resultado(s)</span>
                    <small><?php echo e((string) $search['created_at']); ?></small>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php store_footer(); ?>
</body>
</html>
