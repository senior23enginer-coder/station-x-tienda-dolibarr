<?php
declare(strict_types=1);

require __DIR__ . '/store-lib.php';
sx_start();

$prefix = 'llx_';
$entity = 1;
$pdo = connect_store_common($prefix, $entity);
$flash = $_SESSION['sx_flash'] ?? null;
$currentUser = $_SESSION['sx_user'] ?? null;
unset($_SESSION['sx_flash']);

function redirect_account(string $message): void
{
    $_SESSION['sx_flash'] = $message;
    header('Location: /cuenta.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        redirect_account('La sesion expiro. Intenta de nuevo.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            redirect_account('Completa nombre, correo valido y clave de minimo 6 caracteres.');
        }
        if ($password !== $passwordConfirm) {
            redirect_account('La clave y la confirmacion no coinciden.');
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO sx_store_users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :hash)');
            $stmt->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        } catch (Throwable $error) {
            redirect_account('Ese correo ya esta registrado.');
        }
        $_SESSION['sx_user'] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'phone' => $phone];
        save_user_cart($pdo, (int) $_SESSION['sx_user']['id'], $_SESSION['sx_cart'] ?? []);
        unset($_SESSION['sx_cart']);
        redirect_account('Cuenta creada correctamente.');
    }
    if ($action === 'login') {
        $stmt = $pdo->prepare('SELECT id, name, email, phone, password_hash FROM sx_store_users WHERE email = :email');
        $stmt->execute(['email' => strtolower(trim((string) ($_POST['email'] ?? '')))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            redirect_account('Correo o clave incorrectos.');
        }
        $_SESSION['sx_user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'phone' => $user['phone']];
        redirect_account('Sesion iniciada.');
    }
    if ($action === 'logout') {
        unset($_SESSION['sx_user']);
        redirect_account('Sesion cerrada.');
    }
    if ($action === 'request_reset') {
        redirect_account('Recuperacion disponible desde el panel anterior. Contacta por WhatsApp si necesitas ayuda inmediata.');
    }
}

$currentUser = $_SESSION['sx_user'] ?? null;
$cartCount = array_sum(load_cart($pdo, $currentUser));
$orders = [];
$favorites = [];
if ($currentUser) {
    $stmt = $pdo->prepare('SELECT id, order_code, total, status, payment_status, shipping_status FROM sx_store_orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 20');
    $stmt->execute(['user_id' => $currentUser['id']]);
    $orders = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT p.rowid, p.label FROM sx_store_favorites f JOIN {$prefix}product p ON p.rowid = f.product_id WHERE f.user_id = :user_id ORDER BY f.id DESC");
    $stmt->execute(['user_id' => $currentUser['id']]);
    $favorites = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cuenta | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look app-page">
<?php store_header('Cuenta', $cartCount); ?>
<?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
<main class="page-shell">
    <section class="page-hero">
        <h1>Tu cuenta</h1>
        <p>Accede a tu perfil, compras, pedidos, favoritos y busquedas.</p>
    </section>
    <?php if ($currentUser): ?>
        <section class="account-checkout">
            <div class="account">
                <h2>Hola, <?php echo e($currentUser['name']); ?></h2>
                <p><?php echo e($currentUser['email']); ?></p>
                <form method="post"><?php echo csrf_input(); ?><input type="hidden" name="action" value="logout"><button type="submit">Cerrar sesion</button></form>
            </div>
            <div class="account-menu">
                <a href="/perfil.php"><strong>Mi perfil</strong><span>Editar nombre y telefono</span></a>
                <a href="/favoritos.php"><strong>Favoritos</strong><span><?php echo count($favorites); ?> producto(s) guardados</span></a>
                <a href="/busquedas.php"><strong>Mis busquedas</strong><span>Historial de productos consultados</span></a>
                <a href="/compras.php"><strong>Mis compras</strong><span>Historial de compras realizadas</span></a>
                <a href="/pedidos.php"><strong>Mis pedidos</strong><span><?php echo count($orders); ?> pedido(s) registrados</span></a>
            </div>
        </section>
    <?php else: ?>
        <section class="account-checkout">
            <form method="post" class="form-card" id="login">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="login">
                <strong>Iniciar sesion</strong>
                <input type="email" name="email" placeholder="Correo" autocomplete="email" required>
                <input type="password" name="password" placeholder="Clave" autocomplete="current-password" required>
                <button type="submit">Entrar</button>
            </form>
            <form method="post" class="form-card" id="registro">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="register">
                <strong>Crear cuenta</strong>
                <input type="text" name="name" placeholder="Nombre completo" autocomplete="name" required>
                <input type="email" name="email" placeholder="Correo" autocomplete="email" required>
                <input type="tel" name="phone" placeholder="Telefono / WhatsApp" autocomplete="tel">
                <input type="password" name="password" placeholder="Clave minimo 6 caracteres" minlength="6" autocomplete="new-password" required>
                <input type="password" name="password_confirm" placeholder="Confirmar clave" minlength="6" autocomplete="new-password" required>
                <button type="submit">Registrarme</button>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php store_footer(); ?>
</body>
</html>
