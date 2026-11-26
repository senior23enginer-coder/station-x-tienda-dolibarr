<?php
declare(strict_types=1);

require_once __DIR__ . '/store-private.php';

function sx_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['sx_csrf'])) {
        $_SESSION['sx_csrf'] = bin2hex(random_bytes(32));
    }
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
    return '$' . number_format((float) $value, 0, ',', '.');
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

function display_name(string $value): string
{
    $clean = trim(preg_replace('/[_\s]+/', ' ', strip_tags($value)));
    return $clean === '' ? 'Producto' : ucwords(strtolower($clean));
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

function product_image_url(string $ref, int $width = 0): ?string
{
    $images = product_image_urls($ref, 1, $width);
    return $images[0] ?? null;
}

function product_filter_text(array $product): string
{
    return strtolower(display_name(trim((string) ($product['ref'] ?? '') . ' ' . (string) ($product['label'] ?? '') . ' ' . (string) ($product['description'] ?? '') . ' ' . (string) ($product['erp_categories'] ?? '') . ' ' . product_category($product))));
}

function normalize_store_search(string $query): string
{
    $query = strtolower(trim($query));
    $query = preg_replace('/\s+/', ' ', $query) ?: '';
    $aliases = [
        'x box' => 'xbox',
        'play station' => 'playstation',
        'play 5' => 'ps5',
        'play 4' => 'ps4',
        'nintendo swich' => 'nintendo switch',
        'control ps' => 'dualshock',
    ];
    return strtr($query, $aliases);
}

function text_contains(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

function product_inferred_condition(array $product): string
{
    $text = product_filter_text($product);
    if (preg_match('/\b(reacondicionado|refurbished|reparado)\b/u', $text)) {
        return 'reacondicionado';
    }
    if (preg_match('/\b(usado|segunda|seminuevo|preowned)\b/u', $text)) {
        return 'usado';
    }
    if (preg_match('/\b(caja abierta|open box)\b/u', $text)) {
        return 'caja_abierta';
    }
    return 'nuevo';
}

function product_inferred_type(array $product): string
{
    $text = product_filter_text($product);
    $category = strtolower(product_category($product));
    if (text_contains($text, 'servicio') || text_contains($category, 'servicio')) {
        return 'servicio';
    }
    if (preg_match('/\b(juego|fifa|call of duty|assassin|pokemon|mario|zelda|minecraft|battlefield|dark souls|need for speed)\b/u', $text) || text_contains($category, 'juego')) {
        return 'juego';
    }
    if (preg_match('/\b(consola|ps5|ps4|xbox series|xbox one|switch oled|nintendo 3ds|nintendo dsi|wii)\b/u', $text)) {
        return 'consola';
    }
    if (preg_match('/\b(control|joystick|joy-con|dualshock|dualsense|mando)\b/u', $text)) {
        return 'control';
    }
    if (preg_match('/\b(peluche|figura|funko|llavero|carta|cartas|coleccionable)\b/u', $text) || text_contains($category, 'coleccion')) {
        return 'coleccionable';
    }
    return 'accesorio';
}

function product_inferred_platform(array $product): string
{
    $text = product_filter_text($product);
    if (preg_match('/\b(xbox|x-box|360|series x|series s)\b/u', $text)) {
        return 'xbox';
    }
    if (preg_match('/\b(ps5|playstation 5|play 5)\b/u', $text)) {
        return 'ps5';
    }
    if (preg_match('/\b(ps4|playstation 4|play 4)\b/u', $text)) {
        return 'ps4';
    }
    if (preg_match('/\b(ps3|playstation 3|play 3)\b/u', $text)) {
        return 'ps3';
    }
    if (preg_match('/\b(switch|nintendo|3ds|dsi|wii|mario|pokemon|zelda)\b/u', $text)) {
        return 'nintendo';
    }
    if (preg_match('/\b(pc|steam|computador|gaming)\b/u', $text)) {
        return 'pc';
    }
    return '';
}

function product_inferred_brand(array $product): string
{
    $text = product_filter_text($product);
    $brands = [
        'nintendo' => ['nintendo', 'switch', 'mario', 'pokemon', 'zelda'],
        'sony' => ['sony', 'playstation', 'ps5', 'ps4', 'ps3', 'dualsense', 'dualshock'],
        'microsoft' => ['microsoft', 'xbox', 'x-box'],
        'ea' => ['electronic arts', ' ea ', 'fifa'],
        'activision' => ['activision', 'call of duty'],
        'ubisoft' => ['ubisoft', 'assassin', 'farcry'],
        'rockstar' => ['rockstar', 'red dead', 'gta'],
        'funko' => ['funko'],
        'pokemon' => ['pokemon'],
        'dragon_ball' => ['dragon ball', 'goku', 'vegeta'],
    ];
    foreach ($brands as $brand => $needles) {
        foreach ($needles as $needle) {
            if (text_contains(' ' . $text . ' ', $needle)) {
                return $brand;
            }
        }
    }
    return '';
}

function filter_label(string $group, string $value): string
{
    $labels = [
        'type' => ['consola' => 'Consolas', 'juego' => 'Juegos', 'control' => 'Controles', 'accesorio' => 'Accesorios', 'coleccionable' => 'Coleccionables', 'servicio' => 'Servicios'],
        'platform' => ['xbox' => 'Xbox', 'ps5' => 'PlayStation 5', 'ps4' => 'PlayStation 4', 'ps3' => 'PlayStation 3', 'nintendo' => 'Nintendo', 'pc' => 'PC / Gaming'],
        'condition' => ['nuevo' => 'Nuevo', 'usado' => 'Usado', 'reacondicionado' => 'Reacondicionado', 'caja_abierta' => 'Caja abierta'],
        'brand' => ['nintendo' => 'Nintendo', 'sony' => 'Sony', 'microsoft' => 'Microsoft / Xbox', 'ea' => 'Electronic Arts', 'activision' => 'Activision', 'ubisoft' => 'Ubisoft', 'rockstar' => 'Rockstar', 'funko' => 'Funko', 'pokemon' => 'Pokemon', 'dragon_ball' => 'Dragon Ball'],
    ];
    return $labels[$group][$value] ?? display_name(str_replace('_', ' ', $value));
}

function apply_store_extended_filters(array $products, array $filters): array
{
    $type = trim((string) ($filters['type'] ?? ''));
    $platform = trim((string) ($filters['platform'] ?? ''));
    $condition = trim((string) ($filters['condition'] ?? ''));
    $brand = trim((string) ($filters['brand'] ?? ''));
    $availability = trim((string) ($filters['availability'] ?? ''));
    $pricedOnly = !empty($filters['priced']);
    $questionsOnly = !empty($filters['questions']);
    $reviewsOnly = !empty($filters['reviews']);
    $imageOnly = !empty($filters['image']);

    return array_values(array_filter($products, static function (array $product) use ($type, $platform, $condition, $brand, $availability, $pricedOnly, $questionsOnly, $reviewsOnly, $imageOnly): bool {
        $price = (float) (($product['price_ttc'] !== null && $product['price_ttc'] !== '') ? $product['price_ttc'] : $product['price']);
        if ($type !== '' && product_inferred_type($product) !== $type) {
            return false;
        }
        if ($platform !== '' && product_inferred_platform($product) !== $platform) {
            return false;
        }
        if ($condition !== '' && product_inferred_condition($product) !== $condition) {
            return false;
        }
        if ($brand !== '' && product_inferred_brand($product) !== $brand) {
            return false;
        }
        if ($availability === 'available' && (float) ($product['stock'] ?? 0) <= 0) {
            return false;
        }
        if ($availability === 'consult' && (float) ($product['stock'] ?? 0) > 0) {
            return false;
        }
        if ($pricedOnly && $price <= 0) {
            return false;
        }
        if ($questionsOnly && (int) ($product['question_count'] ?? 0) <= 0) {
            return false;
        }
        if ($reviewsOnly && (int) ($product['review_count'] ?? 0) <= 0) {
            return false;
        }
        if ($imageOnly && product_image_url((string) $product['ref'], 80) === null) {
            return false;
        }
        return true;
    }));
}

function facet_counts(array $products, callable $resolver): array
{
    $counts = [];
    foreach ($products as $product) {
        $value = (string) $resolver($product);
        if ($value === '') {
            continue;
        }
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
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

function connect_store_common(string &$prefix, int &$entity): PDO
{
    require __DIR__ . '/htdocs/conf/conf.php';
    $host = $dolibarr_main_db_host ?? 'localhost';
    $port = $dolibarr_main_db_port ?? '';
    $name = $dolibarr_main_db_name ?? '';
    $user = $dolibarr_main_db_user ?? '';
    $pass = $dolibarr_main_db_pass ?? '';
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $dolibarr_main_db_prefix ?? 'llx_');
    $entity = (int) ($dolibarr_main_entity ?? 1);
    $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function ensure_store_experience_tables(PDO $pdo): void
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
            order_code VARCHAR(32) NULL,
            user_id INT NULL,
            customer_name VARCHAR(120) NOT NULL,
            customer_email VARCHAR(180) NULL,
            customer_phone VARCHAR(40) NOT NULL,
            delivery_method VARCHAR(40) NOT NULL,
            payment_method VARCHAR(40) NULL,
            address VARCHAR(255) NULL,
            notes TEXT NULL,
            subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            shipping_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total DECIMAL(18,2) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'nuevo',
            payment_status VARCHAR(40) NOT NULL DEFAULT 'pendiente',
            shipping_status VARCHAR(40) NOT NULL DEFAULT 'pendiente',
            tracking_url VARCHAR(255) NULL,
            payment_evidence_status VARCHAR(40) NOT NULL DEFAULT 'por_confirmar',
            payment_evidence_note VARCHAR(255) NULL,
            payment_evidence_file VARCHAR(255) NULL,
            store_public_note TEXT NULL,
            dolibarr_invoice_id INT NULL,
            dolibarr_invoice_ref VARCHAR(64) NULL,
            confirmed_at DATETIME NULL,
            paid_at DATETIME NULL,
            preparing_at DATETIME NULL,
            shipped_at DATETIME NULL,
            delivered_at DATETIME NULL,
            dolibarr_payment_id INT NULL,
            dolibarr_payment_ref VARCHAR(64) NULL,
            stock_discounted_at DATETIME NULL,
            dolibarr_sync_note TEXT NULL,
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
        CREATE TABLE IF NOT EXISTS sx_store_order_evidence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            uploaded_by VARCHAR(20) NOT NULL DEFAULT 'customer',
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_evidence_order (order_id)
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
        CREATE TABLE IF NOT EXISTS sx_store_searches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            query VARCHAR(180) NOT NULL,
            category VARCHAR(80) NULL,
            result_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_search_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_addresses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            label VARCHAR(80) NOT NULL,
            address VARCHAR(255) NOT NULL,
            city VARCHAR(80) NOT NULL DEFAULT 'Tulua',
            phone VARCHAR(40) NULL,
            is_default TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_address_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NULL,
            email VARCHAR(160) NULL,
            phone VARCHAR(60) NULL,
            interest VARCHAR(180) NULL,
            source VARCHAR(80) NOT NULL DEFAULT 'web',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
            discount_value DECIMAL(18,2) NOT NULL DEFAULT 0,
            enabled TINYINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_product_promotions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL UNIQUE,
            promo_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            label VARCHAR(120) NULL,
            enabled TINYINT NOT NULL DEFAULT 1,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_promo_product_enabled (product_id, enabled)
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
            payment_url VARCHAR(255) NULL,
            dolibarr_payment_code VARCHAR(20) NULL,
            dolibarr_bank_account_id INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_favorite_lists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fav_list_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sx_store_favorite_list_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            list_id INT NOT NULL,
            product_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_fav_list_product (list_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    foreach ([
        "ALTER TABLE sx_store_orders ADD COLUMN subtotal DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER notes",
        "ALTER TABLE sx_store_orders ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER subtotal",
        "ALTER TABLE sx_store_orders ADD COLUMN shipping_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_amount",
        "ALTER TABLE sx_store_orders ADD COLUMN tracking_url VARCHAR(255) NULL AFTER shipping_status",
        "ALTER TABLE sx_store_orders ADD COLUMN payment_evidence_status VARCHAR(40) NOT NULL DEFAULT 'por_confirmar' AFTER tracking_url",
        "ALTER TABLE sx_store_orders ADD COLUMN payment_evidence_note VARCHAR(255) NULL AFTER payment_evidence_status",
        "ALTER TABLE sx_store_orders ADD COLUMN payment_evidence_file VARCHAR(255) NULL AFTER payment_evidence_note",
        "ALTER TABLE sx_store_orders ADD COLUMN store_public_note TEXT NULL AFTER payment_evidence_file",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_invoice_id INT NULL AFTER store_public_note",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_invoice_ref VARCHAR(64) NULL AFTER dolibarr_invoice_id",
        "ALTER TABLE sx_store_orders ADD COLUMN confirmed_at DATETIME NULL AFTER dolibarr_invoice_ref",
        "ALTER TABLE sx_store_orders ADD COLUMN paid_at DATETIME NULL AFTER confirmed_at",
        "ALTER TABLE sx_store_orders ADD COLUMN preparing_at DATETIME NULL AFTER paid_at",
        "ALTER TABLE sx_store_orders ADD COLUMN shipped_at DATETIME NULL AFTER preparing_at",
        "ALTER TABLE sx_store_orders ADD COLUMN delivered_at DATETIME NULL AFTER shipped_at",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_payment_id INT NULL AFTER delivered_at",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_payment_ref VARCHAR(64) NULL AFTER dolibarr_payment_id",
        "ALTER TABLE sx_store_orders ADD COLUMN stock_discounted_at DATETIME NULL AFTER dolibarr_payment_ref",
        "ALTER TABLE sx_store_orders ADD COLUMN dolibarr_sync_note TEXT NULL AFTER stock_discounted_at",
        "ALTER TABLE sx_store_payment_methods ADD COLUMN payment_url VARCHAR(255) NULL AFTER instructions",
        "ALTER TABLE sx_store_payment_methods ADD COLUMN dolibarr_payment_code VARCHAR(20) NULL AFTER payment_url",
        "ALTER TABLE sx_store_payment_methods ADD COLUMN dolibarr_bank_account_id INT NULL AFTER dolibarr_payment_code"
    ] as $ddl) {
        try {
            $pdo->exec($ddl);
        } catch (Throwable $alreadyExists) {
        }
    }
    $defaultRates = [
        ['retiro', 'Retiro en tienda', 0],
        ['tulua', 'Domicilio Tulua', 8000],
        ['valle', 'Envio Valle del Cauca', 18000],
        ['nacional', 'Envio nacional', 25000],
    ];
    $rateStmt = $pdo->prepare('INSERT IGNORE INTO sx_store_shipping_rates (code, label, price) VALUES (:code, :label, :price)');
    foreach ($defaultRates as $rate) {
        $rateStmt->execute(['code' => $rate[0], 'label' => $rate[1], 'price' => $rate[2]]);
    }
    $defaultMethods = [
        ['transferencia', 'Transferencia bancaria', 'Te enviaremos los datos bancarios al confirmar disponibilidad.'],
        ['nequi', 'Nequi / Daviplata', 'Te confirmaremos el numero y valor final antes del pago.'],
        ['datafono', 'Pago con tarjeta en tienda', 'Disponible para retiro o compra presencial.'],
    ];
    $methodStmt = $pdo->prepare('INSERT IGNORE INTO sx_store_payment_methods (code, label, instructions) VALUES (:code, :label, :instructions)');
    foreach ($defaultMethods as $method) {
        $methodStmt->execute(['code' => $method[0], 'label' => $method[1], 'instructions' => $method[2]]);
    }
}

function save_payment_evidence_uploads(string $field, string $orderCode): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }
    $files = $_FILES[$field];
    $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
    $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
    $dir = __DIR__ . '/assets/uploads/payment-evidence';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo preparar la carpeta de evidencias.');
    }
    $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $safeCode = preg_replace('/[^A-Z0-9-]/i', '-', $orderCode);
    $saved = [];
    foreach ($errors as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo cargar una imagen de evidencia.');
        }
        if ((int) ($sizes[$index] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException('Cada evidencia no puede superar 5 MB.');
        }
        $tmpName = (string) ($tmpNames[$index] ?? '');
        $mime = $tmpName !== '' ? (mime_content_type($tmpName) ?: '') : '';
        if (!isset($map[$mime])) {
            throw new RuntimeException('La evidencia debe ser imagen JPG, PNG o WEBP.');
        }
        $name = strtolower($safeCode . '-' . bin2hex(random_bytes(4)) . '-' . ((int) $index + 1) . '.' . $map[$mime]);
        $target = $dir . '/' . $name;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('No se pudo guardar una evidencia de pago.');
        }
        $saved[] = '/assets/uploads/payment-evidence/' . $name;
    }
    return $saved;
}

function save_payment_evidence_upload(string $field, string $orderCode): ?string
{
    $files = save_payment_evidence_uploads($field, $orderCode);
    return $files[0] ?? null;
}

function insert_order_evidence(PDO $pdo, int $orderId, array $files, string $uploadedBy, string $note = ''): void
{
    if (!$files) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO sx_store_order_evidence (order_id, file_path, uploaded_by, note) VALUES (:order_id, :file_path, :uploaded_by, :note)');
    foreach ($files as $file) {
        $stmt->execute([
            'order_id' => $orderId,
            'file_path' => (string) $file,
            'uploaded_by' => $uploadedBy,
            'note' => $note !== '' ? $note : null,
        ]);
    }
}

function fetch_order_evidence(PDO $pdo, int $orderId, ?string $legacyFile = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM sx_store_order_evidence WHERE order_id = :order_id ORDER BY id ASC');
    $stmt->execute(['order_id' => $orderId]);
    $rows = $stmt->fetchAll();
    if (!$rows && $legacyFile) {
        $rows[] = [
            'id' => 0,
            'order_id' => $orderId,
            'file_path' => $legacyFile,
            'uploaded_by' => 'customer',
            'note' => null,
            'created_at' => null,
        ];
    }
    return $rows;
}

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('DESCRIBE ' . $table)->fetchAll() as $column) {
        $columns[(string) $column['Field']] = $column;
    }
    return $columns;
}

function insert_existing_columns(PDO $pdo, string $table, array $values): int
{
    $columns = table_columns($pdo, $table);
    $filtered = array_intersect_key($values, $columns);
    if (!$filtered) {
        throw new RuntimeException('No hay columnas compatibles para ' . $table);
    }
    $names = array_keys($filtered);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($filtered);
    return (int) $pdo->lastInsertId();
}

function update_existing_columns(PDO $pdo, string $table, array $values, string $where, array $whereParams): void
{
    $columns = table_columns($pdo, $table);
    $filtered = array_intersect_key($values, $columns);
    if (!$filtered) {
        return;
    }
    $sets = array_map(static fn($name) => $name . ' = :' . $name, array_keys($filtered));
    $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where);
    $stmt->execute($filtered + $whereParams);
}

function find_or_create_store_thirdparty(PDO $pdo, string $prefix, int $entity, array $customer): int
{
    $societeTable = $prefix . 'societe';
    $email = trim((string) ($customer['email'] ?? ''));
    $phone = trim((string) ($customer['phone'] ?? ''));

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT rowid FROM {$societeTable} WHERE email = :email ORDER BY rowid DESC LIMIT 1");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['rowid'];
        }
    }
    if ($phone !== '') {
        $stmt = $pdo->prepare("SELECT rowid FROM {$societeTable} WHERE phone = :phone OR phone_mobile = :phone ORDER BY rowid DESC LIMIT 1");
        try {
            $stmt->execute(['phone' => $phone]);
            $row = $stmt->fetch();
            if ($row) {
                return (int) $row['rowid'];
            }
        } catch (Throwable $ignored) {
        }
    }

    $name = trim((string) ($customer['name'] ?? 'Cliente tienda web'));
    return insert_existing_columns($pdo, $societeTable, [
        'nom' => $name !== '' ? $name : 'Cliente tienda web',
        'name_alias' => 'Cliente web Station X',
        'entity' => $entity,
        'client' => 1,
        'status' => 1,
        'datec' => date('Y-m-d H:i:s'),
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'phone_mobile' => $phone !== '' ? $phone : null,
        'address' => trim((string) ($customer['address'] ?? '')),
        'town' => 'Tulua',
        'code_client' => 'SXWEB-' . substr(preg_replace('/[^0-9A-Z]/i', '', $phone . $email), 0, 24),
    ]);
}

function create_dolibarr_draft_invoice(PDO $pdo, string $prefix, int $entity, int $orderId, string $orderCode, array $customer, array $items, float $subtotal, float $discount, float $shipping, float $total): ?array
{
    $invoiceTable = $prefix . 'facture';
    $lineTable = $prefix . 'facturedet';

    try {
        $existing = $pdo->prepare('SELECT dolibarr_invoice_id, dolibarr_invoice_ref FROM sx_store_orders WHERE id = :id');
        $existing->execute(['id' => $orderId]);
        $order = $existing->fetch();
        if ($order && !empty($order['dolibarr_invoice_id'])) {
            return ['id' => (int) $order['dolibarr_invoice_id'], 'ref' => (string) $order['dolibarr_invoice_ref']];
        }

        $thirdpartyId = find_or_create_store_thirdparty($pdo, $prefix, $entity, $customer);
        $now = date('Y-m-d H:i:s');
        $date = date('Y-m-d');
        $invoiceRef = '(PROV' . $orderId . ')';
        $invoiceId = insert_existing_columns($pdo, $invoiceTable, [
            'ref' => $invoiceRef,
            'ref_ext' => $orderCode,
            'entity' => $entity,
            'type' => 0,
            'fk_soc' => $thirdpartyId,
            'datec' => $now,
            'datef' => $date,
            'date_lim_reglement' => $date,
            'total_ht' => $total,
            'total_tva' => 0,
            'total_ttc' => $total,
            'localtax1' => 0,
            'localtax2' => 0,
            'paye' => 0,
            'fk_statut' => 0,
            'status' => 0,
            'note_private' => "Pedido web {$orderCode}. Evidencia de pago por confirmar. Validar factura en Dolibarr antes de aprobar el estado.",
        ]);

        $rank = 1;
        foreach ($items as $item) {
            $qty = (int) $item['quantity'];
            $unit = (float) $item['unit_price'];
            $lineTotal = $unit * $qty;
            insert_existing_columns($pdo, $lineTable, [
                'fk_facture' => $invoiceId,
                'fk_product' => (int) $item['rowid'],
                'description' => display_name((string) $item['label']),
                'qty' => $qty,
                'subprice' => $unit,
                'tva_tx' => 0,
                'total_ht' => $lineTotal,
                'total_tva' => 0,
                'total_ttc' => $lineTotal,
                'product_type' => 0,
                'rang' => $rank++,
            ]);
        }
        if ($discount > 0) {
            insert_existing_columns($pdo, $lineTable, [
                'fk_facture' => $invoiceId,
                'description' => 'Descuento tienda web',
                'qty' => 1,
                'subprice' => -$discount,
                'tva_tx' => 0,
                'total_ht' => -$discount,
                'total_tva' => 0,
                'total_ttc' => -$discount,
                'product_type' => 1,
                'rang' => $rank++,
            ]);
        }
        if ($shipping > 0) {
            insert_existing_columns($pdo, $lineTable, [
                'fk_facture' => $invoiceId,
                'description' => 'Envio tienda web',
                'qty' => 1,
                'subprice' => $shipping,
                'tva_tx' => 0,
                'total_ht' => $shipping,
                'total_tva' => 0,
                'total_ttc' => $shipping,
                'product_type' => 1,
                'rang' => $rank++,
            ]);
        }

        update_existing_columns($pdo, $invoiceTable, [
            'ref' => $invoiceRef,
            'total_ht' => $total,
            'total_tva' => 0,
            'total_ttc' => $total,
        ], 'rowid = :invoice_id', ['invoice_id' => $invoiceId]);
        $pdo->prepare('UPDATE sx_store_orders SET dolibarr_invoice_id = :invoice_id, dolibarr_invoice_ref = :invoice_ref WHERE id = :order_id')->execute([
            'invoice_id' => $invoiceId,
            'invoice_ref' => $invoiceRef,
            'order_id' => $orderId,
        ]);
        return ['id' => $invoiceId, 'ref' => $invoiceRef];
    } catch (Throwable $error) {
        $pdo->prepare('UPDATE sx_store_orders SET payment_evidence_note = :note WHERE id = :order_id')->execute([
            'note' => 'No se pudo crear factura Dolibarr: ' . substr($error->getMessage(), 0, 180),
            'order_id' => $orderId,
        ]);
        return null;
    }
}

function fetch_shipping_rates(PDO $pdo): array
{
    ensure_store_experience_tables($pdo);
    $rows = $pdo->query('SELECT code, label, price, enabled FROM sx_store_shipping_rates WHERE enabled = 1 ORDER BY price ASC, id ASC')->fetchAll();
    return $rows ?: [
        ['code' => 'retiro', 'label' => 'Retiro en tienda', 'price' => 0, 'enabled' => 1],
        ['code' => 'tulua', 'label' => 'Domicilio Tulua', 'price' => 8000, 'enabled' => 1],
        ['code' => 'valle', 'label' => 'Envio Valle del Cauca', 'price' => 18000, 'enabled' => 1],
        ['code' => 'nacional', 'label' => 'Envio nacional', 'price' => 25000, 'enabled' => 1],
    ];
}

function fetch_payment_methods(PDO $pdo): array
{
    ensure_store_experience_tables($pdo);
    $rows = $pdo->query("SELECT code, label, enabled, instructions, payment_url FROM sx_store_payment_methods WHERE enabled = 1 AND code <> 'confirmar' ORDER BY id ASC")->fetchAll();
    return $rows ?: [
        ['code' => 'transferencia', 'label' => 'Transferencia bancaria', 'enabled' => 1, 'instructions' => 'Enviaremos los datos bancarios para confirmar tu pago.', 'payment_url' => ''],
        ['code' => 'nequi', 'label' => 'Nequi / Daviplata', 'enabled' => 1, 'instructions' => 'Enviaremos los datos para confirmar tu pago movil.', 'payment_url' => ''],
        ['code' => 'datafono', 'label' => 'Pago con tarjeta en tienda', 'enabled' => 1, 'instructions' => 'Disponible para retiro o compra presencial.', 'payment_url' => ''],
    ];
}

function find_store_option(array $rows, string $code): ?array
{
    foreach ($rows as $row) {
        if ((string) ($row['code'] ?? '') === $code) {
            return $row;
        }
    }
    return $rows[0] ?? null;
}

function fetch_store_products(PDO $pdo, string $prefix, int $entity, int $limit = 5000): array
{
    try {
        ensure_store_experience_tables($pdo);
    } catch (Throwable $ignored) {
    }
    try {
        $stmt = $pdo->prepare("
            SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc,
                   COALESCE((SELECT SUM(ps.reel) FROM {$prefix}product_stock ps WHERE ps.fk_product = p.rowid), 0) AS stock,
                   GROUP_CONCAT(DISTINCT c.label ORDER BY c.label SEPARATOR ', ') AS erp_categories,
                   COALESCE((SELECT AVG(r.rating) FROM sx_store_product_reviews r WHERE r.product_id = p.rowid), 0) AS avg_rating,
                   (SELECT COUNT(*) FROM sx_store_product_reviews r WHERE r.product_id = p.rowid) AS review_count,
                   (SELECT COUNT(*) FROM sx_store_product_questions q WHERE q.product_id = p.rowid) AS question_count,
                   COALESCE(pr.promo_price, 0) AS promo_price,
                   pr.label AS promo_label
            FROM {$prefix}product p
            LEFT JOIN {$prefix}categorie_product cp ON cp.fk_product = p.rowid
            LEFT JOIN {$prefix}categorie c ON c.rowid = cp.fk_categorie AND c.type = 0
            LEFT JOIN sx_store_product_promotions pr ON pr.product_id = p.rowid
                AND pr.enabled = 1
                AND (pr.starts_at IS NULL OR pr.starts_at <= NOW())
                AND (pr.ends_at IS NULL OR pr.ends_at >= NOW())
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
            GROUP BY p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, pr.promo_price, pr.label
            ORDER BY p.rowid DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['entity' => $entity]);
        return $stmt->fetchAll();
    } catch (Throwable $stockError) {
        $stmt = $pdo->prepare("
            SELECT rowid, ref, label, description, price, price_ttc, NULL AS stock, NULL AS erp_categories, 0 AS avg_rating, 0 AS review_count, 0 AS question_count, 0 AS promo_price, NULL AS promo_label
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
        ");
        $stmt->execute(['entity' => $entity]);
        return $stmt->fetchAll();
    }
}

function fetch_store_categories(PDO $pdo, string $prefix, int $entity): array
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

function filter_store_products(array $products, string $query = '', string $category = '', float $min = 0, float $max = 0, bool $stockOnly = false, string $sort = 'recent'): array
{
    $query = normalize_store_search($query);
    $category = trim($category);
    $filtered = array_values(array_filter($products, static function (array $product) use ($query, $category, $min, $max, $stockOnly): bool {
        $price = product_effective_price($product);
        $text = normalize_store_search(($product['ref'] ?? '') . ' ' . ($product['label'] ?? '') . ' ' . ($product['description'] ?? '') . ' ' . ($product['erp_categories'] ?? '') . ' ' . product_category($product));
        if ($query !== '' && strpos($text, $query) === false) {
            return false;
        }
        if ($category !== '' && $category !== 'Todo' && !product_matches_category($product, $category)) {
            return false;
        }
        if ($min > 0 && $price < $min) {
            return false;
        }
        if ($max > 0 && $price > $max) {
            return false;
        }
        if ($stockOnly && (float) ($product['stock'] ?? 0) <= 0) {
            return false;
        }
        return true;
    }));

    usort($filtered, static function (array $a, array $b) use ($sort): int {
        $priceA = product_effective_price($a);
        $priceB = product_effective_price($b);
        if ($sort === 'price_asc') {
            return $priceA <=> $priceB;
        }
        if ($sort === 'price_desc') {
            return $priceB <=> $priceA;
        }
        if ($sort === 'name') {
            return display_name((string) $a['label']) <=> display_name((string) $b['label']);
        }
        if ($sort === 'rating') {
            return (float) ($b['avg_rating'] ?? 0) <=> (float) ($a['avg_rating'] ?? 0);
        }
        if ($sort === 'stock') {
            return (float) ($b['stock'] ?? 0) <=> (float) ($a['stock'] ?? 0);
        }
        if ($sort === 'questions') {
            return (int) ($b['question_count'] ?? 0) <=> (int) ($a['question_count'] ?? 0);
        }
        if ($sort === 'discount') {
            return product_discount_percent($b) <=> product_discount_percent($a);
        }
        return (int) $b['rowid'] <=> (int) $a['rowid'];
    });

    return $filtered;
}

function save_store_search(PDO $pdo, ?array $user, string $query, string $category, int $count): void
{
    $query = trim($query);
    $category = trim($category);
    if ($query === '' && $category === '') {
        return;
    }
    ensure_store_experience_tables($pdo);
    $stmt = $pdo->prepare('INSERT INTO sx_store_searches (user_id, query, category, result_count) VALUES (:user_id, :query, :category, :result_count)');
    $stmt->execute([
        'user_id' => $user['id'] ?? null,
        'query' => $query !== '' ? $query : $category,
        'category' => $category !== '' ? $category : null,
        'result_count' => $count,
    ]);
    if (!$user) {
        $_SESSION['sx_recent_searches'] = array_slice(array_values(array_unique(array_merge([$query !== '' ? $query : $category], $_SESSION['sx_recent_searches'] ?? []))), 0, 10);
    }
}

function fetch_user_searches(PDO $pdo, ?array $user): array
{
    if (!$user) {
        return array_map(static fn($query) => ['query' => $query, 'category' => null, 'result_count' => 0, 'created_at' => 'Sesion actual'], $_SESSION['sx_recent_searches'] ?? []);
    }
    ensure_store_experience_tables($pdo);
    $stmt = $pdo->prepare('SELECT query, category, result_count, created_at FROM sx_store_searches WHERE user_id = :user_id ORDER BY id DESC LIMIT 40');
    $stmt->execute(['user_id' => $user['id']]);
    return $stmt->fetchAll();
}

function fetch_user_orders_full(PDO $pdo, ?array $user): array
{
    if (!$user) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, order_code, total, status, payment_status, shipping_status, payment_evidence_status, dolibarr_invoice_ref, delivery_method, payment_method, created_at FROM sx_store_orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 40');
    $stmt->execute(['user_id' => $user['id']]);
    return $stmt->fetchAll();
}

function fetch_user_favorites(PDO $pdo, string $prefix, ?array $user): array
{
    if (!$user) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, NULL AS stock, 0 AS avg_rating, 0 AS review_count, 0 AS question_count FROM sx_store_favorites f JOIN {$prefix}product p ON p.rowid = f.product_id WHERE f.user_id = :user_id ORDER BY f.id DESC");
    $stmt->execute(['user_id' => $user['id']]);
    return $stmt->fetchAll();
}

function fetch_order_detail(PDO $pdo, ?array $user, int $orderId): ?array
{
    if (!$user) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM sx_store_orders WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $orderId, 'user_id' => (int) $user['id']]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }
    $items = $pdo->prepare('SELECT * FROM sx_store_order_items WHERE order_id = :order_id');
    $items->execute(['order_id' => $orderId]);
    $order['items'] = $items->fetchAll();
    return $order;
}

function fetch_user_addresses(PDO $pdo, ?array $user): array
{
    if (!$user) {
        return [];
    }
    ensure_store_experience_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM sx_store_addresses WHERE user_id = :user_id ORDER BY is_default DESC, id DESC');
    $stmt->execute(['user_id' => (int) $user['id']]);
    return $stmt->fetchAll();
}

function paginate_items(array $items, int $page, int $perPage = 24): array
{
    $total = count($items);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return [
        'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'per_page' => $perPage,
        'from' => $total ? (($page - 1) * $perPage) + 1 : 0,
        'to' => min($total, $page * $perPage),
    ];
}

function page_url(array $params, int $page): string
{
    $params['page'] = $page;
    return '?' . http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null && $value !== false));
}

function render_pagination(array $pagination, array $params): void
{
    if (($pagination['pages'] ?? 1) <= 1) {
        return;
    }
    $page = (int) $pagination['page'];
    $pages = (int) $pagination['pages'];
    ?>
    <nav class="pagination" aria-label="Paginacion">
        <a class="<?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page > 1 ? e(page_url($params, $page - 1)) : '#'; ?>">Anterior</a>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <a class="<?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo e(page_url($params, $i)); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <a class="<?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo $page < $pages ? e(page_url($params, $page + 1)) : '#'; ?>">Siguiente</a>
    </nav>
    <?php
}

function render_store_product_card(array $product, array $favoriteIds = []): void
{
    $price = product_effective_price($product);
    $basePrice = product_base_price($product);
    $discount = product_discount_percent($product);
    $name = display_name((string) $product['label']);
    $ref = display_name((string) $product['ref']);
    $description = trim(strip_tags((string) ($product['description'] ?? '')));
    $category = product_category($product);
    $image = product_image_url((string) $product['ref'], 420);
    $url = product_url($product);
    ?>
    <article class="product">
        <a class="product-media" href="<?php echo e($url); ?>">
            <?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e($name); ?>" loading="lazy"><?php else: ?><span><?php echo e(substr($name, 0, 2)); ?></span><?php endif; ?>
        </a>
        <div class="product-body">
            <span class="pill"><?php echo e($category); ?></span>
            <h3><a href="<?php echo e($url); ?>"><?php echo e($name); ?></a></h3>
            <p><?php echo e($description !== '' ? display_name($description) : $ref); ?></p>
            <div class="product-signals">
                <span><?php echo (float) ($product['avg_rating'] ?? 0) > 0 ? number_format((float) $product['avg_rating'], 1) . '/5' : 'Nuevo'; ?></span>
                <span><?php echo (int) ($product['question_count'] ?? 0); ?> preguntas</span>
            </div>
        </div>
        <form class="product-footer" method="post" action="/">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="add_cart">
            <input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>">
            <div>
                <?php if ($discount > 0): ?><small class="old-price"><?php echo e(money_value($basePrice)); ?></small><?php endif; ?>
                <strong><?php echo e(money_value($price)); ?></strong>
                <?php if ($discount > 0): ?><span class="discount-badge"><?php echo $discount; ?>% OFF</span><?php endif; ?>
                <?php if (array_key_exists('stock', $product) && $product['stock'] !== null): ?><small><?php echo (float) $product['stock'] > 0 ? 'Disponible' : 'Consultar disponibilidad'; ?></small><?php endif; ?>
            </div>
            <button type="submit">Agregar</button>
        </form>
        <form class="favorite-form" method="post" action="/">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="toggle_favorite">
            <input type="hidden" name="product_id" value="<?php echo (int) $product['rowid']; ?>">
            <button type="submit"><?php echo in_array((int) $product['rowid'], $favoriteIds, true) ? 'Favorito' : 'Guardar'; ?></button>
        </form>
        <form class="compare-form" method="get" action="/comparar.php">
            <input type="hidden" name="add" value="<?php echo (int) $product['rowid']; ?>">
            <button type="submit">Comparar</button>
        </form>
    </article>
    <?php
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
    return $_SESSION['sx_cart'] ?? [];
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

function fetch_cart_items(PDO $pdo, string $prefix, array $cart): array
{
    if (!$cart) {
        return [];
    }
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT p.rowid, p.ref, p.label, p.price, p.price_ttc,
               COALESCE((SELECT SUM(ps.reel) FROM {$prefix}product_stock ps WHERE ps.fk_product = p.rowid), 0) AS stock,
               COALESCE(pr.promo_price, 0) AS promo_price,
               pr.label AS promo_label
        FROM {$prefix}product p
        LEFT JOIN sx_store_product_promotions pr ON pr.product_id = p.rowid
            AND pr.enabled = 1
            AND (pr.starts_at IS NULL OR pr.starts_at <= NOW())
            AND (pr.ends_at IS NULL OR pr.ends_at >= NOW())
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
        $row['unit_price'] = product_effective_price($row);
        $items[] = $row;
    }
    return $items;
}

function unavailable_cart_items(array $items): array
{
    return array_values(array_filter($items, static function (array $item): bool {
        return isset($item['stock']) && (float) $item['stock'] < (int) ($item['quantity'] ?? 0);
    }));
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

function fetch_product_detail(PDO $pdo, string $prefix, int $id): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.weight, p.weight_units, p.length, p.width, p.height, p.size_units, COALESCE(SUM(ps.reel), 0) AS stock
            FROM {$prefix}product p
            LEFT JOIN {$prefix}product_stock ps ON ps.fk_product = p.rowid
            WHERE p.rowid = :id AND p.tosell = 1
              AND EXISTS (
                  SELECT 1
                  FROM {$prefix}categorie_product cp_store
                  INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie
                  WHERE cp_store.fk_product = p.rowid
                    AND c_store.type = 0
                    AND UPPER(TRIM(c_store.label)) = 'TIENDA'
              )
            GROUP BY p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.weight, p.weight_units, p.length, p.width, p.height, p.size_units
        ");
        $stmt->execute(['id' => $id]);
    } catch (Throwable $stockError) {
        $stmt = $pdo->prepare("SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, NULL AS weight, NULL AS weight_units, NULL AS length, NULL AS width, NULL AS height, NULL AS size_units, NULL AS stock FROM {$prefix}product p WHERE p.rowid = :id AND p.tosell = 1 AND EXISTS (SELECT 1 FROM {$prefix}categorie_product cp_store INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie WHERE cp_store.fk_product = p.rowid AND c_store.type = 0 AND UPPER(TRIM(c_store.label)) = 'TIENDA')");
        $stmt->execute(['id' => $id]);
    }
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

function add_session_cart(int $productId, int $quantity): void
{
    $cart = $_SESSION['sx_cart'] ?? [];
    $cart[$productId] = max(1, ($cart[$productId] ?? 0) + $quantity);
    $_SESSION['sx_cart'] = $cart;
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

function store_header(string $title, int $cartCount = 0, array $miniCartItems = []): void
{
    global $pdo, $prefix, $currentUser;
    if (!$miniCartItems && $cartCount > 0 && $pdo instanceof PDO && is_string($prefix)) {
        try {
            $miniCartItems = fetch_cart_items($pdo, $prefix, load_cart($pdo, is_array($currentUser ?? null) ? $currentUser : null));
        } catch (Throwable $ignored) {
            $miniCartItems = [];
        }
    }
    $logo = public_upload('logo-transparent') ?? public_upload('logo');
    $mark = $logo ? '<img class="brand-logo" src="' . e($logo) . '" alt="Station X Tulua">' : '<span class="brand-mark">SX</span>';
    $miniTotal = array_sum(array_map(static fn($item) => (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 0), $miniCartItems));
    $suggestions = ['Nintendo Switch', 'PlayStation', 'Xbox', 'Controles', 'Videojuegos', 'Accesorios', 'Servicio tecnico', 'Pikachu'];
    if ($pdo instanceof PDO && is_string($prefix)) {
        try {
            $rows = $pdo->query("SELECT p.ref, p.label FROM {$prefix}product p WHERE p.tosell = 1 AND EXISTS (SELECT 1 FROM {$prefix}categorie_product cp_store INNER JOIN {$prefix}categorie c_store ON c_store.rowid = cp_store.fk_categorie WHERE cp_store.fk_product = p.rowid AND c_store.type = 0 AND UPPER(TRIM(c_store.label)) = 'TIENDA') ORDER BY p.rowid DESC LIMIT 60")->fetchAll();
            foreach ($rows as $row) {
                $suggestions[] = display_name((string) ($row['label'] ?? ''));
                $suggestions[] = display_name((string) ($row['ref'] ?? ''));
            }
        } catch (Throwable $ignored) {
        }
    }
    $suggestions = array_slice(array_values(array_unique(array_filter($suggestions))), 0, 90);
    $options = '';
    foreach ($suggestions as $suggestion) {
        $options .= '<option value="' . e($suggestion) . '">';
    }
    $sessionLink = is_array($currentUser ?? null)
        ? '<a href="/logout.php">Cerrar sesion</a>'
        : '<a href="/cuenta.php#login">Entrar</a>';
    echo '<header class="topbar"><a class="brand" href="/">' . $mark . '<span><strong>Station X Tulua</strong><small>Marketplace gamer</small></span></a><form class="header-search" action="/buscar.php" method="get"><input type="search" name="q" list="store-search-suggestions" placeholder="Buscar consolas, juegos y accesorios"><button type="submit" aria-label="Buscar">&#128269;</button><datalist id="store-search-suggestions">' . $options . '</datalist></form><nav class="nav"><a href="/categoria.php">Categorias</a><a href="/recientes.php">Recientes</a><a href="/ayuda.php">Ayuda</a><a href="/perfil.php">Mi perfil</a>' . $sessionLink . '<div class="mini-cart"><a class="cart-toggle" href="/carrito.php">Carrito <span>' . (int) $cartCount . '</span></a><div class="mini-cart-panel">';
    if ($miniCartItems) {
        echo '<strong>Tu carrito</strong>';
        foreach (array_slice($miniCartItems, 0, 3) as $item) {
            $image = product_image_url((string) ($item['ref'] ?? ''), 96);
            echo '<a class="mini-cart-line" href="' . e(product_url($item)) . '">';
            if ($image) {
                echo '<img src="' . e($image) . '" alt="' . e(display_name((string) ($item['label'] ?? 'Producto'))) . '">';
            }
            echo '<span>' . e(display_name((string) ($item['label'] ?? 'Producto'))) . '<small>' . (int) ($item['quantity'] ?? 0) . ' x ' . e(money_value($item['unit_price'] ?? 0)) . '</small></span></a>';
        }
        echo '<div class="mini-cart-total"><span>Total</span><strong>' . e(money_value($miniTotal)) . '</strong></div><a class="mini-cart-action" href="/carrito.php">Ir al carrito</a>';
    } else {
        echo '<strong>Tu carrito esta vacio</strong><p>Agrega productos y revisalos sin salir del catalogo.</p><a class="mini-cart-action" href="/#catalogo">Ver productos</a>';
    }
    echo '</div></div></nav><div class="delivery-note"><strong>Enviar a Tulua</strong><span>Retiro en tienda, domicilio local y envio nacional a confirmar</span></div><div class="header-links"><a href="/pedidos.php">Mis pedidos</a><a href="/favoritos.php">Favoritos</a><a href="/busquedas.php">Mis busquedas</a><a href="/compras.php">Mis compras</a></div></header>';
}

function store_footer(): void
{
    $accountLink = isset($_SESSION['sx_user']) && is_array($_SESSION['sx_user'])
        ? '<a href="/logout.php">Cerrar sesion</a>'
        : '<a href="/cuenta.php">Entrar o registrarme</a>';
    echo '<footer class="footer professional-footer"><div><strong>Station X Tulua</strong><span>Consolas, videojuegos, accesorios, controles, reparacion y mantenimiento gamer.</span></div><div><strong>Comprar</strong><a href="/categoria.php">Categorias</a><a href="/recientes.php">Mas recientes</a><a href="/favoritos.php">Favoritos</a><a href="/comparar.php">Comparador</a><a href="/historial.php">Historial</a></div><div><strong>Mi cuenta</strong>' . $accountLink . '<a href="/perfil.php">Mi perfil</a><a href="/pedidos.php">Mis pedidos</a><a href="/busquedas.php">Mis busquedas</a></div><div><strong>Soporte</strong><a href="/ayuda.php">Ayuda y preguntas</a><a href="/sobre.php">Sobre Station X</a><a href="/legal.php?seccion=garantia">Garantia</a><a href="/legal.php?seccion=privacidad">Privacidad</a></div><div><strong>Redes</strong><a href="https://www.instagram.com/stationxtulua/?hl=es" target="_blank" rel="noopener">Instagram</a><a href="https://www.facebook.com/stationxtulua1/?locale=es_LA" target="_blank" rel="noopener">Facebook</a><a href="https://www.tiktok.com/@stationxtulua" target="_blank" rel="noopener">TikTok</a><a href="/htdocs/">Administracion</a></div></footer><div class="cookie-note" data-cookie-note hidden><span>Usamos cookies para recordar busquedas, textos pendientes y productos vistos en este navegador.</span><button type="button" data-cookie-accept>Aceptar</button></div><script src="/assets/store.js?v=20261014-guest-history" defer></script>';
}
