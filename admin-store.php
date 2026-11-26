<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/store-private.php';

$confPath = __DIR__ . '/htdocs/conf/conf.php';
$flash = $_SESSION['sx_admin_flash'] ?? null;
$admin = $_SESSION['sx_admin'] ?? null;
$pdo = null;
$prefix = 'llx_';
$error = null;

unset($_SESSION['sx_admin_flash']);

if (empty($_SESSION['sx_admin_csrf'])) {
    $_SESSION['sx_admin_csrf'] = bin2hex(random_bytes(32));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money_value($value): string
{
    return '$' . number_format((float) $value, 0, ',', '.');
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['sx_admin_csrf'] ?? '') . '">';
}

function flash_redirect(string $message): void
{
    $_SESSION['sx_admin_flash'] = $message;
    header('Location: /admin-store.php');
    exit;
}

function connect_store(string $confPath, string &$prefix): PDO
{
    require $confPath;
    $host = $dolibarr_main_db_host ?? 'localhost';
    $port = $dolibarr_main_db_port ?? '';
    $name = $dolibarr_main_db_name ?? '';
    $user = $dolibarr_main_db_user ?? '';
    $pass = $dolibarr_main_db_pass ?? '';
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $dolibarr_main_db_prefix ?? 'llx_');
    $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function admin_exists(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM sx_store_users WHERE is_admin = 1');
    return (int) $stmt->fetchColumn() > 0;
}

function ensure_admin_tables(PDO $pdo): void
{
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
}

function display_name(string $value): string
{
    $clean = trim(preg_replace('/[_\s]+/', ' ', strip_tags($value)));
    return $clean === '' ? 'Producto' : ucwords(strtolower($clean));
}

function save_uploaded_asset(string $field, string $name): void
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return;
    }
    $mime = mime_content_type($_FILES[$field]['tmp_name']) ?: '';
    $map = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) {
        throw new RuntimeException('Formato de imagen no permitido.');
    }
    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    foreach (glob($dir . '/' . $name . '.*') ?: [] as $old) {
        @unlink($old);
    }
    move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name . '.' . $map[$mime]);
}

function admin_product_image_count(string $ref, int $limit = 8): int
{
    $safeRef = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $ref);
    $dir = __DIR__ . '/documents/produit/' . $safeRef;
    if (!is_dir($dir)) {
        return 0;
    }
    $files = glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG,WEBP,GIF}', GLOB_BRACE);
    return min($limit, count($files ?: []));
}

function admin_product_image_url(string $ref, int $width = 160): ?string
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

function admin_order_items(PDO $pdo, array $orders): array
{
    if (!$orders) {
        return [];
    }
    $ids = array_map('intval', array_column($orders, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM sx_store_order_items WHERE order_id IN ({$placeholders}) ORDER BY id ASC");
    $stmt->execute($ids);
    $items = [];
    foreach ($stmt->fetchAll() as $item) {
        $items[(int) $item['order_id']][] = $item;
    }
    return $items;
}

function admin_state_label(?string $value): string
{
    return display_name(str_replace('_', ' ', (string) $value));
}

function output_admin_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function dolibarr_invoice_is_validated(PDO $pdo, string $prefix, int $invoiceId): bool
{
    if ($invoiceId <= 0) {
        return false;
    }
    $table = $prefix . 'facture';
    $columns = array_map(static fn($row) => (string) $row['Field'], $pdo->query('DESCRIBE ' . $table)->fetchAll());
    if (in_array('fk_statut', $columns, true)) {
        $stmt = $pdo->prepare("SELECT fk_statut AS status_value FROM {$table} WHERE rowid = :id");
    } elseif (in_array('status', $columns, true)) {
        $stmt = $pdo->prepare("SELECT status AS status_value FROM {$table} WHERE rowid = :id");
    } else {
        return false;
    }
    $stmt->execute(['id' => $invoiceId]);
    return (int) ($stmt->fetch()['status_value'] ?? 0) > 0;
}

function admin_table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('DESCRIBE ' . $table)->fetchAll() as $column) {
        $columns[(string) $column['Field']] = $column;
    }
    return $columns;
}

function admin_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $ignored) {
        return false;
    }
}

function admin_insert_existing_columns(PDO $pdo, string $table, array $values): int
{
    $columns = admin_table_columns($pdo, $table);
    $filtered = array_intersect_key($values, $columns);
    if (!$filtered) {
        throw new RuntimeException('No hay columnas compatibles para ' . $table);
    }
    $names = array_keys($filtered);
    $stmt = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')');
    $stmt->execute($filtered);
    return (int) $pdo->lastInsertId();
}

function admin_save_payment_evidence_uploads(string $field, string $orderCode): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }
    $files = $_FILES[$field];
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

function admin_insert_order_evidence(PDO $pdo, int $orderId, array $files, string $uploadedBy, string $note = ''): void
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

function admin_order_evidence(PDO $pdo, array $orders): array
{
    if (!$orders) {
        return [];
    }
    $ids = array_map('intval', array_column($orders, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM sx_store_order_evidence WHERE order_id IN ({$placeholders}) ORDER BY id ASC");
    $stmt->execute($ids);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['order_id']][] = $row;
    }
    foreach ($orders as $order) {
        $orderId = (int) $order['id'];
        if (empty($grouped[$orderId]) && !empty($order['payment_evidence_file'])) {
            $grouped[$orderId][] = [
                'id' => 0,
                'order_id' => $orderId,
                'file_path' => (string) $order['payment_evidence_file'],
                'uploaded_by' => 'customer',
                'note' => null,
                'created_at' => null,
            ];
        }
    }
    return $grouped;
}

function admin_update_existing_columns(PDO $pdo, string $table, array $values, string $where, array $whereParams): void
{
    $columns = admin_table_columns($pdo, $table);
    $filtered = array_intersect_key($values, $columns);
    if (!$filtered) {
        return;
    }
    $sets = array_map(static fn($name) => $name . ' = :' . $name, array_keys($filtered));
    $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where);
    $stmt->execute($filtered + $whereParams);
}

function dolibarr_invoice_total_matches_order(PDO $pdo, string $prefix, array $order): bool
{
    $invoiceId = (int) ($order['dolibarr_invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT total_ttc FROM {$prefix}facture WHERE rowid = :id");
    $stmt->execute(['id' => $invoiceId]);
    $invoiceTotal = (float) ($stmt->fetch()['total_ttc'] ?? -1);
    return abs($invoiceTotal - (float) ($order['total'] ?? 0)) < 1;
}

function assert_order_stock_available(PDO $pdo, string $prefix, int $orderId): void
{
    $stmt = $pdo->prepare("
        SELECT i.product_name, i.product_id, i.quantity, COALESCE(SUM(ps.reel), 0) AS stock
        FROM sx_store_order_items i
        LEFT JOIN {$prefix}product_stock ps ON ps.fk_product = i.product_id
        WHERE i.order_id = :order_id
        GROUP BY i.id, i.product_name, i.product_id, i.quantity
    ");
    $stmt->execute(['order_id' => $orderId]);
    foreach ($stmt->fetchAll() as $item) {
        if ((float) $item['stock'] < (int) $item['quantity']) {
            throw new RuntimeException('Stock insuficiente en Dolibarr para ' . display_name((string) $item['product_name']) . '. Disponible: ' . (float) $item['stock'] . ', requerido: ' . (int) $item['quantity'] . '.');
        }
    }
}

function dolibarr_payment_type_id(PDO $pdo, string $prefix, string $code): ?int
{
    $code = strtoupper(trim($code));
    if ($code === '' || !admin_table_exists($pdo, $prefix . 'c_paiement')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM {$prefix}c_paiement WHERE UPPER(code) = :code OR UPPER(libelle) = :code ORDER BY id ASC LIMIT 1");
    $stmt->execute(['code' => $code]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function create_dolibarr_payment_for_order(PDO $pdo, string $prefix, int $orderId): bool
{
    $orderStmt = $pdo->prepare('SELECT * FROM sx_store_orders WHERE id = :id');
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderStmt->fetch();
    if (!$order || !empty($order['dolibarr_payment_id'])) {
        return true;
    }
    if (!dolibarr_invoice_total_matches_order($pdo, $prefix, $order)) {
        throw new RuntimeException('El total de la factura Dolibarr no coincide con el total del pedido web.');
    }
    $methodStmt = $pdo->prepare('SELECT * FROM sx_store_payment_methods WHERE code = :code LIMIT 1');
    $methodStmt->execute(['code' => (string) ($order['payment_method'] ?? '')]);
    $method = $methodStmt->fetch() ?: [];
    $paymentTypeId = dolibarr_payment_type_id($pdo, $prefix, (string) ($method['dolibarr_payment_code'] ?? ''));
    if (!$paymentTypeId) {
        $fallback = match ((string) ($order['payment_method'] ?? '')) {
            'transferencia' => 'VIR',
            'nequi' => 'LIQ',
            'datafono' => 'CB',
            default => 'LIQ',
        };
        $paymentTypeId = dolibarr_payment_type_id($pdo, $prefix, $fallback);
    }
    if (!$paymentTypeId) {
        throw new RuntimeException('Configura el codigo de pago Dolibarr para este metodo antes de marcarlo pagado.');
    }
    $ref = 'SXWEB-' . ($order['order_code'] ?: $orderId);
    $amount = (float) $order['total'];
    $invoiceEntityStmt = $pdo->prepare("SELECT entity FROM {$prefix}facture WHERE rowid = :id");
    $invoiceEntityStmt->execute(['id' => (int) $order['dolibarr_invoice_id']]);
    $entity = (int) ($invoiceEntityStmt->fetch()['entity'] ?? 1);
    $paymentId = admin_insert_existing_columns($pdo, $prefix . 'paiement', [
        'entity' => $entity ?: 1,
        'datec' => date('Y-m-d H:i:s'),
        'datep' => date('Y-m-d'),
        'amount' => $amount,
        'fk_paiement' => $paymentTypeId,
        'num_paiement' => $ref,
        'ref' => $ref,
        'note' => 'Pago registrado desde tienda web para pedido ' . ($order['order_code'] ?: '#' . $orderId),
        'fk_user_creat' => 1,
        'statut' => 0,
    ]);
    admin_insert_existing_columns($pdo, $prefix . 'paiement_facture', [
        'fk_facture' => (int) $order['dolibarr_invoice_id'],
        'fk_paiement' => $paymentId,
        'amount' => $amount,
    ]);

    $bankAccountId = (int) ($method['dolibarr_bank_account_id'] ?? 0);
    if ($bankAccountId > 0 && admin_table_exists($pdo, $prefix . 'bank')) {
        try {
            $bankId = admin_insert_existing_columns($pdo, $prefix . 'bank', [
                'entity' => $entity ?: 1,
                'datec' => date('Y-m-d H:i:s'),
                'datev' => date('Y-m-d'),
                'dateo' => date('Y-m-d'),
                'amount' => $amount,
                'label' => 'Pago tienda web ' . $ref,
                'fk_account' => $bankAccountId,
                'num_releve' => '',
                'rappro' => 0,
                'note' => 'Pedido web ' . ($order['order_code'] ?: '#' . $orderId),
                'fk_type' => 'VIR',
            ]);
            admin_update_existing_columns($pdo, $prefix . 'paiement', ['fk_bank' => $bankId], 'rowid = :id', ['id' => $paymentId]);
            if (admin_table_exists($pdo, $prefix . 'bank_url')) {
                admin_insert_existing_columns($pdo, $prefix . 'bank_url', [
                    'fk_bank' => $bankId,
                    'url_id' => $paymentId,
                    'url' => '/compta/paiement/card.php?id=' . $paymentId,
                    'label' => 'Pago cliente',
                    'type' => 'payment',
                ]);
            }
        } catch (Throwable $bankError) {
            $pdo->prepare('UPDATE sx_store_orders SET dolibarr_sync_note = :note WHERE id = :id')->execute([
                'id' => $orderId,
                'note' => 'Pago registrado, sin movimiento banco: ' . substr($bankError->getMessage(), 0, 180),
            ]);
        }
    }

    admin_update_existing_columns($pdo, $prefix . 'facture', [
        'paye' => 1,
    ], 'rowid = :invoice_id', ['invoice_id' => (int) $order['dolibarr_invoice_id']]);
    $pdo->prepare('UPDATE sx_store_orders SET dolibarr_payment_id = :payment_id, dolibarr_payment_ref = :payment_ref, dolibarr_sync_note = NULL WHERE id = :id')->execute([
        'payment_id' => $paymentId,
        'payment_ref' => $ref,
        'id' => $orderId,
    ]);
    return true;
}

function apply_stock_discount(PDO $pdo, string $prefix, int $orderId): int
{
    $items = $pdo->prepare('SELECT product_id, quantity FROM sx_store_order_items WHERE order_id = :order_id');
    $items->execute(['order_id' => $orderId]);
    $changed = 0;

    foreach ($items->fetchAll() as $item) {
        $productId = (int) $item['product_id'];
        $quantity = (int) $item['quantity'];
        if ($quantity <= 0) {
            continue;
        }

        try {
            $movement = $pdo->prepare('INSERT INTO sx_store_stock_movements (order_id, product_id, quantity, movement_type, note) VALUES (:order_id, :product_id, :quantity, :movement_type, :note)');
            $movement->execute([
                'order_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'movement_type' => 'discount',
                'note' => 'Descuento manual desde tienda web',
            ]);
        } catch (Throwable $duplicate) {
            continue;
        }

        $stock = $pdo->prepare("SELECT rowid, reel, fk_entrepot FROM {$prefix}product_stock WHERE fk_product = :product_id AND reel > 0 ORDER BY reel DESC, rowid ASC");
        $stock->execute(['product_id' => $productId]);
        $remaining = $quantity;
        foreach ($stock->fetchAll() as $row) {
            if ($remaining <= 0) {
                break;
            }
            $taken = min($remaining, max(0, (float) $row['reel']));
            if ($taken <= 0) {
                continue;
            }
            $update = $pdo->prepare("UPDATE {$prefix}product_stock SET reel = GREATEST(0, reel - :quantity) WHERE rowid = :rowid");
            $update->execute(['quantity' => $taken, 'rowid' => (int) $row['rowid']]);
            try {
                admin_insert_existing_columns($pdo, $prefix . 'stock_mouvement', [
                    'datem' => date('Y-m-d H:i:s'),
                    'fk_product' => $productId,
                    'fk_entrepot' => (int) ($row['fk_entrepot'] ?? 0),
                    'value' => -$taken,
                    'type_mouvement' => 2,
                    'label' => 'Salida tienda web pedido #' . $orderId,
                    'inventorycode' => 'SXWEB-' . $orderId,
                    'fk_user_author' => 1,
                ]);
            } catch (Throwable $movementError) {
            }
            $remaining -= $taken;
        }
        if ($remaining <= 0) {
            $changed++;
        }
    }

    return $changed;
}

try {
    $pdo = connect_store($confPath, $prefix);
    ensure_admin_tables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['sx_admin_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
            flash_redirect('Sesion expirada. Intenta de nuevo.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'setup_admin') {
            if (admin_exists($pdo)) {
                flash_redirect('El administrador inicial ya existe.');
            }
            if (!hash_equals(SX_ADMIN_SETUP_CODE, (string) ($_POST['setup_code'] ?? ''))) {
                flash_redirect('Codigo de instalacion invalido.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                flash_redirect('Completa nombre, correo y clave de minimo 8 caracteres.');
            }
            $stmt = $pdo->prepare('INSERT INTO sx_store_users (name, email, password_hash, is_admin) VALUES (:name, :email, :hash, 1)');
            $stmt->execute(['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['sx_admin'] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $email];
            flash_redirect('Administrador creado.');
        }

        if ($action === 'login') {
            $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM sx_store_users WHERE email = :email AND is_admin = 1');
            $stmt->execute(['email' => strtolower(trim((string) ($_POST['email'] ?? '')))]);
            $user = $stmt->fetch();
            if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                flash_redirect('Acceso invalido.');
            }
            $_SESSION['sx_admin'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
            flash_redirect('Bienvenido al panel.');
        }

        if ($action === 'logout') {
            unset($_SESSION['sx_admin']);
            flash_redirect('Sesion cerrada.');
        }

        $admin = $_SESSION['sx_admin'] ?? null;
        if (!$admin) {
            flash_redirect('Debes iniciar sesion.');
        }

        if ($action === 'update_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'nuevo');
            $paymentStatus = (string) ($_POST['payment_status'] ?? 'pendiente');
            $evidenceStatus = (string) ($_POST['payment_evidence_status'] ?? 'por_confirmar');
            $orderStmt = $pdo->prepare('SELECT * FROM sx_store_orders WHERE id = :id');
            $orderStmt->execute(['id' => $orderId]);
            $currentOrder = $orderStmt->fetch();
            if (!$currentOrder) {
                flash_redirect('Pedido no encontrado.');
            }
            if ($status === 'cancelado' && (($currentOrder['payment_status'] ?? '') === 'pagado' || !empty($currentOrder['dolibarr_payment_id']) || !empty($currentOrder['stock_discounted_at']))) {
                flash_redirect('No se puede cancelar un pedido con pago o inventario ya aplicado en Dolibarr.');
            }
            if (($status === 'confirmado' || $paymentStatus === 'pagado') && $evidenceStatus !== 'aprobada') {
                flash_redirect('Aprueba la evidencia de pago antes de confirmar o marcar pagado.');
            }
            if ($status === 'confirmado' || $paymentStatus === 'pagado') {
                if (!dolibarr_invoice_is_validated($pdo, $prefix, (int) ($currentOrder['dolibarr_invoice_id'] ?? 0))) {
                    flash_redirect('Valida primero la factura borrador en Dolibarr antes de confirmar o marcar pagado.');
                }
                if (!dolibarr_invoice_total_matches_order($pdo, $prefix, $currentOrder)) {
                    flash_redirect('El total de la factura Dolibarr no coincide con el total del pedido web.');
                }
            }
            if ($paymentStatus === 'pagado') {
                try {
                    create_dolibarr_payment_for_order($pdo, $prefix, $orderId);
                } catch (Throwable $paymentError) {
                    flash_redirect($paymentError->getMessage());
                }
            }
            try {
                $newEvidenceFiles = admin_save_payment_evidence_uploads('payment_evidence_files', (string) ($currentOrder['order_code'] ?: ('pedido-' . $orderId)));
                admin_insert_order_evidence($pdo, $orderId, $newEvidenceFiles, 'admin', trim((string) ($_POST['payment_evidence_note'] ?? '')));
                if ($newEvidenceFiles && empty($currentOrder['payment_evidence_file'])) {
                    $pdo->prepare('UPDATE sx_store_orders SET payment_evidence_file = :file WHERE id = :id')->execute(['file' => $newEvidenceFiles[0], 'id' => $orderId]);
                }
                if ($newEvidenceFiles && $evidenceStatus === 'por_confirmar') {
                    $evidenceStatus = 'enviada_web';
                }
            } catch (Throwable $evidenceError) {
                flash_redirect($evidenceError->getMessage());
            }
            $stmt = $pdo->prepare("
                UPDATE sx_store_orders
                SET status = :status,
                    payment_status = :payment_status,
                    shipping_status = :shipping_status,
                    tracking_url = :tracking_url,
                    payment_evidence_status = :payment_evidence_status,
                    payment_evidence_note = :payment_evidence_note,
                    store_public_note = :store_public_note,
                    confirmed_at = CASE WHEN :status_confirmed = 'confirmado' AND confirmed_at IS NULL THEN NOW() ELSE confirmed_at END,
                    paid_at = CASE WHEN :payment_paid = 'pagado' AND paid_at IS NULL THEN NOW() ELSE paid_at END,
                    preparing_at = CASE WHEN :shipping_preparing = 'preparando' AND preparing_at IS NULL THEN NOW() ELSE preparing_at END,
                    shipped_at = CASE WHEN :shipping_shipped = 'enviado' AND shipped_at IS NULL THEN NOW() ELSE shipped_at END,
                    delivered_at = CASE WHEN (:shipping_delivered = 'entregado' OR :status_delivered = 'entregado') AND delivered_at IS NULL THEN NOW() ELSE delivered_at END
                    , dolibarr_sync_note = CASE WHEN :status_cancelled = 'cancelado' AND dolibarr_sync_note IS NULL THEN 'Cancelado desde admin antes de pago/inventario' ELSE dolibarr_sync_note END
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'payment_status' => $paymentStatus,
                'shipping_status' => (string) ($_POST['shipping_status'] ?? 'pendiente'),
                'tracking_url' => trim((string) ($_POST['tracking_url'] ?? '')),
                'payment_evidence_status' => $evidenceStatus,
                'payment_evidence_note' => trim((string) ($_POST['payment_evidence_note'] ?? '')),
                'store_public_note' => trim((string) ($_POST['store_public_note'] ?? '')),
                'status_confirmed' => $status,
                'payment_paid' => $paymentStatus,
                'shipping_preparing' => (string) ($_POST['shipping_status'] ?? 'pendiente'),
                'shipping_shipped' => (string) ($_POST['shipping_status'] ?? 'pendiente'),
                'shipping_delivered' => (string) ($_POST['shipping_status'] ?? 'pendiente'),
                'status_delivered' => $status,
                'status_cancelled' => $status,
                'id' => $orderId,
            ]);
            flash_redirect('Pedido actualizado.');
        }

        if ($action === 'answer_question') {
            $stmt = $pdo->prepare('UPDATE sx_store_product_questions SET answer = :answer, status = :status WHERE id = :id');
            $stmt->execute([
                'answer' => trim((string) ($_POST['answer'] ?? '')),
                'status' => trim((string) ($_POST['answer'] ?? '')) !== '' ? 'respondida' : 'pendiente',
                'id' => (int) ($_POST['question_id'] ?? 0),
            ]);
            flash_redirect('Pregunta actualizada.');
        }

        if ($action === 'delete_review') {
            $pdo->prepare('DELETE FROM sx_store_product_reviews WHERE id = :id')->execute(['id' => (int) ($_POST['review_id'] ?? 0)]);
            flash_redirect('Resena eliminada.');
        }

        if ($action === 'change_admin_password') {
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 12) {
                flash_redirect('La nueva clave debe tener minimo 12 caracteres.');
            }
            $pdo->prepare('UPDATE sx_store_users SET password_hash = :hash WHERE id = :id AND is_admin = 1')->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => (int) $admin['id'],
            ]);
            flash_redirect('Clave administrativa actualizada.');
        }

        if ($action === 'save_shipping') {
            $stmt = $pdo->prepare('
                INSERT INTO sx_store_shipping_rates (code, label, price, enabled)
                VALUES (:code, :label, :price, :enabled)
                ON DUPLICATE KEY UPDATE label = VALUES(label), price = VALUES(price), enabled = VALUES(enabled)
            ');
            $stmt->execute([
                'code' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['code'] ?? ''))),
                'label' => trim((string) ($_POST['label'] ?? '')),
                'price' => (float) ($_POST['price'] ?? 0),
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);
            flash_redirect('Tarifa de envio guardada.');
        }

        if ($action === 'save_payment') {
            $stmt = $pdo->prepare('
                INSERT INTO sx_store_payment_methods (code, label, enabled, instructions, payment_url, dolibarr_payment_code, dolibarr_bank_account_id)
                VALUES (:code, :label, :enabled, :instructions, :payment_url, :dolibarr_payment_code, :dolibarr_bank_account_id)
                ON DUPLICATE KEY UPDATE label = VALUES(label), enabled = VALUES(enabled), instructions = VALUES(instructions), payment_url = VALUES(payment_url), dolibarr_payment_code = VALUES(dolibarr_payment_code), dolibarr_bank_account_id = VALUES(dolibarr_bank_account_id)
            ');
            $stmt->execute([
                'code' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['code'] ?? ''))),
                'label' => trim((string) ($_POST['label'] ?? '')),
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'instructions' => trim((string) ($_POST['instructions'] ?? '')),
                'payment_url' => trim((string) ($_POST['payment_url'] ?? '')),
                'dolibarr_payment_code' => strtoupper(trim((string) ($_POST['dolibarr_payment_code'] ?? ''))),
                'dolibarr_bank_account_id' => (int) ($_POST['dolibarr_bank_account_id'] ?? 0) ?: null,
            ]);
            flash_redirect('Metodo de pago guardado.');
        }

        if ($action === 'save_coupon') {
            $stmt = $pdo->prepare('
                INSERT INTO sx_store_coupons (code, label, discount_type, discount_value, enabled)
                VALUES (:code, :label, :discount_type, :discount_value, :enabled)
                ON DUPLICATE KEY UPDATE label = VALUES(label), discount_type = VALUES(discount_type), discount_value = VALUES(discount_value), enabled = VALUES(enabled)
            ');
            $stmt->execute([
                'code' => strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) ($_POST['code'] ?? '')))),
                'label' => trim((string) ($_POST['label'] ?? '')),
                'discount_type' => (string) ($_POST['discount_type'] ?? 'fixed'),
                'discount_value' => (float) ($_POST['discount_value'] ?? 0),
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);
            flash_redirect('Cupon guardado.');
        }

        if ($action === 'save_product_promo') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $promoPrice = max(0, (float) ($_POST['promo_price'] ?? 0));
            if ($productId <= 0 || $promoPrice <= 0) {
                flash_redirect('Selecciona producto y precio promocional.');
            }
            $stmt = $pdo->prepare('
                INSERT INTO sx_store_product_promotions (product_id, promo_price, label, enabled, starts_at, ends_at)
                VALUES (:product_id, :promo_price, :label, :enabled, NULLIF(:starts_at, ""), NULLIF(:ends_at, ""))
                ON DUPLICATE KEY UPDATE promo_price = VALUES(promo_price), label = VALUES(label), enabled = VALUES(enabled), starts_at = VALUES(starts_at), ends_at = VALUES(ends_at)
            ');
            $stmt->execute([
                'product_id' => $productId,
                'promo_price' => $promoPrice,
                'label' => trim((string) ($_POST['label'] ?? 'Oferta')),
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'starts_at' => trim((string) ($_POST['starts_at'] ?? '')),
                'ends_at' => trim((string) ($_POST['ends_at'] ?? '')),
            ]);
            flash_redirect('Promocion de producto guardada.');
        }

        if ($action === 'upload_brand') {
            save_uploaded_asset('logo', 'logo');
            save_uploaded_asset('banner', 'banner');
            flash_redirect('Marca actualizada.');
        }

        if ($action === 'discount_stock') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $check = $pdo->prepare('SELECT * FROM sx_store_orders WHERE id = :id');
            $check->execute(['id' => $orderId]);
            $orderCheck = $check->fetch();
            if (!$orderCheck) {
                flash_redirect('Pedido no encontrado.');
            }
            $evidenceStatus = (string) ($_POST['payment_evidence_status'] ?? ($orderCheck['payment_evidence_status'] ?? 'por_confirmar'));
            try {
                $newEvidenceFiles = admin_save_payment_evidence_uploads('payment_evidence_files', (string) ($orderCheck['order_code'] ?: ('pedido-' . $orderId)));
                admin_insert_order_evidence($pdo, $orderId, $newEvidenceFiles, 'admin', trim((string) ($_POST['payment_evidence_note'] ?? '')));
                if ($newEvidenceFiles && empty($orderCheck['payment_evidence_file'])) {
                    $pdo->prepare('UPDATE sx_store_orders SET payment_evidence_file = :file WHERE id = :id')->execute(['file' => $newEvidenceFiles[0], 'id' => $orderId]);
                    $orderCheck['payment_evidence_file'] = $newEvidenceFiles[0];
                }
                if ($newEvidenceFiles && $evidenceStatus === 'por_confirmar') {
                    $evidenceStatus = 'enviada_web';
                }
            } catch (Throwable $evidenceError) {
                flash_redirect($evidenceError->getMessage());
            }
            if ($evidenceStatus !== 'aprobada') {
                flash_redirect('Aprueba la evidencia de pago antes de confirmar y descontar stock.');
            }
            $shippingStatus = (string) ($_POST['shipping_status'] ?? ($orderCheck['shipping_status'] ?? 'pendiente'));
            if (!in_array($shippingStatus, ['pendiente', 'preparando', 'enviado', 'entregado'], true)) {
                $shippingStatus = 'pendiente';
            }
            if ($shippingStatus === 'pendiente') {
                $shippingStatus = 'preparando';
            }
            if (!dolibarr_invoice_is_validated($pdo, $prefix, (int) ($orderCheck['dolibarr_invoice_id'] ?? 0))) {
                flash_redirect('Valida primero la factura borrador en Dolibarr antes de confirmar y descontar stock.');
            }
            if (!dolibarr_invoice_total_matches_order($pdo, $prefix, $orderCheck)) {
                flash_redirect('El total de la factura Dolibarr no coincide con el total del pedido web.');
            }
            try {
                assert_order_stock_available($pdo, $prefix, $orderId);
                $pdo->beginTransaction();
                create_dolibarr_payment_for_order($pdo, $prefix, $orderId);
                $changed = apply_stock_discount($pdo, $prefix, $orderId);
                $pdo->prepare("
                    UPDATE sx_store_orders
                    SET status = 'confirmado',
                        payment_status = 'pagado',
                        payment_evidence_status = :payment_evidence_status,
                        payment_evidence_note = :payment_evidence_note,
                        tracking_url = :tracking_url,
                        store_public_note = :store_public_note,
                        shipping_status = :shipping_status,
                        confirmed_at = IF(confirmed_at IS NULL, NOW(), confirmed_at),
                        paid_at = IF(paid_at IS NULL, NOW(), paid_at),
                        preparing_at = IF(preparing_at IS NULL, NOW(), preparing_at),
                        shipped_at = CASE WHEN :shipping_shipped = 'enviado' AND shipped_at IS NULL THEN NOW() ELSE shipped_at END,
                        delivered_at = CASE WHEN :shipping_delivered = 'entregado' AND delivered_at IS NULL THEN NOW() ELSE delivered_at END,
                        stock_discounted_at = IF(stock_discounted_at IS NULL, NOW(), stock_discounted_at)
                    WHERE id = :id
                ")->execute([
                    'payment_evidence_status' => $evidenceStatus,
                    'payment_evidence_note' => trim((string) ($_POST['payment_evidence_note'] ?? '')),
                    'tracking_url' => trim((string) ($_POST['tracking_url'] ?? '')),
                    'store_public_note' => trim((string) ($_POST['store_public_note'] ?? '')),
                    'shipping_status' => $shippingStatus,
                    'shipping_shipped' => $shippingStatus,
                    'shipping_delivered' => $shippingStatus,
                    'id' => $orderId,
                ]);
                $pdo->commit();
            } catch (Throwable $stockError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash_redirect($stockError->getMessage());
            }
            flash_redirect('Stock descontado en ' . $changed . ' producto(s).');
        }
    }

    $admin = $_SESSION['sx_admin'] ?? null;
    $hasAdmin = admin_exists($pdo);
    if ($admin && ($_GET['poll'] ?? '') === 'orders') {
        header('Content-Type: application/json; charset=utf-8');
        $latest = $pdo->query('SELECT id, order_code, customer_name, total, created_at FROM sx_store_orders ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        echo json_encode([
            'latest' => $latest,
            'pending' => (int) $pdo->query("SELECT COUNT(*) FROM sx_store_orders WHERE status IN ('nuevo', 'pendiente')")->fetchColumn(),
            'payments' => (int) $pdo->query("SELECT COUNT(*) FROM sx_store_orders WHERE payment_evidence_status <> 'aprobada' AND status <> 'cancelado'")->fetchColumn(),
        ]);
        exit;
    }
    if ($admin && isset($_GET['export'])) {
        $export = (string) $_GET['export'];
        if ($export === 'orders') {
            $rows = $pdo->query('SELECT order_code, customer_name, customer_phone, customer_email, delivery_method, payment_method, subtotal, discount_amount, shipping_amount, total, status, payment_status, shipping_status, payment_evidence_status, payment_evidence_note, payment_evidence_file, store_public_note, dolibarr_invoice_ref, dolibarr_payment_ref, stock_discounted_at, dolibarr_sync_note, tracking_url, created_at FROM sx_store_orders ORDER BY id DESC LIMIT 1000')->fetchAll();
            output_admin_csv('stationx-pedidos.csv', ['pedido', 'cliente', 'telefono', 'email', 'entrega', 'pago', 'subtotal', 'descuento', 'envio', 'total', 'estado', 'estado_pago', 'estado_envio', 'evidencia_pago', 'nota_evidencia', 'archivo_evidencia', 'nota_cliente', 'factura_dolibarr', 'pago_dolibarr', 'stock_descontado', 'nota_sync_erp', 'seguimiento', 'fecha'], $rows);
        }
        if ($export === 'leads') {
            $rows = $pdo->query('SELECT name, email, phone, interest, source, created_at FROM sx_store_leads ORDER BY id DESC LIMIT 1000')->fetchAll();
            output_admin_csv('stationx-contactos.csv', ['nombre', 'email', 'telefono', 'interes', 'origen', 'fecha'], $rows);
        }
    }
    $orders = $admin ? $pdo->query('SELECT * FROM sx_store_orders ORDER BY id DESC LIMIT 60')->fetchAll() : [];
    $orderItems = $admin ? admin_order_items($pdo, $orders) : [];
    $orderEvidence = $admin ? admin_order_evidence($pdo, $orders) : [];
    $questions = $admin ? $pdo->query('SELECT q.*, p.label FROM sx_store_product_questions q LEFT JOIN ' . $prefix . 'product p ON p.rowid = q.product_id ORDER BY q.id DESC LIMIT 60')->fetchAll() : [];
    $reviews = $admin ? $pdo->query('SELECT r.*, u.name, p.label FROM sx_store_product_reviews r JOIN sx_store_users u ON u.id = r.user_id LEFT JOIN ' . $prefix . 'product p ON p.rowid = r.product_id ORDER BY r.id DESC LIMIT 60')->fetchAll() : [];
    $shippingRates = $admin ? $pdo->query('SELECT * FROM sx_store_shipping_rates ORDER BY price ASC, id ASC')->fetchAll() : [];
    $paymentMethods = $admin ? $pdo->query('SELECT * FROM sx_store_payment_methods ORDER BY id ASC')->fetchAll() : [];
    $coupons = $admin ? $pdo->query('SELECT * FROM sx_store_coupons ORDER BY id DESC LIMIT 40')->fetchAll() : [];
    $productPromos = $admin ? $pdo->query('SELECT pr.*, p.ref, p.label, p.price, p.price_ttc FROM sx_store_product_promotions pr LEFT JOIN ' . $prefix . 'product p ON p.rowid = pr.product_id ORDER BY pr.updated_at DESC LIMIT 40')->fetchAll() : [];
    $promoProducts = $admin ? $pdo->query('SELECT rowid, ref, label, price, price_ttc FROM ' . $prefix . 'product WHERE tosell = 1 ORDER BY rowid DESC LIMIT 160')->fetchAll() : [];
    $contentProducts = $admin ? $pdo->query('SELECT rowid, ref, label, description, price, price_ttc FROM ' . $prefix . 'product WHERE tosell = 1 ORDER BY rowid DESC LIMIT 160')->fetchAll() : [];
    $leads = $admin ? $pdo->query('SELECT * FROM sx_store_leads ORDER BY id DESC LIMIT 40')->fetchAll() : [];
    $stats = $admin ? [
        'orders' => (int) $pdo->query('SELECT COUNT(*) FROM sx_store_orders')->fetchColumn(),
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM sx_store_users')->fetchColumn(),
        'questions' => (int) $pdo->query("SELECT COUNT(*) FROM sx_store_product_questions WHERE status = 'pendiente'")->fetchColumn(),
        'payment_evidence' => (int) $pdo->query("SELECT COUNT(*) FROM sx_store_orders WHERE payment_evidence_status <> 'aprobada' AND status <> 'cancelado'")->fetchColumn(),
        'sales' => (float) $pdo->query('SELECT COALESCE(SUM(total), 0) FROM sx_store_orders')->fetchColumn(),
    ] : [];
    $latestOrderId = $admin ? (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM sx_store_orders')->fetchColumn() : 0;
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel Tienda | Station X Tulua</title>
    <link rel="stylesheet" href="/assets/store.css?v=20261014-mobile-audit-2">
    <style>
        body.admin-panel {
            background: #f3f5f8;
            color: #172033;
        }

        .admin-main {
            width: min(1320px, calc(100% - 32px));
            margin: 24px auto 56px;
        }

        .admin-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
            margin-bottom: 18px;
            padding: 20px;
            border: 1px solid #dfe5ee;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }

        .admin-hero h1,
        .admin-login-card h1 {
            margin: 0 0 6px;
            font-size: 30px;
            line-height: 1.1;
            letter-spacing: 0;
        }

        .admin-hero p,
        .admin-login-card p {
            margin: 0;
            color: #58677e;
        }

        .admin-pill {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            background: #eef5ff;
            color: #2165c4;
            font-weight: 700;
            white-space: nowrap;
        }

        .admin-login-wrap {
            display: grid;
            place-items: center;
            min-height: calc(100vh - 160px);
        }

        .admin-login-card {
            width: min(920px, 100%);
            display: grid;
            grid-template-columns: minmax(260px, .9fr) minmax(320px, 1.1fr);
            overflow: hidden;
            border: 1px solid #dfe5ee;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 18px 48px rgba(15, 23, 42, .14);
        }

        .admin-login-side {
            display: grid;
            align-content: end;
            min-height: 420px;
            padding: 30px;
            background: linear-gradient(135deg, #111827, #263246);
            color: #fff;
        }

        .admin-login-side p {
            color: #d7deea;
        }

        .admin-login-form {
            display: grid;
            gap: 14px;
            align-content: center;
            padding: 34px;
        }

        .admin-login-form label,
        .admin-field,
        .admin-field label {
            display: grid;
            gap: 7px;
            color: #4b5b72;
            font-size: 13px;
            font-weight: 700;
        }

        .admin-login-form input,
        .admin-field input,
        .admin-field select,
        .admin-field textarea {
            width: 100%;
            min-height: 44px;
            padding: 0 12px;
            border: 1px solid #d8e0ea;
            border-radius: 6px;
            background: #fff;
            color: #111827;
            font-size: 15px;
        }

        .admin-field textarea {
            min-height: 92px;
            padding-top: 10px;
            resize: vertical;
        }

        .admin-evidence-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 14px;
            border: 1px solid #2563eb;
            border-radius: 6px;
            color: #2563eb;
            font-weight: 800;
            text-decoration: none;
        }

        .admin-help {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
        }

        .admin-evidence-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
            grid-column: 1 / -1;
            gap: 10px;
        }

        .admin-evidence-gallery a {
            display: grid;
            gap: 6px;
            padding: 8px;
            border: 1px solid #d8e0ea;
            border-radius: 8px;
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
        }

        .admin-evidence-gallery img {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 6px;
            background: #e2e8f0;
        }

        .admin-login-form button,
        .admin-card button,
        .admin-logout button {
            min-height: 44px;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            font-weight: 800;
        }

        .admin-stats {
            grid-template-columns: repeat(5, minmax(0, 1fr));
            margin-bottom: 16px;
        }

        .admin-live-alert {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 16px;
            padding: 14px 16px;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e3a8a;
        }

        .admin-live-alert strong,
        .admin-live-alert span {
            display: block;
        }

        .admin-live-alert button {
            min-height: 40px;
            padding: 0 14px;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            font-weight: 800;
        }

        .admin-toast {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 30;
            display: none;
            max-width: min(360px, calc(100vw - 36px));
            padding: 16px;
            border: 1px solid #60a5fa;
            border-radius: 8px;
            background: #0f172a;
            color: #fff;
            box-shadow: 0 18px 44px rgba(15, 23, 42, .28);
        }

        .admin-toast.is-visible {
            display: grid;
            gap: 4px;
        }

        .admin-toast strong,
        .admin-toast span {
            display: block;
        }

        .admin-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 18px 0 12px;
        }

        .admin-toolbar a {
            color: #2563eb;
            font-weight: 800;
        }

        .admin-panel .admin-table {
            margin-bottom: 16px;
            border: 1px solid #dfe5ee;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .05);
        }

        .admin-panel .admin-table > .section-title,
        .admin-panel .admin-table > .settings-list,
        .admin-panel .admin-table > .settings-grid,
        .admin-panel .admin-table > .checkout-form {
            margin-left: 18px;
            margin-right: 18px;
        }

        .admin-panel .admin-table > .section-title:first-child {
            padding-top: 18px;
        }

        .admin-panel .admin-table > .checkout-form {
            grid-template-columns: minmax(240px, 1fr) minmax(180px, 220px);
            align-items: end;
            padding-bottom: 18px;
        }

        .admin-panel .admin-table > .checkout-form button {
            min-height: 44px;
        }

        .admin-orders {
            display: grid;
            gap: 14px;
        }

        .admin-card {
            overflow: hidden;
            border: 1px solid #dfe5ee;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .06);
        }

        .admin-card-head {
            display: grid;
            grid-template-columns: minmax(240px, 1.2fr) minmax(260px, .8fr);
            gap: 18px;
            padding: 16px;
            border-bottom: 1px solid #edf1f6;
        }

        .admin-order-code {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
        }

        .admin-order-code strong {
            font-size: 18px;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            background: #eef2f7;
            color: #475569;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .admin-badge.warn {
            background: #fff7ed;
            color: #c2410c;
        }

        .admin-badge.ok {
            background: #ecfdf5;
            color: #047857;
        }

        .admin-customer {
            display: grid;
            gap: 4px;
            color: #526179;
            font-size: 14px;
        }

        .admin-total {
            display: grid;
            justify-items: end;
            align-content: start;
            gap: 4px;
            color: #526179;
        }

        .admin-total strong {
            color: #111827;
            font-size: 28px;
            line-height: 1;
        }

        .admin-card-body {
            display: grid;
            grid-template-columns: minmax(280px, .9fr) minmax(320px, 1.1fr);
            gap: 16px;
            padding: 16px;
        }

        .admin-items {
            display: grid;
            gap: 10px;
            align-content: start;
        }

        .admin-item {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            padding: 10px;
            border: 1px solid #edf1f6;
            border-radius: 8px;
            background: #fbfcfe;
        }

        .admin-item-image {
            display: grid;
            place-items: center;
            width: 72px;
            height: 72px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #fff;
            color: #94a3b8;
            font-weight: 900;
        }

        .admin-item-image img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .admin-item-info {
            min-width: 0;
        }

        .admin-item-info strong {
            display: block;
            overflow: hidden;
            color: #172033;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .admin-item-info span {
            display: block;
            margin-top: 4px;
            color: #617089;
            font-size: 13px;
        }

        .admin-controls {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .admin-controls .wide {
            grid-column: 1 / -1;
        }

        .admin-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
            grid-column: 1 / -1;
        }

        .admin-stock-action {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 14px 16px;
            border-top: 1px solid #edf1f6;
            background: #fbfcfe;
        }

        .admin-stock-action p {
            margin: 2px 0 0;
            color: #617089;
            font-size: 14px;
        }

        @media (max-width: 900px) {
            .admin-hero,
            .admin-login-card,
            .admin-card-head,
            .admin-card-body,
            .admin-stock-action {
                grid-template-columns: 1fr;
            }

            .admin-total {
                justify-items: start;
            }

            .admin-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .admin-main {
                width: min(100% - 20px, 1320px);
                margin-top: 12px;
            }

            .admin-controls {
                grid-template-columns: 1fr;
            }

            .admin-panel .admin-table > .checkout-form {
                grid-template-columns: 1fr;
            }

            .admin-actions,
            .admin-stock-action button {
                width: 100%;
            }
        }
    </style>
</head>
<body class="admin-panel">
    <header class="topbar">
        <a class="brand" href="/"><span class="brand-mark">SX</span><span><strong>Panel Tienda</strong><small>Station X Tulua</small></span></a>
        <nav class="nav"><a href="/">Tienda</a><a href="/htdocs/">Dolibarr</a></nav>
    </header>
    <main class="admin-main">
        <?php if ($flash): ?><div class="flash"><?php echo e($flash); ?></div><?php endif; ?>
        <?php if ($error): ?>
            <section class="notice"><strong>No se pudo abrir el panel.</strong><span><?php echo e($error); ?></span></section>
        <?php elseif (!$hasAdmin): ?>
            <section class="admin-login-wrap">
                <div class="admin-login-card">
                    <div class="admin-login-side">
                        <span class="admin-pill">Primer acceso</span>
                        <h1>Crear administrador</h1>
                        <p>Activa el panel privado de Station X para gestionar pedidos, pagos y contenido.</p>
                    </div>
                    <form method="post" class="admin-login-form">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="setup_admin">
                        <label>Codigo de instalacion<input name="setup_code" autocomplete="one-time-code" required></label>
                        <label>Nombre<input name="name" autocomplete="name" required></label>
                        <label>Correo<input type="email" name="email" autocomplete="email" required></label>
                        <label>Clave<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
                        <button type="submit">Crear administrador</button>
                    </form>
                </div>
            </section>
        <?php elseif (!$admin): ?>
            <section class="admin-login-wrap">
                <div class="admin-login-card">
                    <div class="admin-login-side">
                        <span class="admin-pill">Panel tienda</span>
                        <h1>Acceso administrador</h1>
                        <p>Pedidos, pagos por confirmar, inventario y contenido comercial en un solo lugar.</p>
                    </div>
                    <form method="post" class="admin-login-form">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="login">
                        <label>Correo administrador<input type="email" name="email" autocomplete="username" required></label>
                        <label>Clave<input type="password" name="password" autocomplete="current-password" required></label>
                        <button type="submit">Entrar al panel</button>
                    </form>
                </div>
            </section>
        <?php else: ?>
            <section class="admin-hero">
                <div>
                    <h1>Panel Tienda</h1>
                    <p>Operacion diaria de pedidos, pagos, evidencia y contenido de productos.</p>
                </div>
                <span class="admin-pill"><?php echo e((string) $admin['email']); ?></span>
            </section>
            <section class="trustbar admin-stats">
                <article><strong><?php echo (int) $stats['orders']; ?></strong><span>Pedidos</span></article>
                <article><strong><?php echo (int) $stats['users']; ?></strong><span>Usuarios</span></article>
                <article><strong><?php echo (int) $stats['questions']; ?></strong><span>Preguntas pendientes</span></article>
                <article><strong><?php echo (int) $stats['payment_evidence']; ?></strong><span>Pagos por revisar</span></article>
                <article><strong><?php echo e(money_value($stats['sales'])); ?></strong><span>Ventas registradas</span></article>
            </section>
            <section class="admin-live-alert" data-order-watch data-latest-order-id="<?php echo (int) $latestOrderId; ?>">
                <div>
                    <strong>Notificaciones de pedidos</strong>
                    <span data-order-watch-status>Vigilando pedidos nuevos en esta pantalla.</span>
                </div>
                <button type="button" data-enable-order-sound>Activar sonido</button>
            </section>
            <div class="admin-toast" data-order-toast role="status" aria-live="polite">
                <strong>Nuevo pedido en la tienda</strong>
                <span data-order-toast-text>Revisa el panel de pedidos.</span>
            </div>
            <form method="post" class="admin-logout"><?php echo csrf_input(); ?><input type="hidden" name="action" value="logout"><button type="submit">Cerrar sesion</button></form>

            <section class="admin-table">
                <div class="admin-toolbar">
                    <div class="section-title"><h2>Pedidos</h2><p>Revisa productos, evidencia, factura ERP y estados antes de descontar inventario.</p></div>
                    <a href="/admin-store.php?export=orders">Exportar pedidos CSV</a>
                </div>
                <div class="admin-orders">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $items = $orderItems[(int) $order['id']] ?? [];
                    $evidence = (string) ($order['payment_evidence_status'] ?? 'por_confirmar');
                    $evidenceClass = $evidence === 'aprobada' ? 'ok' : ($evidence === 'rechazada' ? 'warn' : 'warn');
                    ?>
                    <article class="admin-card">
                        <form method="post" enctype="multipart/form-data">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                            <div class="admin-card-head">
                                <div>
                                    <div class="admin-order-code">
                                        <strong><?php echo e($order['order_code'] ?: '#' . $order['id']); ?></strong>
                                        <span class="admin-badge"><?php echo e(admin_state_label((string) $order['status'])); ?></span>
                                        <span class="admin-badge <?php echo $evidenceClass; ?>"><?php echo e(admin_state_label($evidence)); ?></span>
                                    </div>
                                    <div class="admin-customer">
                                        <span><?php echo e($order['customer_name']); ?> &middot; <?php echo e($order['customer_phone']); ?></span>
                                        <span><?php echo e((string) ($order['customer_email'] ?? '')); ?></span>
                                        <span><?php echo e((string) ($order['delivery_method'] ?? 'Entrega')); ?> &middot; <?php echo e((string) ($order['payment_method'] ?? 'Pago')); ?></span>
                                    </div>
                                </div>
                                <div class="admin-total">
                                    <span>Total pedido</span>
                                    <strong><?php echo e(money_value($order['total'])); ?></strong>
                                    <?php if (!empty($order['dolibarr_invoice_ref'])): ?><span>Factura ERP <?php echo e((string) $order['dolibarr_invoice_ref']); ?></span><?php endif; ?>
                                    <?php if (!empty($order['dolibarr_payment_ref'])): ?><span>Pago ERP <?php echo e((string) $order['dolibarr_payment_ref']); ?></span><?php endif; ?>
                                    <?php if (!empty($order['stock_discounted_at'])): ?><span>Stock ERP <?php echo e((string) $order['stock_discounted_at']); ?></span><?php endif; ?>
                                    <?php if (!empty($order['dolibarr_sync_note'])): ?><span>Nota ERP <?php echo e((string) $order['dolibarr_sync_note']); ?></span><?php endif; ?>
                                    <span><?php echo e((string) ($order['created_at'] ?? '')); ?></span>
                                </div>
                            </div>
                            <div class="admin-card-body">
                                <div class="admin-items">
                                    <?php if (!$items): ?>
                                        <div class="admin-item"><span class="admin-item-image">SX</span><div class="admin-item-info"><strong>Sin items visibles</strong><span>Revisar detalle del pedido</span></div></div>
                                    <?php endif; ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php $image = admin_product_image_url((string) ($item['product_ref'] ?? ''), 180); ?>
                                        <div class="admin-item">
                                            <span class="admin-item-image"><?php if ($image): ?><img src="<?php echo e($image); ?>" alt="<?php echo e((string) $item['product_name']); ?>"><?php else: ?>SX<?php endif; ?></span>
                                            <div class="admin-item-info">
                                                <strong><?php echo e((string) $item['product_name']); ?></strong>
                                                <span><?php echo (int) $item['quantity']; ?> x <?php echo e(money_value($item['price'])); ?> &middot; Ref <?php echo e((string) $item['product_ref']); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="admin-controls">
                                    <label class="admin-field">Pedido<select name="status"><option <?php echo $order['status'] === 'nuevo' ? 'selected' : ''; ?>>nuevo</option><option <?php echo $order['status'] === 'confirmado' ? 'selected' : ''; ?>>confirmado</option><option <?php echo $order['status'] === 'cancelado' ? 'selected' : ''; ?>>cancelado</option><option <?php echo $order['status'] === 'entregado' ? 'selected' : ''; ?>>entregado</option></select></label>
                                    <label class="admin-field">Pago<select name="payment_status"><option <?php echo $order['payment_status'] === 'pendiente' ? 'selected' : ''; ?>>pendiente</option><option <?php echo $order['payment_status'] === 'pagado' ? 'selected' : ''; ?>>pagado</option><option <?php echo $order['payment_status'] === 'fallido' ? 'selected' : ''; ?>>fallido</option></select></label>
                                    <label class="admin-field">Entrega<select name="shipping_status"><option <?php echo $order['shipping_status'] === 'pendiente' ? 'selected' : ''; ?>>pendiente</option><option <?php echo $order['shipping_status'] === 'preparando' ? 'selected' : ''; ?>>preparando</option><option <?php echo $order['shipping_status'] === 'enviado' ? 'selected' : ''; ?>>enviado</option><option <?php echo $order['shipping_status'] === 'entregado' ? 'selected' : ''; ?>>entregado</option></select></label>
                                    <label class="admin-field">Evidencia<select name="payment_evidence_status">
                                        <option value="por_confirmar" <?php echo $evidence === 'por_confirmar' ? 'selected' : ''; ?>>evidencia por confirmar</option>
                                        <option value="pendiente_transferencia" <?php echo $evidence === 'pendiente_transferencia' ? 'selected' : ''; ?>>transferencia pendiente</option>
                                        <option value="enviada_whatsapp" <?php echo $evidence === 'enviada_whatsapp' ? 'selected' : ''; ?>>enviada por WhatsApp</option>
                                        <option value="enviada_web" <?php echo $evidence === 'enviada_web' ? 'selected' : ''; ?>>adjunta en web</option>
                                        <option value="aprobada" <?php echo $evidence === 'aprobada' ? 'selected' : ''; ?>>aprobada</option>
                                        <option value="rechazada" <?php echo $evidence === 'rechazada' ? 'selected' : ''; ?>>rechazada</option>
                                    </select></label>
                                    <label class="admin-field wide">Nota evidencia<input name="payment_evidence_note" value="<?php echo e((string) ($order['payment_evidence_note'] ?? '')); ?>"></label>
                                    <label class="admin-field wide">Adjuntar evidencia de pago<input type="file" name="payment_evidence_files[]" accept="image/*" multiple><span class="admin-help">Puedes subir una o varias fotos/capturas JPG, PNG o WEBP.</span></label>
                                    <?php $evidenceFiles = $orderEvidence[(int) $order['id']] ?? []; ?>
                                    <?php if ($evidenceFiles): ?>
                                        <div class="admin-evidence-gallery">
                                            <?php foreach ($evidenceFiles as $evidenceFile): ?>
                                                <a href="<?php echo e((string) $evidenceFile['file_path']); ?>" target="_blank" rel="noopener">
                                                    <img src="<?php echo e((string) $evidenceFile['file_path']); ?>" alt="Evidencia de pago">
                                                    <span><?php echo e(admin_state_label((string) ($evidenceFile['uploaded_by'] ?? 'web'))); ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <label class="admin-field wide">URL seguimiento<input name="tracking_url" value="<?php echo e((string) ($order['tracking_url'] ?? '')); ?>"></label>
                                    <label class="admin-field wide">Nota visible para cliente<textarea name="store_public_note" placeholder="Ej: Pago recibido, pedido confirmado o cualquier mensaje para el cliente."><?php echo e((string) ($order['store_public_note'] ?? '')); ?></textarea></label>
                                    <div class="admin-actions"><button type="submit" name="action" value="update_order">Guardar cambios</button></div>
                                </div>
                            </div>
                            <div class="admin-stock-action">
                                <div><strong>ERP Dolibarr</strong><p>Registra pago, valida total factura, descuenta stock y deja movimiento de inventario.</p></div>
                                <button type="submit" name="action" value="discount_stock">Confirmar y descontar</button>
                            </div>
                        </form>
                        </form>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Pagos y envios</h2><p>Configura opciones visibles en checkout.</p></div>
                <div class="settings-grid">
                    <form method="post" class="form-card">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="save_shipping">
                        <strong>Tarifa de envio</strong>
                        <input name="code" placeholder="codigo" required>
                        <input name="label" placeholder="Nombre visible" required>
                        <input type="number" name="price" min="0" placeholder="Precio" required>
                        <label><input type="checkbox" name="enabled" checked> Activo</label>
                        <button type="submit">Guardar tarifa</button>
                    </form>
                    <form method="post" class="form-card">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="save_payment">
                        <strong>Metodo de pago</strong>
                        <input name="code" placeholder="codigo" required>
                        <input name="label" placeholder="Nombre visible" required>
                        <textarea name="instructions" placeholder="Instrucciones"></textarea>
                        <input type="url" name="payment_url" placeholder="Link de pago / QR / pasarela">
                        <input name="dolibarr_payment_code" placeholder="Codigo pago Dolibarr: VIR, LIQ, CB">
                        <input type="number" name="dolibarr_bank_account_id" min="0" placeholder="ID cuenta banco/caja Dolibarr">
                        <label><input type="checkbox" name="enabled" checked> Activo</label>
                        <button type="submit">Guardar pago</button>
                    </form>
                    <form method="post" enctype="multipart/form-data" class="form-card">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="upload_brand">
                        <strong>Marca</strong>
                        <label>Logo <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
                        <label>Banner <input type="file" name="banner" accept="image/png,image/jpeg,image/webp"></label>
                        <button type="submit">Subir marca</button>
                    </form>
                </div>
                <div class="settings-list">
                    <?php foreach ($shippingRates as $rate): ?>
                        <span>Envio: <?php echo e($rate['label']); ?> &middot; <?php echo e(money_value($rate['price'])); ?> &middot; <?php echo $rate['enabled'] ? 'activo' : 'inactivo'; ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($paymentMethods as $method): ?>
                        <span>Pago: <?php echo e($method['label']); ?> &middot; <?php echo $method['enabled'] ? 'activo' : 'inactivo'; ?> &middot; Dolibarr <?php echo e((string) ($method['dolibarr_payment_code'] ?? 'auto')); ?><?php if (!empty($method['dolibarr_bank_account_id'])): ?> &middot; Cuenta <?php echo (int) $method['dolibarr_bank_account_id']; ?><?php endif; ?></span>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Promociones y contactos</h2><p>Cupones, leads y capturas desde la tienda.</p></div>
                <div class="settings-list"><a href="/admin-store.php?export=leads">Exportar contactos CSV</a></div>
                <div class="settings-grid">
                    <form method="post" class="form-card">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="save_coupon">
                        <strong>Cupon</strong>
                        <input name="code" placeholder="CODIGO" required>
                        <input name="label" placeholder="Nombre interno" required>
                        <select name="discount_type"><option value="fixed">Valor fijo</option><option value="percent">Porcentaje</option></select>
                        <input type="number" name="discount_value" min="0" placeholder="Descuento" required>
                        <label><input type="checkbox" name="enabled" checked> Activo</label>
                        <button type="submit">Guardar cupon</button>
                    </form>
                    <div class="settings-list">
                        <?php foreach ($coupons as $coupon): ?>
                            <span>Cupon: <?php echo e($coupon['code']); ?> - <?php echo e($coupon['label']); ?> - <?php echo e((string) $coupon['discount_value']); ?> <?php echo e($coupon['discount_type']); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="settings-list">
                        <?php foreach ($leads as $lead): ?>
                            <span>Contacto: <?php echo e($lead['name'] ?: 'Sin nombre'); ?> - <?php echo e($lead['email'] ?: $lead['phone']); ?> - <?php echo e($lead['interest']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Promociones por producto</h2><p>Descuentos reales por producto, sin inventar precios en la vitrina.</p></div>
                <div class="settings-grid">
                    <form method="post" class="form-card">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="save_product_promo">
                        <strong>Crear oferta</strong>
                        <select name="product_id" required>
                            <option value="">Producto</option>
                            <?php foreach ($promoProducts as $product): ?>
                                <option value="<?php echo (int) $product['rowid']; ?>"><?php echo e('#' . $product['rowid'] . ' - ' . display_name((string) $product['label']) . ' - ' . money_value($product['price_ttc'] !== null && $product['price_ttc'] !== '' ? $product['price_ttc'] : $product['price'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="label" placeholder="Etiqueta: Oferta gamer">
                        <input type="number" name="promo_price" min="0" placeholder="Precio promocional" required>
                        <label>Inicia <input type="datetime-local" name="starts_at"></label>
                        <label>Termina <input type="datetime-local" name="ends_at"></label>
                        <label><input type="checkbox" name="enabled" checked> Activa</label>
                        <button type="submit">Guardar oferta</button>
                    </form>
                    <div class="settings-list">
                        <?php foreach ($productPromos as $promo): ?>
                            <span>Oferta: <?php echo e(display_name((string) $promo['label'])); ?> - <?php echo e(money_value($promo['promo_price'])); ?> - <?php echo $promo['enabled'] ? 'activa' : 'inactiva'; ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="settings-list">
                        <strong>Productos a completar fotos</strong>
                        <?php $missingPhotoCount = 0; foreach ($promoProducts as $product): ?>
                            <?php $imageCount = admin_product_image_count((string) $product['ref']); if ($imageCount >= 3) { continue; } $missingPhotoCount++; ?>
                            <?php if ($missingPhotoCount <= 18): ?><span><?php echo e(display_name((string) $product['label'])); ?> - <?php echo $imageCount; ?> foto(s)</span><?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($missingPhotoCount === 0): ?><span>Los productos recientes revisados tienen 3 o mas fotos.</span><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Seguridad</h2><p>Cambia la clave administrativa cuando lo necesites.</p></div>
                <form method="post" class="checkout-form">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="change_admin_password">
                    <input type="password" name="password" placeholder="Nueva clave administrador" minlength="12" required>
                    <button type="submit">Actualizar clave</button>
                </form>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Contenido pendiente</h2><p>Productos que necesitan fotos, descripcion o precio visible.</p></div>
                <div class="settings-list">
                    <?php $pendingContent = 0; foreach ($contentProducts as $product): ?>
                        <?php
                        $imageCount = admin_product_image_count((string) $product['ref']);
                        $description = trim(strip_tags((string) ($product['description'] ?? '')));
                        $price = $product['price_ttc'] !== null && $product['price_ttc'] !== '' ? (float) $product['price_ttc'] : (float) $product['price'];
                        $missing = [];
                        if ($imageCount < 3) { $missing[] = $imageCount . ' foto(s)'; }
                        if ($description === '') { $missing[] = 'descripcion'; }
                        if ($price <= 0) { $missing[] = 'precio'; }
                        if (!$missing) { continue; }
                        $pendingContent++;
                        ?>
                        <?php if ($pendingContent <= 30): ?><span><?php echo e('#' . $product['rowid'] . ' - ' . display_name((string) $product['label']) . ': falta ' . implode(', ', $missing)); ?></span><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($pendingContent === 0): ?><span>Los productos recientes revisados tienen contenido minimo listo.</span><?php endif; ?>
                </div>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Preguntas</h2><p>Responde dudas de productos.</p></div>
                <?php foreach ($questions as $question): ?>
                    <form method="post" class="admin-row question-row">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="answer_question">
                        <input type="hidden" name="question_id" value="<?php echo (int) $question['id']; ?>">
                        <strong><?php echo e($question['label']); ?></strong>
                        <span><?php echo e($question['customer_name']); ?>: <?php echo e($question['question']); ?></span>
                        <textarea name="answer" placeholder="Respuesta"><?php echo e($question['answer']); ?></textarea>
                        <button type="submit">Responder</button>
                    </form>
                <?php endforeach; ?>
            </section>

            <section class="admin-table">
                <div class="section-title"><h2>Resenas</h2><p>Modera comentarios publicados.</p></div>
                <?php foreach ($reviews as $review): ?>
                    <form method="post" class="admin-row review-row">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="delete_review">
                        <input type="hidden" name="review_id" value="<?php echo (int) $review['id']; ?>">
                        <strong><?php echo e($review['label']); ?> &middot; <?php echo (int) $review['rating']; ?>/5</strong>
                        <span><?php echo e($review['name']); ?>: <?php echo e($review['comment']); ?></span>
                        <button type="submit">Eliminar</button>
                    </form>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
    <?php if ($admin): ?>
    <script>
    (() => {
        const watch = document.querySelector('[data-order-watch]');
        if (!watch) return;
        const status = document.querySelector('[data-order-watch-status]');
        const enableSound = document.querySelector('[data-enable-order-sound]');
        const toast = document.querySelector('[data-order-toast]');
        const toastText = document.querySelector('[data-order-toast-text]');
        let latestId = Number(watch.dataset.latestOrderId || 0);
        let audioContext = null;
        let soundEnabled = false;

        const money = (value) => '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Number(value || 0));
        const beep = () => {
            if (!soundEnabled || !audioContext) return;
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.18, audioContext.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.55);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + 0.6);
        };
        const showToast = (order) => {
            if (!toast || !toastText) return;
            toastText.textContent = `${order.order_code || '#' + order.id} - ${order.customer_name || 'Cliente'} - ${money(order.total)}`;
            toast.classList.add('is-visible');
            window.setTimeout(() => toast.classList.remove('is-visible'), 9000);
        };
        const poll = async () => {
            try {
                const response = await fetch('/admin-store.php?poll=orders', { cache: 'no-store', credentials: 'same-origin' });
                if (!response.ok) return;
                const data = await response.json();
                const order = data.latest;
                if (!order || !order.id) return;
                const incomingId = Number(order.id);
                if (incomingId > latestId) {
                    latestId = incomingId;
                    watch.dataset.latestOrderId = String(latestId);
                    if (status) {
                        status.textContent = `Nuevo pedido detectado. Pendientes: ${data.pending}. Pagos por revisar: ${data.payments}.`;
                    }
                    showToast(order);
                    beep();
                }
            } catch (error) {
                if (status) status.textContent = 'No se pudo consultar pedidos nuevos en este momento.';
            }
        };
        if (enableSound) {
            enableSound.addEventListener('click', async () => {
                audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)();
                if (audioContext.state === 'suspended') {
                    await audioContext.resume();
                }
                soundEnabled = true;
                enableSound.textContent = 'Sonido activo';
                if (status) status.textContent = 'Sonido activo. Vigilando pedidos nuevos en esta pantalla.';
                beep();
            });
        }
        window.setInterval(poll, 12000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
