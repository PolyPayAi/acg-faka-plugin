<?php
// Use a private configuration export, never CLI arguments containing API Keys.
require_once dirname(__DIR__) . '/app/Pay/PolyPay/Gateway.php';
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !in_array($argv[1], ['reconcile', 'cancel'], true)) {
        throw new InvalidArgumentException('Usage: php bin/payment.php reconcile|cancel AC_ORDER_ID');
    }
    $path = getenv('POLYPAY_ACG_CONFIG');
    if (!$path || !is_file($path)) throw new RuntimeException('Set POLYPAY_ACG_CONFIG to a private PHP configuration file');
    $gateway = new \App\Pay\PolyPay\Gateway(require $path);
    echo json_encode($gateway->maintain($argv[2], $argv[1] === 'cancel')) . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
