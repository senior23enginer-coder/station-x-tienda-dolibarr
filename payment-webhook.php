<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/store-private.php';

$confPath = __DIR__ . '/htdocs/conf/conf.php';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function connect_store(string $confPath): PDO
{
    require $confPath;
    $host = $dolibarr_main_db_host ?? 'localhost';
    $port = $dolibarr_main_db_port ?? '';
    $name = $dolibarr_main_db_name ?? '';
    $user = $dolibarr_main_db_user ?? '';
    $pass = $dolibarr_main_db_pass ?? '';
    $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $secret = (string) ($input['secret'] ?? ($_SERVER['HTTP_X_SX_SECRET'] ?? ''));
    if (!hash_equals(SX_PAYMENT_WEBHOOK_SECRET, $secret)) {
        respond(401, ['ok' => false, 'error' => 'invalid_secret']);
    }

    $orderCode = trim((string) ($input['order_code'] ?? ''));
    $paymentStatus = trim((string) ($input['payment_status'] ?? 'pagado'));
    $allowed = ['pendiente', 'pagado', 'fallido', 'reembolsado'];
    if ($orderCode === '' || !in_array($paymentStatus, $allowed, true)) {
        respond(422, ['ok' => false, 'error' => 'invalid_payload']);
    }

    $pdo = connect_store($confPath);
    $status = $paymentStatus === 'pagado' ? 'confirmado' : 'nuevo';
    $stmt = $pdo->prepare('UPDATE sx_store_orders SET payment_status = :payment_status, status = :status WHERE order_code = :order_code');
    $stmt->execute([
        'payment_status' => $paymentStatus,
        'status' => $status,
        'order_code' => $orderCode,
    ]);

    respond(200, ['ok' => true, 'updated' => $stmt->rowCount(), 'order_code' => $orderCode, 'payment_status' => $paymentStatus]);
} catch (Throwable $error) {
    respond(500, ['ok' => false, 'error' => 'server_error']);
}
