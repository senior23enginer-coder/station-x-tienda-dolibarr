<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/store-private.php';

$confPath = __DIR__ . '/htdocs/conf/conf.php';
$products = [];
$featured = [];
$erpCategories = [];
$cartItems = [];
$favoriteIds = [];
$userOrders = [];
$currentUser = $_SESSION['sx_user'] ?? null;
$flash = $_SESSION['sx_flash'] ?? null;
$loadError = null;
$pdo = null;
$prefix = 'llx_';
$entity = 1;
$whatsappNumber = '573155912459';
$shippingRates = [
    'retiro' => ['label' => 'Retiro en tienda', 'price' => 0],
    'tulua' => ['label' => 'Domicilio Tulua', 'price' => 8000],
    'valle' => ['label' => 'Envio Valle del Cauca', 'price' => 18000],
    'nacional' => ['label' => 'Envio nacional', 'price' => 25000],
];
$paymentMethods = [
    ['code' => 'confirmar', 'label' => 'Confirmar con asesor', 'enabled' => 1],
    ['code' => 'transferencia', 'label' => 'Transferencia bancaria', 'enabled' => 1],
    ['code' => 'nequi', 'label' => 'Nequi / Daviplata', 'enabled' => 1],
    ['code' => 'datafono', 'label' => 'Pago con tarjeta en tienda', 'enabled' => 1],
];

unset($_SESSION['sx_flash']);

if (empty($_SESSION['sx_csrf'])) {
    $_SESSION['sx_csrf'] = bin2hex(random_bytes(32));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['sx_csrf'] ?? '') . '">';
}

function money_value($value): string
{
    if ($value === null || $value === '') {
        return 'Consultar';
    }

    return '$' . number_format((float) $value, 0, ',', '.');
}

function display_name(string $value): string
{
    $clean = trim(preg_replace('/[_\s]+/', ' ', strip_tags($value)));
    if ($clean === '') {
        return 'Producto';
    }

    return ucwords(strtolower($clean));
}

function product_slug(string $value): string
{
    $value = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim((string) $value, '-') ?: 'producto';
}

function product_url(array $product): string
{
    return '/producto/' . (int) $product['rowid'] . '-' . product_slug(display_name((string) $product['label']));
}

function product_base_price(array $product): float
{
    return (float) (($product['price_ttc'] !== null && $product['price_ttc'] !== '') ? $product['price_ttc'] : $product['price']);
}

function product_effective_price(array $product): float
{
    $promo = (float) ($product['promo_price'] ?? 0);
    return $promo > 0 ? $promo : product_base_price($product);
}

function product_discount_percent(array $product): int
{
    $base = product_base_price($product);
    $promo = (float) ($product['promo_price'] ?? 0);
    if ($base <= 0 || $promo <= 0 || $promo >= $base) {
        return 0;
    }
    return (int) round((1 - ($promo / $base)) * 100);
}

function product_category(array $product): string
{
    $erpCategories = trim((string) ($product['erp_categories'] ?? ''));
    if ($erpCategories !== '') {
        $parts = array_values(array_filter(array_map('trim', explode(',', $erpCategories))));
        if ($parts) {
            return display_name($parts[0]);
        }
    }
    $text = strtolower(($product['ref'] ?? '') . ' ' . ($product['label'] ?? '') . ' ' . ($product['description'] ?? ''));
    if (preg_match('/play|xbox|nintendo|switch|consol|ps[345]|3ds|wii/', $text)) {
        return 'Consolas';
    }
    if (preg_match('/juego|game|doom|detroit|yakuza|naruto|call of duty|fifa|mario/', $text)) {
        return 'Videojuegos';
    }
    if (preg_match('/control|grip|pila|estuche|maleta|mouse|cable|cargador|pad/', $text)) {
        return 'Accesorios';
    }
    if (preg_match('/figura|peluche|pikachu|dragon|hello|coleccion/', $text)) {
        return 'Coleccionables';
    }

    return 'Gaming';
}

function product_matches_category(array $product, string $category): bool
{
    $category = trim($category);
    if ($category === '' || $category === 'Todo') {
        return true;
    }
    $erpCategories = array_values(array_filter(array_map('trim', explode(',', (string) ($product['erp_categories'] ?? '')))));
    foreach ($erpCategories as $erpCategory) {
        if (strcasecmp($erpCategory, $category) === 0 || strcasecmp(display_name($erpCategory), $category) === 0) {
            return true;
        }
    }
    return strcasecmp(product_category($product), $category) === 0;
}

function product_image_url(string $ref, int $width = 0): ?string
{
    $safeRef = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $ref);
    if ($safeRef === '' || $safeRef === null) {
        return null;
    }

    $dir = __DIR__ . '/documents/produit/' . $safeRef;
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG,WEBP,GIF}', GLOB_BRACE);
    if (!$files) {
        return null;
    }

    return '/product-image.php?ref=' . rawurlencode($safeRef) . '&file=' . rawurlencode(basename($files[0])) . ($width > 0 ? '&w=' . $width : '');
}

function product_image_urls(string $ref, int $limit = 6, int $width = 0): array
{
    $safeRef = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $ref);
    if ($safeRef === '' || $safeRef === null) {
        return [];
    }

    $dir = __DIR__ . '/documents/produit/' . $safeRef;
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG,WEBP,GIF}', GLOB_BRACE);
    if (!$files) {
        return [];
    }

    return array_map(
        static fn($file) => '/product-image.php?ref=' . rawurlencode($safeRef) . '&file=' . rawurlencode(basename($file)) . ($width > 0 ? '&w=' . $width : ''),
        array_slice($files, 0, $limit)
    );
}

function public_upload(string $name): ?string
{
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $path = __DIR__ . '/assets/uploads/' . $name . '.' . $ext;
        if (is_file($path)) {
            return '/assets/uploads/' . $name . '.' . $ext . '?v=' . filemtime($path);
        }
    }
    return null;
}

function cart_whatsapp_text(array $items, float $total): string
{
    $lines = ['Hola Station X Tulua, quiero confirmar este pedido:', ''];
    foreach ($items as $item) {
        $lines[] = ((int) $item['quantity']) . ' x ' . display_name((string) $item['label']) . ' - ' . money_value($item['unit_price']);
    }
    $lines[] = '';
    $lines[] = 'Total estimado: ' . money_value($total);
    return rawurlencode(implode("\n", $lines));
}

function send_store_mail(string $to, string $subject, string $body): bool
{
    if (defined('SX_SMTP_HOST') && SX_SMTP_HOST !== '' && defined('SX_SMTP_USER') && SX_SMTP_USER !== '') {
        $host = SX_SMTP_HOST;
        $port = defined('SX_SMTP_PORT') ? (int) SX_SMTP_PORT : 587;
        $socket = @stream_socket_client($host . ':' . $port, $errno, $errstr, 12);
        if ($socket) {
            $read = static fn() => fgets($socket, 515);
            $write = static fn(string $line) => fwrite($socket, $line . "\r\n");
            $read();
            $write('EHLO stationxtulua.com');
            while (($line = $read()) && isset($line[3]) && $line[3] === '-') {
            }
            if (defined('SX_SMTP_SECURE') && SX_SMTP_SECURE === 'tls') {
                $write('STARTTLS');
                $read();
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $write('EHLO stationxtulua.com');
                    while (($line = $read()) && isset($line[3]) && $line[3] === '-') {
                    }
                }
            }
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode(SX_SMTP_USER));
            $read();
            $write(base64_encode(SX_SMTP_PASS));
            $read();
            $write('MAIL FROM:<' . SX_STORE_EMAIL . '>');
            $read();
            $write('RCPT TO:<' . $to . '>');
            $read();
            $write('DATA');
            $read();
            $message = "From: Station X Tulua <" . SX_STORE_EMAIL . ">\r\n";
            $message .= "Reply-To: " . SX_STORE_EMAIL . "\r\n";
            $message .= "To: <" . $to . ">\r\n";
            $message .= "Subject: " . $subject . "\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $message .= $body . "\r\n.";
            $write($message);
            $result = (string) $read();
            $write('QUIT');
            fclose($socket);
            if (str_starts_with($result, '250')) {
                return true;
            }
        }
    }

    $headers = [
        'From: Station X Tulua <' . SX_STORE_EMAIL . '>',
        'Reply-To: ' . SX_STORE_EMAIL,
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function tracking_scripts(): string
{
    $html = '';
    if (defined('SX_GA4_ID') && SX_GA4_ID !== '') {
        $id = e(SX_GA4_ID);
        $html .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . $id . '");</script>';
    }
    if (defined('SX_META_PIXEL_ID') && SX_META_PIXEL_ID !== '') {
        $id = e(SX_META_PIXEL_ID);
        $html .= '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","' . $id . '");fbq("track","PageView");</script>';
    }
    return $html;
}

function connect_store(string $confPath, string &$prefix, int &$entity): PDO
{
    if (!is_file($confPath)) {
        throw new RuntimeException('No se encontro la configuracion de base de datos.');
    }

    require $confPath;

    $driver = $dolibarr_main_db_type ?? 'mysqli';
    $host = $dolibarr_main_db_host ?? 'localhost';
    $port = $dolibarr_main_db_port ?? '';
    $name = $dolibarr_main_db_name ?? '';
    $user = $dolibarr_main_db_user ?? '';
    $pass = $dolibarr_main_db_pass ?? '';
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $dolibarr_main_db_prefix ?? 'llx_');
    $entity = (int) ($dolibarr_main_entity ?? 1);

    if (!in_array($driver, ['mysqli', 'mysql'], true)) {
        throw new RuntimeException('La tienda espera MySQL/MariaDB.');
    }

    $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function ensure_store_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            phone VARCHAR(40) NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY ux_cart_user_product (user_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            customer_name VARCHAR(120) NOT NULL,
            customer_email VARCHAR(180) NULL,
            customer_phone VARCHAR(40) NOT NULL,
            delivery_method VARCHAR(40) NOT NULL,
            address VARCHAR(255) NULL,
            notes TEXT NULL,
            total DECIMAL(18,2) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'nuevo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_ref VARCHAR(128) NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            price DECIMAL(18,2) NOT NULL DEFAULT 0,
            quantity INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_favorites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_favorite_user_product (user_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_product_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            user_id INT NULL,
            customer_name VARCHAR(120) NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pendiente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_product_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            user_id INT NOT NULL,
            rating TINYINT NOT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_review_user_product (user_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_shipping_rates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            price DECIMAL(18,2) NOT NULL DEFAULT 0,
            enabled TINYINT NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_payment_methods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            enabled TINYINT NOT NULL DEFAULT 1,
            instructions TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            movement_type VARCHAR(40) NOT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_stock_order_product_type (order_id, product_id, movement_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach ([
        "ALTER TABLE sx_store_orders ADD COLUMN payment_method VARCHAR(40) NULL AFTER delivery_method",
        "ALTER TABLE sx_store_orders ADD COLUMN payment_status VARCHAR(40) NOT NULL DEFAULT 'pendiente' AFTER status",
        "ALTER TABLE sx_store_orders ADD COLUMN shipping_status VARCHAR(40) NOT NULL DEFAULT 'pendiente' AFTER payment_status",
        "ALTER TABLE sx_store_orders ADD COLUMN subtotal DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER notes",
        "ALTER TABLE sx_store_orders ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER subtotal",
        "ALTER TABLE sx_store_orders ADD COLUMN shipping_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_amount",
        "ALTER TABLE sx_store_orders ADD COLUMN tracking_url VARCHAR(255) NULL AFTER shipping_status",
        "ALTER TABLE sx_store_orders ADD COLUMN payment_evidence_status VARCHAR(40) NOT NULL DEFAULT 'por_confirmar' AFTER tracking_url",
        "ALTER TABLE sx_store_orders ADD COLUMN payment_evidence_note VARCHAR(255) NULL AFTER payment_evidence_status",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_invoice_id INT NULL AFTER payment_evidence_note",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_invoice_ref VARCHAR(64) NULL AFTER dolibarr_invoice_id",
        "ALTER TABLE sx_store_payment_methods ADD COLUMN payment_url VARCHAR(255) NULL AFTER instructions",
        "ALTER TABLE sx_store_orders ADD COLUMN order_code VARCHAR(32) NULL AFTER id",
        "ALTER TABLE sx_store_users ADD COLUMN is_admin TINYINT NOT NULL DEFAULT 0 AFTER password_hash"
    ] as $ddl) {
        try {
            $pdo->exec($ddl);
        } catch (Throwable $alreadyExists) {
        }
    }

    $defaults = [
        ['retiro', 'Retiro en tienda', 0],
        ['tulua', 'Domicilio Tulua', 8000],
        ['valle', 'Envio Valle del Cauca', 18000],
        ['nacional', 'Envio nacional', 25000],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO sx_store_shipping_rates (code, label, price) VALUES (:code, :label, :price)');
    foreach ($defaults as $rate) {
        $stmt->execute(['code' => $rate[0], 'label' => $rate[1], 'price' => $rate[2]]);
    }

    $methods = [
        ['confirmar', 'Confirmar con asesor'],
        ['transferencia', 'Transferencia bancaria'],
        ['nequi', 'Nequi / Daviplata'],
        ['datafono', 'Pago con tarjeta en tienda'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO sx_store_payment_methods (code, label) VALUES (:code, :label)');
    foreach ($methods as $method) {
        $stmt->execute(['code' => $method[0], 'label' => $method[1]]);
    }
}

function get_session_cart(): array
{
    return $_SESSION['sx_cart'] ?? [];
}

function set_session_cart(array $cart): void
{
    $_SESSION['sx_cart'] = $cart;
}

function add_session_cart(int $productId, int $quantity): void
{
    $cart = get_session_cart();
    $cart[$productId] = max(1, ($cart[$productId] ?? 0) + $quantity);
    set_session_cart($cart);
}

function load_cart(PDO $pdo, ?array $user): array
{
    if ($user) {
        $stmt = $pdo->prepare('SELECT product_id, quantity FROM sx_store_cart_items WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['id']]);
        $cart = [];
        foreach ($stmt->fetchAll() as $row) {
            $cart[(int) $row['product_id']] = (int) $row['quantity'];
        }
        return $cart;
    }

    return get_session_cart();
}

function save_user_cart(PDO $pdo, int $userId, array $cart): void
{
    $pdo->prepare('DELETE FROM sx_store_cart_items WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    $stmt = $pdo->prepare('INSERT INTO sx_store_cart_items (user_id, product_id, quantity) VALUES (:user_id, :product_id, :quantity)');
    foreach ($cart as $productId => $quantity) {
        if ((int) $quantity > 0) {
            $stmt->execute(['user_id' => $userId, 'product_id' => (int) $productId, 'quantity' => (int) $quantity]);
        }
    }
}

function fetch_products(PDO $pdo, string $prefix, int $entity, int $limit = 5000): array
{
    $sql = "
        SELECT
            p.rowid,
            p.ref,
            p.label,
            p.description,
            p.price,
            p.price_ttc,
            COALESCE((SELECT SUM(ps.reel) FROM {$prefix}product_stock ps WHERE ps.fk_product = p.rowid), 0) AS stock,
            GROUP_CONCAT(DISTINCT c.label ORDER BY c.label SEPARATOR ', ') AS erp_categories,
            COALESCE((SELECT AVG(r.rating) FROM sx_store_product_reviews r WHERE r.product_id = p.rowid), 0) AS avg_rating,
            (SELECT COUNT(*) FROM sx_store_product_reviews r WHERE r.product_id = p.rowid) AS review_count,
            (SELECT COUNT(*) FROM sx_store_product_questions q WHERE q.product_id = p.rowid) AS question_count
        FROM {$prefix}product p
        LEFT JOIN {$prefix}categorie_product cp ON cp.fk_product = p.rowid
        LEFT JOIN {$prefix}categorie c ON c.rowid = cp.fk_categorie AND c.type = 0
        WHERE p.tosell = 1
          AND (p.entity = :entity OR p.entity IS NULL)
          AND EXISTS (
              SELECT 1
              FROM {$prefix}categorie_product cp_store
              INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie
              WHERE cp_store.fk_product = p.rowid
                AND c_store.type = 0
                AND UPPER(TRIM(c_store.label)) = 'TIENDA'
          )
        GROUP BY p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc
        ORDER BY p.rowid DESC
        LIMIT {$limit}
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['entity' => $entity]);
        return $stmt->fetchAll();
    } catch (Throwable $stockError) {
        $sql = "
            SELECT rowid, ref, label, description, price, price_ttc, NULL AS stock, NULL AS erp_categories, 0 AS avg_rating, 0 AS review_count, 0 AS question_count
            FROM {$prefix}product p
            WHERE p.tosell = 1
              AND (p.entity = :entity OR p.entity IS NULL)
              AND EXISTS (
                  SELECT 1
                  FROM {$prefix}categorie_product cp_store
                  INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie
                  WHERE cp_store.fk_product = p.rowid
                    AND c_store.type = 0
                    AND UPPER(TRIM(c_store.label)) = 'TIENDA'
              )
            ORDER BY rowid DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['entity' => $entity]);
        return $stmt->fetchAll();
    }
}

function fetch_erp_categories(PDO $pdo, string $prefix, int $entity): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                c.rowid,
                c.label,
                c.description,
                COUNT(DISTINCT p.rowid) AS product_count,
                SUBSTRING_INDEX(GROUP_CONCAT(p.ref ORDER BY p.rowid DESC SEPARATOR '|||'), '|||', 1) AS sample_ref
            FROM {$prefix}categorie c
            LEFT JOIN {$prefix}categorie_product cp ON cp.fk_categorie = c.rowid
            LEFT JOIN {$prefix}product p ON p.rowid = cp.fk_product
                AND p.tosell = 1
                AND (p.entity = :entity_products OR p.entity IS NULL)
                AND EXISTS (
                    SELECT 1
                    FROM {$prefix}categorie_product cp_store
                    INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie
                    WHERE cp_store.fk_product = p.rowid
                      AND c_store.type = 0
                      AND UPPER(TRIM(c_store.label)) = 'TIENDA'
                )
            WHERE c.type = 0
              AND (c.entity = :entity_categories OR c.entity IS NULL)
            GROUP BY c.rowid, c.label, c.description
            HAVING product_count > 0
            ORDER BY c.label ASC
        ");
        $stmt->execute(['entity_products' => $entity, 'entity_categories' => $entity]);
        return $stmt->fetchAll();
    } catch (Throwable $ignored) {
        return [];
    }
}

function fetch_cart_items(PDO $pdo, string $prefix, array $cart): array
{
    if (!$cart) {
        return [];
    }

    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT p.rowid, p.ref, p.label, p.price, p.price_ttc,
               COALESCE((SELECT SUM(ps.reel) FROM {$prefix}product_stock ps WHERE ps.fk_product = p.rowid), 0) AS stock
        FROM {$prefix}product p
        WHERE p.rowid IN ({$placeholders})
          AND p.tosell = 1
          AND EXISTS (
              SELECT 1
              FROM {$prefix}categorie_product cp_store
              INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie
              WHERE cp_store.fk_product = p.rowid
                AND c_store.type = 0
                AND UPPER(TRIM(c_store.label)) = 'TIENDA'
          )
    ");
    $stmt->execute($ids);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['quantity'] = $cart[(int) $row['rowid']] ?? 0;
        $row['unit_price'] = $row['price_ttc'] !== null && $row['price_ttc'] !== '' ? $row['price_ttc'] : $row['price'];
        $items[] = $row;
    }

    return $items;
}

function fetch_product_by_id(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc FROM {$prefix}product p WHERE p.rowid = :id AND p.tosell = 1 AND EXISTS (SELECT 1 FROM {$prefix}categorie_product cp_store INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie WHERE cp_store.fk_product = p.rowid AND c_store.type = 0 AND UPPER(TRIM(c_store.label)) = 'TIENDA')");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_favorite_ids(PDO $pdo, ?array $user): array
{
    if (!$user) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT product_id FROM sx_store_favorites WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $user['id']]);
    return array_map('intval', array_column($stmt->fetchAll(), 'product_id'));
}

function fetch_user_orders(PDO $pdo, ?array $user): array
{
    if (!$user) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, order_code, total, status, payment_status, shipping_status, created_at FROM sx_store_orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 10');
    $stmt->execute(['user_id' => $user['id']]);
    return $stmt->fetchAll();
}

function fetch_product_questions(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT customer_name, question, answer, created_at FROM sx_store_product_questions WHERE product_id = :product_id ORDER BY id DESC LIMIT 12');
    $stmt->execute(['product_id' => $productId]);
    return $stmt->fetchAll();
}

function fetch_product_reviews(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT r.rating, r.comment, r.created_at, u.name FROM sx_store_product_reviews r JOIN sx_store_users u ON u.id = r.user_id WHERE r.product_id = :product_id ORDER BY r.id DESC LIMIT 12');
    $stmt->execute(['product_id' => $productId]);
    return $stmt->fetchAll();
}

function fetch_shipping_rates(PDO $pdo, array $fallback): array
{
    try {
        $rows = $pdo->query('SELECT code, label, price, enabled FROM sx_store_shipping_rates WHERE enabled = 1 ORDER BY price ASC, id ASC')->fetchAll();
        if ($rows) {
            $rates = [];
            foreach ($rows as $row) {
                $rates[(string) $row['code']] = ['label' => (string) $row['label'], 'price' => (float) $row['price']];
            }
            return $rates;
        }
    } catch (Throwable $ignored) {
    }
    return $fallback;
}

function fetch_payment_methods(PDO $pdo, array $fallback): array
{
    try {
        $rows = $pdo->query('SELECT code, label, enabled, instructions, payment_url FROM sx_store_payment_methods WHERE enabled = 1 ORDER BY id ASC')->fetchAll();
        if ($rows) {
            return $rows;
        }
    } catch (Throwable $ignored) {
    }
    return $fallback;
}

function find_payment_method(array $methods, string $code): ?array
{
    foreach ($methods as $method) {
        if ((string) ($method['code'] ?? '') === $code) {
            return $method;
        }
    }
    return $methods[0] ?? null;
}

function flash_redirect(string $message, string $target = '/'): void
{
    $_SESSION['sx_flash'] = $message;
    header('Location: ' . $target);
    exit;
}

try {
    $pdo = connect_store($confPath, $prefix, $entity);
    ensure_store_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if (!hash_equals($_SESSION['sx_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
            flash_redirect('La sesion expiro. Intenta de nuevo.');
        }

        if ($action === 'register') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
                flash_redirect('Completa nombre, correo valido y clave de minimo 6 caracteres.', '/cuenta.php');
            }

            $stmt = $pdo->prepare('INSERT INTO sx_store_users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :password_hash)');
            try {
                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } catch (Throwable $duplicate) {
                flash_redirect('Ese correo ya esta registrado. Inicia sesion para continuar.', '/cuenta.php');
            }

            $_SESSION['sx_user'] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'phone' => $phone];
            save_user_cart($pdo, (int) $_SESSION['sx_user']['id'], get_session_cart());
            unset($_SESSION['sx_cart']);
            flash_redirect('Cuenta creada. Tu carrito quedo guardado.', '/carrito.php');
        }

        if ($action === 'login') {
            $_SESSION['sx_login_attempts'] = $_SESSION['sx_login_attempts'] ?? [];
            $_SESSION['sx_login_attempts'] = array_values(array_filter(
                $_SESSION['sx_login_attempts'],
                static fn($time) => $time > time() - 600
            ));
            if (count($_SESSION['sx_login_attempts']) >= 6) {
                flash_redirect('Demasiados intentos. Espera unos minutos e intenta de nuevo.', '/cuenta.php');
            }

            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT id, name, email, phone, password_hash FROM sx_store_users WHERE email = :email');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $_SESSION['sx_login_attempts'][] = time();
                flash_redirect('Correo o clave incorrectos.', '/cuenta.php');
            }

            $_SESSION['sx_login_attempts'] = [];
            $_SESSION['sx_user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'phone' => $user['phone']];
            $serverCart = load_cart($pdo, $_SESSION['sx_user']);
            foreach (get_session_cart() as $productId => $quantity) {
                $serverCart[$productId] = ($serverCart[$productId] ?? 0) + $quantity;
            }
            save_user_cart($pdo, (int) $_SESSION['sx_user']['id'], $serverCart);
            unset($_SESSION['sx_cart']);
            flash_redirect('Sesion iniciada. Tu carrito esta listo.', '/carrito.php');
        }

        if ($action === 'request_reset') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $stmt = $pdo->prepare('SELECT id FROM sx_store_users WHERE email = :email');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            if ($user) {
                $token = bin2hex(random_bytes(16));
                $stmt = $pdo->prepare('INSERT INTO sx_store_password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
                $stmt->execute(['user_id' => (int) $user['id'], 'token_hash' => password_hash($token, PASSWORD_DEFAULT)]);
                send_store_mail($email, 'Recuperacion de clave Station X Tulua', "Tu token de recuperacion es: {$token}\n\nVence en 30 minutos.");
                flash_redirect('Token de recuperacion: ' . $token . ' . Copialo y define tu nueva clave.', '/cuenta.php');
            }
            flash_redirect('Si el correo existe, se genero un token de recuperacion.', '/cuenta.php');
        }

        if ($action === 'reset_password') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $token = trim((string) ($_POST['token'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 6) {
                flash_redirect('La nueva clave debe tener minimo 6 caracteres.', '/cuenta.php');
            }

            $stmt = $pdo->prepare('
                SELECT pr.id, pr.token_hash, pr.user_id
                FROM sx_store_password_resets pr
                JOIN sx_store_users u ON u.id = pr.user_id
                WHERE u.email = :email AND pr.used_at IS NULL AND pr.expires_at > NOW()
                ORDER BY pr.id DESC
                LIMIT 1
            ');
            $stmt->execute(['email' => $email]);
            $reset = $stmt->fetch();
            if (!$reset || !password_verify($token, $reset['token_hash'])) {
                flash_redirect('Token invalido o expirado.', '/cuenta.php');
            }

            $pdo->prepare('UPDATE sx_store_users SET password_hash = :hash WHERE id = :id')->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => (int) $reset['user_id'],
            ]);
            $pdo->prepare('UPDATE sx_store_password_resets SET used_at = NOW() WHERE id = :id')->execute(['id' => (int) $reset['id']]);
            flash_redirect('Clave actualizada. Ya puedes iniciar sesion.', '/cuenta.php');
        }

        if ($action === 'logout') {
            unset($_SESSION['sx_user']);
            flash_redirect('Sesion cerrada.');
        }

        if ($action === 'add_cart') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId > 0) {
                if ($_SESSION['sx_user'] ?? null) {
                    $cart = load_cart($pdo, $_SESSION['sx_user']);
                    $cart[$productId] = ($cart[$productId] ?? 0) + 1;
                    save_user_cart($pdo, (int) $_SESSION['sx_user']['id'], $cart);
                } else {
                    add_session_cart($productId, 1);
                }
            }
            flash_redirect('Producto agregado al carrito.', '/carrito.php');
        }

        if ($action === 'toggle_favorite') {
            $user = $_SESSION['sx_user'] ?? null;
            if (!$user) {
                flash_redirect('Inicia sesion para guardar favoritos.', '/cuenta.php');
            }

            $productId = (int) ($_POST['product_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id FROM sx_store_favorites WHERE user_id = :user_id AND product_id = :product_id');
            $stmt->execute(['user_id' => (int) $user['id'], 'product_id' => $productId]);
            if ($stmt->fetch()) {
                $pdo->prepare('DELETE FROM sx_store_favorites WHERE user_id = :user_id AND product_id = :product_id')->execute(['user_id' => (int) $user['id'], 'product_id' => $productId]);
                flash_redirect('Producto retirado de favoritos.', '#catalogo');
            }
            $pdo->prepare('INSERT INTO sx_store_favorites (user_id, product_id) VALUES (:user_id, :product_id)')->execute(['user_id' => (int) $user['id'], 'product_id' => $productId]);
            flash_redirect('Producto guardado en favoritos.', '/cuenta.php');
        }

        if ($action === 'ask_question') {
            $user = $_SESSION['sx_user'] ?? null;
            $productId = (int) ($_POST['product_id'] ?? 0);
            $name = trim((string) ($_POST['customer_name'] ?? ($user['name'] ?? 'Cliente')));
            $question = trim((string) ($_POST['question'] ?? ''));
            if ($productId <= 0 || $question === '') {
                flash_redirect('Escribe tu pregunta sobre el producto.', '#producto');
            }
            $stmt = $pdo->prepare('INSERT INTO sx_store_product_questions (product_id, user_id, customer_name, question) VALUES (:product_id, :user_id, :customer_name, :question)');
            $stmt->execute([
                'product_id' => $productId,
                'user_id' => $user['id'] ?? null,
                'customer_name' => $name !== '' ? $name : 'Cliente',
                'question' => $question,
            ]);
            flash_redirect('Pregunta enviada. Station X la respondera pronto.', '/producto.php?id=' . $productId . '#preguntas');
        }

        if ($action === 'review_product') {
            $user = $_SESSION['sx_user'] ?? null;
            if (!$user) {
                flash_redirect('Inicia sesion para calificar productos.', '/cuenta.php');
            }
            $productId = (int) ($_POST['product_id'] ?? 0);
            $rating = min(5, max(1, (int) ($_POST['rating'] ?? 5)));
            $comment = trim((string) ($_POST['comment'] ?? ''));
            $stmt = $pdo->prepare('
                INSERT INTO sx_store_product_reviews (product_id, user_id, rating, comment)
                VALUES (:product_id, :user_id, :rating, :comment)
                ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), created_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                'product_id' => $productId,
                'user_id' => (int) $user['id'],
                'rating' => $rating,
                'comment' => $comment,
            ]);
            flash_redirect('Gracias por tu calificacion.', '/producto.php?id=' . $productId . '#resenas');
        }

        if ($action === 'update_cart') {
            $cart = [];
            foreach (($_POST['qty'] ?? []) as $productId => $quantity) {
                $qty = max(0, (int) $quantity);
                if ($qty > 0) {
                    $cart[(int) $productId] = $qty;
                }
            }

            if ($_SESSION['sx_user'] ?? null) {
                save_user_cart($pdo, (int) $_SESSION['sx_user']['id'], $cart);
            } else {
                set_session_cart($cart);
            }
            flash_redirect('Carrito actualizado.', '/carrito.php');
        }

        if ($action === 'checkout') {
            $user = $_SESSION['sx_user'] ?? null;
            if (!$user) {
                flash_redirect('Inicia sesion o registrate para crear el pedido. Tu carrito queda guardado.', '/cuenta.php');
            }
            $cart = load_cart($pdo, $user);
            $items = fetch_cart_items($pdo, $prefix, $cart);
            if (!$items) {
                flash_redirect('Agrega productos antes de finalizar.', '#catalogo');
            }
            foreach ($items as $item) {
                if (isset($item['stock']) && (float) $item['stock'] < (int) $item['quantity']) {
                    flash_redirect('Ajusta cantidades: ' . display_name((string) $item['label']) . ' tiene stock disponible de ' . (int) $item['stock'] . '.', '/carrito.php');
                }
            }

            $customerName = trim((string) ($_POST['customer_name'] ?? ($user['name'] ?? '')));
            $customerEmail = trim((string) ($_POST['customer_email'] ?? ($user['email'] ?? '')));
            $customerPhone = trim((string) ($_POST['customer_phone'] ?? ($user['phone'] ?? '')));
            $deliveryMethod = trim((string) ($_POST['delivery_method'] ?? 'tienda'));
            $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'confirmar'));
            $deliveryRate = $shippingRates[$deliveryMethod] ?? reset($shippingRates);
            $selectedPayment = find_payment_method($paymentMethods, $paymentMethod);
            if (!$deliveryRate || !$selectedPayment) {
                flash_redirect('Selecciona un metodo de entrega y pago disponible.', '/carrito.php');
            }
            $address = trim((string) ($_POST['address'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $evidenceStatus = (string) ($_POST['payment_evidence_status'] ?? 'por_confirmar');
            if (!in_array($evidenceStatus, ['por_confirmar', 'enviada_whatsapp', 'pendiente_transferencia'], true)) {
                $evidenceStatus = 'por_confirmar';
            }
            $evidenceNote = trim((string) ($_POST['payment_evidence_note'] ?? ''));

            if ($customerName === '' || $customerPhone === '') {
                flash_redirect('Necesitamos nombre y telefono para confirmar el pedido.', '/carrito.php');
            }

            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $shippingAmount = (float) ($deliveryRate['price'] ?? 0);
            $total = max(0, $subtotal + $shippingAmount);

            $pdo->beginTransaction();
            $orderCode = 'SX-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $stmt = $pdo->prepare('
                INSERT INTO sx_store_orders (order_code, user_id, customer_name, customer_email, customer_phone, delivery_method, payment_method, address, notes, subtotal, shipping_amount, total, payment_evidence_status, payment_evidence_note)
                VALUES (:order_code, :user_id, :customer_name, :customer_email, :customer_phone, :delivery_method, :payment_method, :address, :notes, :subtotal, :shipping_amount, :total, :payment_evidence_status, :payment_evidence_note)
            ');
            $stmt->execute([
                'order_code' => $orderCode,
                'user_id' => $user['id'] ?? null,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'delivery_method' => $deliveryMethod,
                'payment_method' => (string) $selectedPayment['code'],
                'address' => $address,
                'notes' => $notes . "\nEntrega: " . $deliveryRate['label'] . ' (' . money_value($shippingAmount) . ')' . "\nPago: " . $selectedPayment['label'],
                'subtotal' => $subtotal,
                'shipping_amount' => $shippingAmount,
                'total' => $total,
                'payment_evidence_status' => $evidenceStatus,
                'payment_evidence_note' => $evidenceNote,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO sx_store_order_items (order_id, product_id, product_ref, product_name, price, quantity)
                VALUES (:order_id, :product_id, :product_ref, :product_name, :price, :quantity)
            ');
            foreach ($items as $item) {
                $itemStmt->execute([
                    'order_id' => $orderId,
                    'product_id' => (int) $item['rowid'],
                    'product_ref' => (string) $item['ref'],
                    'product_name' => display_name((string) $item['label']),
                    'price' => (float) $item['unit_price'],
                    'quantity' => (int) $item['quantity'],
                ]);
            }
            $pdo->commit();

            if ($user) {
                save_user_cart($pdo, (int) $user['id'], []);
            } else {
                set_session_cart([]);
            }

            $mailBody = "Pedido {$orderCode}\nCliente: {$customerName}\nTelefono: {$customerPhone}\nTotal: " . money_value($total) . "\n\nProductos:\n";
            foreach ($items as $item) {
                $mailBody .= '- ' . (int) $item['quantity'] . ' x ' . display_name((string) $item['label']) . ' - ' . money_value($item['unit_price']) . "\n";
            }
            send_store_mail(SX_STORE_EMAIL, 'Nuevo pedido ' . $orderCode, $mailBody);
            if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                send_store_mail($customerEmail, 'Recibimos tu pedido ' . $orderCode, "Hola {$customerName},\n\nRecibimos tu pedido {$orderCode}. Station X Tulua confirmara disponibilidad, pago y entrega.\n\nTotal estimado: " . money_value($total));
            }

            flash_redirect('Pedido ' . $orderCode . ' creado. Te contactaremos para confirmar pago, stock y entrega.', '/cuenta.php');
        }
    }

    $currentUser = $_SESSION['sx_user'] ?? null;
    $shippingRates = fetch_shipping_rates($pdo, $shippingRates);
    $paymentMethods = fetch_payment_methods($pdo, $paymentMethods);
    $products = fetch_products($pdo, $prefix, $entity);
    $erpCategories = fetch_erp_categories($pdo, $prefix, $entity);
$featured = array_slice($products, 0, 8);
$cartItems = fetch_cart_items($pdo, $prefix, load_cart($pdo, $currentUser));
$favoriteIds = fetch_favorite_ids($pdo, $currentUser);
$userOrders = fetch_user_orders($pdo, $currentUser);
} catch (Throwable $error) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $loadError = $error->getMessage();
}

$cartCount = array_sum(array_map(static fn($item) => (int) $item['quantity'], $cartItems));
$cartTotal = array_sum(array_map(static fn($item) => (float) $item['unit_price'] * (int) $item['quantity'], $cartItems));
$categoryLabels = array_values(array_filter(array_map(static fn($category) => display_name((string) ($category['label'] ?? '')), $erpCategories)));
$categories = array_merge(['Todo'], $categoryLabels ?: ['Consolas', 'Videojuegos', 'Accesorios', 'Coleccionables', 'Gaming']);
$catalogPreview = array_slice($products, 0, 96);
$recentProducts = array_slice($products, 0, 12);
$offerProducts = array_values(array_filter($products, static fn($product) => product_discount_percent($product) > 0));
$consultedProducts = $products;
usort($consultedProducts, static fn($a, $b) => ((int) ($b['question_count'] ?? 0) <=> (int) ($a['question_count'] ?? 0)) ?: ((float) ($b['stock'] ?? 0) <=> (float) ($a['stock'] ?? 0)));
$recommendedProducts = $products;
usort($recommendedProducts, static fn($a, $b) => ((float) ($b['avg_rating'] ?? 0) <=> (float) ($a['avg_rating'] ?? 0)) ?: ((int) $b['rowid'] <=> (int) $a['rowid']));
$cartCategories = array_values(array_unique(array_map(static fn($item) => product_category($item), $cartItems)));
$cartBasedProducts = $cartCategories ? array_values(array_filter($products, static fn($product) => in_array(product_category($product), $cartCategories, true))) : array_slice($products, 8, 12);
$searchSuggestions = ['Nintendo Switch', 'PlayStation', 'Xbox', 'Controles', 'Videojuegos', 'Accesorios', 'Servicio tecnico', 'Pikachu'];
foreach (array_slice($products, 0, 80) as $suggestionProduct) {
    $searchSuggestions[] = display_name((string) $suggestionProduct['label']);
    $searchSuggestions[] = display_name((string) $suggestionProduct['ref']);
}
$searchSuggestions = array_slice(array_values(array_unique(array_filter($searchSuggestions))), 0, 100);
$heroProducts = [];
foreach ($products as $heroProduct) {
    $heroImage = product_image_url((string) $heroProduct['ref'], 1200);
    if ($heroImage) {
        $heroProducts[] = ['product' => $heroProduct, 'image' => $heroImage];
    }
    if (count($heroProducts) >= 6) {
        break;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Station X Tulua | Marketplace Gamer</title>
    <meta name="description" content="Marketplace gamer de Station X Tulua con consolas, videojuegos, accesorios y servicio tecnico.">
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
</head>
<body class="meli-look">
    <header class="topbar">
        <a class="brand" href="/">
            <?php if ($logo = public_upload('logo-transparent') ?? public_upload('logo')): ?>
                <img class="brand-logo" src="<?php echo e($logo); ?>" alt="Station X Tulua">
            <?php else: ?>
                <span class="brand-mark">SX</span>
            <?php endif; ?>
            <span>
                <strong>Station X Tulua</strong>
                <small>Marketplace gamer</small>
            </span>
        </a>
        <form class="header-search" action="/buscar.php" method="get">
            <input type="search" name="q" list="store-search-suggestions" placeholder="Buscar consolas, juegos y accesorios" data-search>
            <button type="submit" aria-label="Buscar">&#128269;</button>
            <datalist id="store-search-suggestions">
                <?php foreach ($searchSuggestions as $suggestion): ?><option value="<?php echo e($suggestion); ?>"><?php endforeach; ?>
            </datalist>
        </form>
        <nav class="nav">
            <a href="/categoria.php">Categorias</a>
            <a href="/recientes.php">Recientes</a>
            <a href="/ayuda.php">Ayuda</a>
            <a href="/perfil.php">Mi perfil</a>
            <div class="mini-cart">
                <a class="cart-toggle" href="/carrito.php">Carrito <span><?php echo (int) $cartCount; ?></span></a>
                <div class="mini-cart-panel">
                    <?php if ($cartItems): ?>
                        <strong>Tu carrito</strong>
                        <?php foreach (array_slice($cartItems, 0, 3) as $item): ?>
                            <?php $miniImage = product_image_url((string) $item['ref'], 96); ?>
                            <a class="mini-cart-line" href="<?php echo e(product_url($item)); ?>">
                                <?php if ($miniImage): ?><img src="<?php echo e($miniImage); ?>" alt="<?php echo e(display_name((string) $item['label'])); ?>"><?php endif; ?>
                                <span><?php echo e(display_name((string) $item['label'])); ?><small><?php echo (int) $item['quantity']; ?> x <?php echo e(money_value($item['unit_price'])); ?></small></span>
                            </a>
                        <?php endforeach; ?>
                        <div class="mini-cart-total"><span>Total</span><strong><?php echo e(money_value($cartTotal)); ?></strong></div>
                        <a class="mini-cart-action" href="/carrito.php">Ir al carrito</a>
                    <?php else: ?>
                        <strong>Tu carrito esta vacio</strong>
                        <p>Agrega productos y revisalos sin salir del catalogo.</p>
                        <a class="mini-cart-action" href="#catalogo">Ver productos</a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <div class="delivery-note">
            <strong>Enviar a Tulua</strong>
            <span>Retiro en tienda, domicilio local y envio nacional a confirmar</span>
        </div>
        <div class="header-links">
            <a href="/pedidos.php">Mis pedidos</a>
            <a href="/favoritos.php">Favoritos</a>
            <a href="/busquedas.php">Mis busquedas</a>
            <a href="/compras.php">Mis compras</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash"><?php echo e($flash); ?></div>
    <?php endif; ?>

    <main>
        <?php if (($_GET['pago'] ?? '') === 'exitoso'): ?>
            <section class="notice success"><strong>Pago registrado.</strong><span>Gracias. Station X validara el pago y actualizara tu pedido.</span></section>
        <?php elseif (($_GET['pago'] ?? '') === 'fallido'): ?>
            <section class="notice danger"><strong>Pago no completado.</strong><span>Puedes intentar de nuevo o confirmar por WhatsApp.</span></section>
        <?php endif; ?>

        <section class="hero">
            <button class="hero-arrow hero-arrow-left" type="button" aria-label="Promocion anterior">&lsaquo;</button>
            <button class="hero-arrow hero-arrow-right" type="button" aria-label="Promocion siguiente">&rsaquo;</button>
            <?php if ($banner = public_upload('banner')): ?>
                <img class="hero-banner" src="<?php echo e($banner); ?>" alt="Station X Tulua">
            <?php endif; ?>
            <?php if ($heroProducts): ?>
                <div class="hero-product-slides" aria-hidden="true">
                    <?php foreach ($heroProducts as $index => $heroItem): ?>
                        <figure class="<?php echo $index === 0 ? 'is-active' : ''; ?>" data-hero-slide>
                            <img src="<?php echo e($heroItem['image']); ?>" alt="">
                            <figcaption><?php echo e(display_name((string) $heroItem['product']['label'])); ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="hero-copy">
                <p class="eyebrow">Venta, soporte y garantia</p>
                <h1>Tu tienda gamer en Tulua, ahora con compra online.</h1>
                <p>Consolas, videojuegos, controles, accesorios, coleccionables y servicio tecnico confiable.</p>
                <div class="hero-actions">
                    <a class="button primary" href="#catalogo">Comprar ahora</a>
                    <a class="button ghost" href="https://wa.me/<?php echo e($whatsappNumber); ?>?text=Hola%20Station%20X%20Tulua%2C%20quiero%20comprar%20en%20la%20tienda" target="_blank" rel="noopener">WhatsApp</a>
                </div>
            </div>
            <div class="hero-card">
                <strong><?php echo count($products); ?></strong>
                <span>Cientos de productos a tu disposici&oacute;n</span>
            </div>
            <div class="hero-dots" aria-hidden="true">
                <?php for ($i = 0, $count = max(1, count($heroProducts)); $i < min(6, $count); $i++): ?><span class="<?php echo $i === 0 ? 'is-active' : ''; ?>"></span><?php endfor; ?>
            </div>
        </section>

        <?php if ($products): ?>
            <section class="meli-floating-row" aria-label="Recomendaciones rapidas">
                <article class="meli-home-card">
                    <h2>Compra tu carrito</h2>
                    <div class="meli-card-stack">
                        <?php foreach (array_slice($cartItems ?: $featured, 0, 3) as $item): ?>
                            <?php $cardImage = product_image_url((string) $item['ref'], 160); ?>
                            <?php if ($cardImage): ?><img src="<?php echo e($cardImage); ?>" alt="<?php echo e(display_name((string) $item['label'])); ?>" loading="lazy"><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <a href="/carrito.php"><?php echo $cartItems ? 'Ir al carrito' : 'Armar carrito'; ?></a>
                </article>
                <?php foreach (array_slice($products, 0, 5) as $product): ?>
                    <?php
                    $name = display_name((string) $product['label']);
                    $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'];
                    $image = product_image_url((string) $product['ref'], 220);
                    ?>
                    <a class="meli-home-card product-mini" href="<?php echo e(product_url($product)); ?>">
                        <h2><?php echo e(product_category($product)); ?></h2>
                        <span class="mini-product-image"><?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy"><?php endif; ?></span>
                        <strong><?php echo e($name); ?></strong>
                        <small><?php echo e(money_value($price)); ?></small>
                        <em>Envio a confirmar</em>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="trustbar">
            <article><img class="trust-icon" src="/assets/icon-shipping.svg" alt="" loading="lazy"><div><strong>Envios nacionales</strong><span>Compra desde cualquier ciudad.</span></div></article>
            <article><img class="trust-icon" src="/assets/icon-shield.svg" alt="" loading="lazy"><div><strong>Garantia local</strong><span>Servicio confiable y con soporte.</span></div></article>
            <article><img class="trust-icon" src="/assets/icon-store.svg" alt="" loading="lazy"><div><strong>Retiro en tienda</strong><span>Carrera 25 #28-37, Tulua.</span></div></article>
            <article><img class="trust-icon" src="/assets/icon-social.svg" alt="" loading="lazy"><div><strong>Redes activas</strong><span>Instagram, Facebook y TikTok oficiales.</span></div></article>
        </section>

        <section class="sx-benefits-band" aria-label="Beneficios de compra">
            <article>
                <img src="/assets/icon-coupon.svg" alt="" loading="lazy">
                <div><strong>Ofertas gamer</strong><span>Promociones, combos y productos destacados desde el inventario real.</span></div>
            </article>
            <article>
                <img src="/assets/icon-card.svg" alt="" loading="lazy">
                <div><strong>Compra guiada</strong><span>Agrega al carrito y confirma disponibilidad con Station X.</span></div>
            </article>
            <article>
                <img src="/assets/icon-return.svg" alt="" loading="lazy">
                <div><strong>Soporte local</strong><span>Asesoria, garantia y retiro en tienda en Tulua.</span></div>
            </article>
        </section>

        <section class="sx-shortcuts" aria-label="Accesos rapidos">
            <a href="/buscar.php?sort=discount">Ofertas</a>
            <a href="/buscar.php?availability=available">Disponibles</a>
            <a href="/buscar.php?sort=rating">Mejor calificados</a>
            <a href="/buscar.php?sort=stock">Mayor disponibilidad</a>
            <a href="/categoria.php">Categorias</a>
            <a href="/favoritos.php">Favoritos</a>
        </section>

        <section class="market-shelf recent-viewed-module" data-recent-viewed hidden>
            <div class="section-title">
                <h2>Tu historial</h2>
                <a href="/busquedas.php">Ir a mi historial de navegacion</a>
            </div>
            <div class="product-rail recent-viewed-track" data-recent-viewed-track></div>
        </section>

        <?php
        $homeRails = [
            'Recien llegados' => ['items' => $recentProducts, 'link' => '/recientes.php'],
            'Mas consultados' => ['items' => $consultedProducts, 'link' => '/buscar.php?sort=questions'],
            'Recomendados para ti' => ['items' => $recommendedProducts, 'link' => '/buscar.php?sort=rating'],
            'Basado en tu carrito' => ['items' => $cartBasedProducts, 'link' => '/carrito.php'],
        ];
        if ($offerProducts) {
            $homeRails = ['Ofertas destacadas' => ['items' => $offerProducts, 'link' => '/buscar.php?sort=discount']] + $homeRails;
        }
        ?>
        <?php foreach ($homeRails as $railTitle => $railData): ?>
            <?php $railItems = array_slice(array_values($railData['items']), 0, 10); if (!$railItems) { continue; } ?>
            <section class="market-shelf home-product-rail">
                <div class="section-title">
                    <h2><?php echo e($railTitle); ?></h2>
                    <a href="<?php echo e($railData['link']); ?>">Ver mas</a>
                </div>
                <div class="product-rail">
                    <?php foreach ($railItems as $product): ?>
                        <?php
                        $name = display_name((string) $product['label']);
                        $price = product_effective_price($product);
                        $base = product_base_price($product);
                        $discount = product_discount_percent($product);
                        $image = product_image_url((string) $product['ref'], 260);
                        ?>
                        <a class="shelf-card" href="<?php echo e(product_url($product)); ?>">
                            <?php if ($discount > 0): ?><span class="deal-badge"><?php echo $discount; ?>% OFF</span><?php else: ?><span class="deal-badge"><?php echo e(product_category($product)); ?></span><?php endif; ?>
                            <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy"><?php else: ?><span class="recent-fallback">SX</span><?php endif; ?>
                            <strong><?php echo e($name); ?></strong>
                            <?php if ($discount > 0): ?><span class="old-price"><?php echo e(money_value($base)); ?></span><?php endif; ?>
                            <small><?php echo e(money_value($price)); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="visual-services">
            <article>
                <span>01</span>
                <strong>Consolas listas para jugar</strong>
                <p>Equipos nuevos y usados revisados, con asesoria antes de comprar.</p>
            </article>
            <article>
                <span>02</span>
                <strong>Videojuegos y accesorios</strong>
                <p>Catalogo gamer conectado al inventario real de Station X Tulua.</p>
            </article>
            <article>
                <span>03</span>
                <strong>Servicio tecnico</strong>
                <p>Mantenimiento, diagnostico, reparacion y soporte local.</p>
            </article>
        </section>

        <section class="social-strip">
            <div>
                <strong>Station X en redes</strong>
                <span>Promos, novedades, liquidaciones y contenido gamer.</span>
            </div>
            <a href="https://www.instagram.com/stationxtulua/?hl=es" target="_blank" rel="noopener">Instagram</a>
            <a href="https://www.facebook.com/stationxtulua1/?locale=es_LA" target="_blank" rel="noopener">Facebook</a>
            <a href="https://www.tiktok.com/@stationxtulua" target="_blank" rel="noopener">TikTok</a>
        </section>

        <section class="promo-wall">
            <div>
                <span>Compra segura</span>
                <h2>Elige online y confirma con un asesor.</h2>
                <p>El pedido queda registrado, Station X valida disponibilidad, metodo de pago y entrega antes de cerrar la venta.</p>
            </div>
            <div class="promo-metrics">
                <strong><?php echo count($products); ?></strong><span>productos</span>
                <strong><?php echo count($paymentMethods); ?></strong><span>metodos de pago</span>
                <strong><?php echo count($shippingRates); ?></strong><span>opciones de entrega</span>
            </div>
        </section>

        <section class="categories" aria-label="Categorias">
            <?php foreach ($categories as $category): ?>
                <button type="button" data-category="<?php echo e($category); ?>" class="<?php echo $category === 'Todo' ? 'active' : ''; ?>"><?php echo e($category); ?></button>
            <?php endforeach; ?>
        </section>

        <section class="market-bar" id="carrito">
            <div class="market-bar-item">
                <span>Cuenta</span>
                <?php if ($currentUser): ?>
                    <strong>Hola, <?php echo e($currentUser['name']); ?></strong>
                    <a href="/perfil.php">Ver perfil</a>
                <?php else: ?>
                    <strong>Compra mas rapido</strong>
                    <a href="/cuenta.php">Entrar o registrarme</a>
                <?php endif; ?>
            </div>
            <div class="market-bar-item">
                <span>Carrito</span>
                <strong><?php echo (int) $cartCount; ?> producto(s)</strong>
                <a href="/carrito.php">Ver carrito</a>
            </div>
            <div class="market-bar-item">
                <span>Entrega</span>
                <strong>Retiro, domicilio o envio</strong>
                <a href="/pedidos.php">Mis pedidos</a>
            </div>
            <div class="market-bar-item">
                <span>Soporte</span>
                <strong>Asesoria por WhatsApp</strong>
                <a href="https://wa.me/<?php echo e($whatsappNumber); ?>?text=<?php echo e($cartItems ? cart_whatsapp_text($cartItems, (float) $cartTotal) : rawurlencode('Hola Station X Tulua, quiero comprar en la tienda online.')); ?>" target="_blank" rel="noopener">Contactar</a>
            </div>
        </section>

        <?php if ($products): ?>
            <section class="category-showcase department-carousel" aria-label="Compra por departamento">
                <div class="section-title carousel-title">
                    <div>
                        <span class="section-kicker">Categorias Dolibarr</span>
                        <h2>Compra por departamento</h2>
                        <p>Explora las categorias reales del ERP y entra directo a sus productos.</p>
                    </div>
                    <div class="carousel-controls" aria-label="Controles de categorias">
                        <button type="button" data-carousel-prev aria-label="Categoria anterior">&lsaquo;</button>
                        <button type="button" data-carousel-next aria-label="Categoria siguiente">&rsaquo;</button>
                    </div>
                </div>
                <div class="department-carousel-shell">
                    <div class="department-grid" data-department-carousel>
                    <?php foreach (($erpCategories ?: array_map(static fn($label) => ['label' => $label, 'product_count' => 0, 'sample_ref' => null], array_slice($categories, 1))) as $categoryRow): ?>
                        <?php
                        $category = display_name((string) ($categoryRow['label'] ?? 'Categoria'));
                        $deptImage = !empty($categoryRow['sample_ref']) ? product_image_url((string) $categoryRow['sample_ref'], 260) : null;
                        if (!$deptImage) {
                            foreach ($products as $candidate) {
                                if (product_matches_category($candidate, $category)) {
                                    $deptImage = product_image_url((string) $candidate['ref'], 260);
                                    break;
                                }
                            }
                        }
                        ?>
                        <a class="department-link" href="/categoria.php?c=<?php echo e(rawurlencode($category)); ?>">
                            <span class="department-image">
                                <?php if ($deptImage): ?><img src="<?php echo e($deptImage); ?>" alt="<?php echo e($category); ?>" loading="lazy"><?php else: ?><b>SX</b><?php endif; ?>
                            </span>
                            <small><?php echo (int) ($categoryRow['product_count'] ?? 0); ?> productos</small>
                            <strong><?php echo e($category); ?></strong>
                            <span>Ver categoria</span>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="market-shelf">
                <div class="section-title">
                    <h2>Ofertas y destacados</h2>
                    <p>Vitrina visual para descubrir productos sin bajar hasta todo el catalogo.</p>
                </div>
                <div class="shelf-track">
                    <?php foreach (array_slice($products, 0, 12) as $product): ?>
                        <?php
                        $name = display_name((string) $product['label']);
                        $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'];
                        $image = product_image_url((string) $product['ref'], 260);
                        ?>
                        <a class="shelf-card" href="<?php echo e(product_url($product)); ?>">
                            <span class="deal-badge">Destacado</span>
                            <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy"><?php endif; ?>
                            <strong><?php echo e($name); ?></strong>
                            <small><?php echo e(money_value($price)); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php foreach (array_slice($categoryLabels ?: ['Consolas', 'Videojuegos', 'Accesorios', 'Coleccionables'], 0, 6) as $shelfCategory): ?>
                <?php
                $shelfProducts = array_values(array_filter($products, static fn($item) => product_matches_category($item, $shelfCategory)));
                if (!$shelfProducts) {
                    continue;
                }
                ?>
                <section class="category-shelf">
                    <div class="section-title">
                        <h2><?php echo e($shelfCategory); ?></h2>
                        <p>Seleccion destacada para comprar rapido.</p>
                    </div>
                    <div class="shelf-track">
                        <?php foreach (array_slice($shelfProducts, 0, 10) as $product): ?>
                            <?php
                            $name = display_name((string) $product['label']);
                            $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'];
                            $image = product_image_url((string) $product['ref'], 260);
                            ?>
                            <a class="shelf-card" href="<?php echo e(product_url($product)); ?>">
                                <span class="deal-badge"><?php echo e($shelfCategory); ?></span>
                                <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy"><?php endif; ?>
                                <strong><?php echo e($name); ?></strong>
                                <small><?php echo e(money_value($price)); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <a class="section-more" href="/categoria.php?c=<?php echo e(rawurlencode($shelfCategory)); ?>">Ver todo en <?php echo e($shelfCategory); ?></a>
                </section>
            <?php endforeach; ?>

            <section class="storefront-modules">
                <article>
                    <strong>Compra con asesoria</strong>
                    <p>Pregunta antes de comprar y recibe recomendacion segun consola, presupuesto o uso.</p>
                    <a href="https://wa.me/<?php echo e($whatsappNumber); ?>?text=Hola%20Station%20X%20Tulua%2C%20necesito%20asesoria" target="_blank" rel="noopener">Hablar con asesor</a>
                </article>
                <article>
                    <strong>Garantia y soporte</strong>
                    <p>Productos nuevos, usados y servicios se validan con condiciones claras antes de entregar.</p>
                    <a href="/legal.php?seccion=garantia">Ver garantia</a>
                </article>
                <article>
                    <strong>Retiro y envios</strong>
                    <p>Retiro en tienda, domicilio local y envio nacional segun disponibilidad del producto.</p>
                    <a href="/carrito.php">Ver carrito</a>
                </article>
            </section>

            <section class="help-hub">
                <div>
                    <h2>Centro de ayuda Station X</h2>
                    <p>Resuelve dudas frecuentes antes de comprar.</p>
                </div>
                <a href="/buscar.php?q=control">Controles</a>
                <a href="/buscar.php?q=nintendo">Nintendo</a>
                <a href="/buscar.php?q=playstation">PlayStation</a>
                <a href="/buscar.php?q=servicio">Servicio tecnico</a>
                <a href="/ayuda.php">Ayuda</a>
            </section>
            <section class="newsletter-band">
                <div>
                    <h2>Recibe novedades gamer</h2>
                    <p>Promos, nuevos ingresos, consolas y accesorios destacados.</p>
                </div>
                <form method="post" action="/newsletter.php">
                    <?php echo csrf_input(); ?>
                    <input name="name" placeholder="Nombre">
                    <input type="email" name="email" placeholder="Correo">
                    <input name="phone" placeholder="WhatsApp">
                    <input type="hidden" name="interest" value="Promociones tienda online">
                    <button type="submit">Quiero novedades</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($loadError !== null): ?>
            <section class="notice">
                <strong>No se pudo cargar la tienda.</strong>
                <span><?php echo e($loadError); ?></span>
            </section>
        <?php else: ?>
<section class="deal-row" id="ofertas">
                <div class="section-title">
                    <h2>Recomendados</h2>
                    <p>Productos recientes del inventario.</p>
                </div>
                <div class="mini-grid">
                    <?php foreach ($featured as $product): ?>
                        <?php
                        $name = display_name((string) $product['label']);
                        $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'];
                        $image = product_image_url((string) $product['ref'], 220);
                        $url = product_url($product);
                        ?>
                        <a class="mini-card" href="<?php echo e($url); ?>">
                            <div>
                                <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy"><?php endif; ?>
                            </div>
                            <strong><?php echo e($name); ?></strong>
                            <span><?php echo e(money_value($price)); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="catalog-head" id="catalogo">
                <div class="section-title">
                    <h2>Vista rapida del catalogo</h2>
                    <p><span data-results-count><?php echo count($catalogPreview); ?></span> destacados de <?php echo count($products); ?> productos conectados.</p>
                </div>
            </section>
            <section class="quick-filter-chips" aria-label="Filtros rapidos">
                <a href="/buscar.php?max=50000">Hasta $50.000</a>
                <a href="/buscar.php?min=50000&max=150000">$50.000 a $150.000</a>
                <a href="/buscar.php?min=150000">Mas de $150.000</a>
                <?php foreach (array_slice(array_slice($categories, 1), 0, 4) as $chipCategory): ?><a href="/buscar.php?c=<?php echo e(rawurlencode($chipCategory)); ?>"><?php echo e($chipCategory); ?></a><?php endforeach; ?>
            </section>

            <?php if (!$products): ?>
                <section class="notice">
                    <strong>No hay productos publicados.</strong>
                    <span>Marca productos como vendibles en el sistema administrativo.</span>
                </section>
            <?php else: ?>
                <section class="catalog-layout">
                    <aside class="catalog-filters" aria-label="Filtros del catalogo">
                        <strong>Filtros</strong>
                        <label>Precio minimo<input type="number" min="0" placeholder="$0" data-min-price></label>
                        <label>Precio maximo<input type="number" min="0" placeholder="$999.999" data-max-price></label>
                        <label class="check-filter"><input type="checkbox" data-stock-only> Solo disponibles</label>
                        <label>Ordenar
                            <select data-sort aria-label="Ordenar productos">
                                <option value="recent">Mas recientes</option>
                                <option value="price_asc">Menor precio</option>
                                <option value="price_desc">Mayor precio</option>
                                <option value="name">Nombre</option>
                            </select>
                        </label>
                    </aside>
                    <div class="catalog-main">
                        <section class="grid" data-product-grid>
                            <?php foreach ($catalogPreview as $product): ?>
                                <?php
                                $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'];
                                $name = display_name((string) $product['label']);
                                $ref = display_name((string) $product['ref']);
                                $description = trim(strip_tags((string) ($product['description'] ?? '')));
                                $category = product_category($product);
                                $image = product_image_url((string) $product['ref'], 420);
                                $url = product_url($product);
                                $allCategories = (string) ($product['erp_categories'] ?? $category);
                                $search = strtolower(($product['ref'] ?? '') . ' ' . $name . ' ' . $description . ' ' . $allCategories . ' ' . $category);
                                ?>
                                <article class="product" data-product-card data-category="<?php echo e($category); ?>" data-categories="<?php echo e(strtolower($allCategories)); ?>" data-search-text="<?php echo e($search); ?>" data-name="<?php echo e($name); ?>" data-price="<?php echo e((string) ((float) $price)); ?>" data-stock="<?php echo e((string) ((float) ($product['stock'] ?? 0))); ?>">
                                    <a class="product-media" href="<?php echo e($url); ?>">
                                        <?php if ($image): ?>
                                            <img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy">
                                        <?php else: ?>
                                            <span><?php echo e(substr($name, 0, 2)); ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <div class="product-body">
                                        <span class="pill"><?php echo e($category); ?></span>
                                        <h3><a href="<?php echo e($url); ?>"><?php echo e($name); ?></a></h3>
                                        <p><?php echo e($description !== '' ? display_name($description) : $ref); ?></p>
                                        <div class="product-signals">
                                            <span><?php echo (float) ($product['avg_rating'] ?? 0) > 0 ? e(number_format((float) $product['avg_rating'], 1) . '/5 (' . (int) ($product['review_count'] ?? 0) . ')') : 'Nuevo'; ?></span>
                                            <span><?php echo (int) ($product['question_count'] ?? 0); ?> preguntas</span>
                                        </div>
                                    </div>
                                    <form class="product-footer" method="post">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="add_cart">
                                        <input type="hidden" name="product_id" value="<?php echo e((string) $product['rowid']); ?>">
                                        <div>
                                            <strong><?php echo e(money_value($price)); ?></strong>
                                            <?php if ($product['stock'] !== null): ?>
                                                <small><?php echo (float) $product['stock'] > 0 ? 'Disponible' : 'Consultar disponibilidad'; ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <button type="submit">Agregar</button>
                                    </form>
                                    <form class="favorite-form" method="post">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="toggle_favorite">
                                        <input type="hidden" name="product_id" value="<?php echo e((string) $product['rowid']); ?>">
                                        <button type="submit" aria-label="Favorito"><?php echo in_array((int) $product['rowid'], $favoriteIds, true) ? 'Favorito' : 'Guardar'; ?></button>
                                    </form>
                                    <form class="compare-form" method="get" action="/comparar.php">
                                        <input type="hidden" name="add" value="<?php echo e((string) $product['rowid']); ?>">
                                        <button type="submit">Comparar</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </section>
                        <button class="load-more" type="button" data-load-more>Ver mas productos</button>
                    </div>
                </section>
            <?php endif; ?>


            <section class="visual-services service-deep">
                <article>
                    <span>Pago</span>
                    <strong>Metodos configurables</strong>
                    <p>Transferencia, Nequi, pago en tienda y pasarela externa cuando se activen credenciales.</p>
                </article>
                <article>
                    <span>Envio</span>
                    <strong>Tarifas editables</strong>
                    <p>Retiro, domicilio local, Valle del Cauca y nacional desde el panel administrador.</p>
                </article>
                <article>
                    <span>Stock</span>
                    <strong>Control con Dolibarr</strong>
                    <p>El panel permite confirmar pedidos y descontar inventario manualmente con registro anti-duplicado.</p>
                </article>
            </section>

            <section class="policies">
                <article><strong>Pagos</strong><span>La tienda crea el pedido y Station X confirma disponibilidad antes de recibir el pago final.</span></article>
                <article><strong>Envios</strong><span>Retiro en tienda, domicilio local y envio nacional segun disponibilidad del producto.</span></article>
                <article><strong>Garantia</strong><span>La garantia depende del tipo de producto: nuevo, usado, accesorio o servicio tecnico.</span></article>
                <article><strong>Privacidad</strong><span>Los datos se usan solo para crear cuenta, guardar carrito y gestionar pedidos.</span></article>
            </section>
        <?php endif; ?>
    </main>

    <footer class="footer professional-footer">
        <div>
            <strong>Station X Tulua</strong>
            <span>Consolas, videojuegos, accesorios, controles, reparacion y mantenimiento gamer.</span>
        </div>
        <div>
            <strong>Comprar</strong>
            <a href="/categoria.php">Categorias</a>
            <a href="/recientes.php">Mas recientes</a>
            <a href="/favoritos.php">Favoritos</a>
            <a href="/comparar.php">Comparador</a>
            <a href="/historial.php">Historial</a>
        </div>
        <div>
            <strong>Mi cuenta</strong>
            <a href="/cuenta.php">Entrar o registrarme</a>
            <a href="/perfil.php">Mi perfil</a>
            <a href="/pedidos.php">Mis pedidos</a>
            <a href="/busquedas.php">Mis busquedas</a>
        </div>
        <div>
            <strong>Soporte</strong>
            <a href="/ayuda.php">Ayuda y preguntas</a>
            <a href="/sobre.php">Sobre Station X</a>
            <a href="/legal.php?seccion=garantia">Garantia</a>
            <a href="/legal.php?seccion=privacidad">Privacidad</a>
        </div>
        <div>
            <strong>Redes</strong>
            <a href="https://www.instagram.com/stationxtulua/?hl=es" target="_blank" rel="noopener">Instagram</a>
            <a href="https://www.facebook.com/stationxtulua1/?locale=es_LA" target="_blank" rel="noopener">Facebook</a>
            <a href="https://www.tiktok.com/@stationxtulua" target="_blank" rel="noopener">TikTok</a>
            <a href="/htdocs/">Administracion</a>
        </div>
    </footer>

    <a class="floating-whatsapp" href="https://wa.me/<?php echo e($whatsappNumber); ?>?text=<?php echo e($cartItems ? cart_whatsapp_text($cartItems, (float) $cartTotal) : rawurlencode('Hola Station X Tulua, quiero comprar en la tienda online.')); ?>" target="_blank" rel="noopener">WhatsApp</a>
    <div class="cookie-note" data-cookie-note hidden><span>Usamos cookies para recordar busquedas, textos pendientes y productos vistos en este navegador.</span><button type="button" data-cookie-accept>Aceptar</button></div>
    <script src="/assets/store.js?v=20261014-guest-history" defer></script>

    <script>
    const searchInputs = document.querySelectorAll('[data-search]');
    const cards = [...document.querySelectorAll('[data-product-card]')];
    const categoryButtons = document.querySelectorAll('[data-category]');
    const sort = document.querySelector('[data-sort]');
    const grid = document.querySelector('[data-product-grid]');
    const minPrice = document.querySelector('[data-min-price]');
    const maxPrice = document.querySelector('[data-max-price]');
    const stockOnly = document.querySelector('[data-stock-only]');
    const shippingRate = document.querySelector('[data-shipping-rate]');
    const shippingCopy = document.querySelector('[data-shipping-copy]');
    const resultsCount = document.querySelector('[data-results-count]');
    const loadMore = document.querySelector('[data-load-more]');
    const departmentCarousel = document.querySelector('[data-department-carousel]');
    const departmentPrev = document.querySelector('[data-carousel-prev]');
    const departmentNext = document.querySelector('[data-carousel-next]');
    let activeCategory = 'Todo';
    let query = '';
    let visibleLimit = 24;

    function applyFilters(resetLimit = false) {
        if (resetLimit) visibleLimit = 24;
        const min = Number(minPrice?.value || 0);
        const max = Number(maxPrice?.value || 0);
        const onlyStock = Boolean(stockOnly?.checked);
        const matches = cards.filter((card) => {
            const price = Number(card.dataset.price || 0);
            const stock = Number(card.dataset.stock || 0);
            const byText = query === '' || card.dataset.searchText.includes(query);
            const categories = (card.dataset.categories || card.dataset.category || '').toLowerCase();
            const byCategory = activeCategory === 'Todo' || categories.split(',').map((item) => item.trim()).includes(activeCategory.toLowerCase()) || card.dataset.category === activeCategory;
            const byPrice = (!min || price >= min) && (!max || price <= max);
            const byStock = !onlyStock || stock > 0;
            return byText && byCategory && byPrice && byStock;
        });
        cards.forEach((card) => {
            const index = matches.indexOf(card);
            card.hidden = index === -1 || index >= visibleLimit;
        });
        if (resultsCount) resultsCount.textContent = matches.length;
        if (loadMore) loadMore.hidden = matches.length <= visibleLimit;
    }

    searchInputs.forEach((input) => {
        input.addEventListener('input', (event) => {
            query = event.target.value.trim().toLowerCase();
            searchInputs.forEach((other) => {
                if (other !== input) other.value = event.target.value;
            });
            applyFilters(true);
        });
    });

    categoryButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeCategory = button.dataset.category;
            categoryButtons.forEach((item) => item.classList.toggle('active', item === button));
            applyFilters(true);
        });
    });

    if (sort && grid) {
        sort.addEventListener('change', () => {
            const sorted = [...cards].sort((a, b) => {
                if (sort.value === 'price_asc') return Number(a.dataset.price) - Number(b.dataset.price);
                if (sort.value === 'price_desc') return Number(b.dataset.price) - Number(a.dataset.price);
                if (sort.value === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                return 0;
            });
            sorted.forEach((card) => grid.appendChild(card));
            applyFilters(true);
        });
    }

    [minPrice, maxPrice, stockOnly].forEach((control) => {
        if (control) control.addEventListener('input', () => applyFilters(true));
        if (control) control.addEventListener('change', () => applyFilters(true));
    });

    if (loadMore) {
        loadMore.addEventListener('click', () => {
            visibleLimit += 24;
            applyFilters();
        });
    }

    if (departmentCarousel) {
        const moveDepartments = (direction) => {
            const firstCard = departmentCarousel.querySelector('.department-link');
            const step = firstCard ? firstCard.getBoundingClientRect().width + 14 : 260;
            departmentCarousel.scrollBy({ left: step * direction, behavior: 'smooth' });
        };
        departmentPrev?.addEventListener('click', () => moveDepartments(-1));
        departmentNext?.addEventListener('click', () => moveDepartments(1));
    }

    const heroSlides = [...document.querySelectorAll('[data-hero-slide]')];
    const heroDots = [...document.querySelectorAll('.hero-dots span')];
    let heroIndex = 0;
    function showHeroSlide(index) {
        if (!heroSlides.length) return;
        heroIndex = (index + heroSlides.length) % heroSlides.length;
        heroSlides.forEach((slide, currentIndex) => slide.classList.toggle('is-active', currentIndex === heroIndex));
        heroDots.forEach((dot, currentIndex) => dot.classList.toggle('is-active', currentIndex === heroIndex));
    }
    document.querySelector('.hero-arrow-left')?.addEventListener('click', () => showHeroSlide(heroIndex - 1));
    document.querySelector('.hero-arrow-right')?.addEventListener('click', () => showHeroSlide(heroIndex + 1));
    if (heroSlides.length > 1) {
        window.setInterval(() => showHeroSlide(heroIndex + 1), 6000);
    }

    if (shippingRate && shippingCopy) {
        shippingRate.addEventListener('change', () => {
            const value = Number(shippingRate.value || 0);
            shippingCopy.textContent = value > 0 ? `Envio estimado: ${new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value)}. Se confirma antes del pago.` : 'Retiro en tienda sin costo de envio.';
        });
    }

    applyFilters();

    </script>
<?php echo tracking_scripts(); ?>
</body>
</html>


