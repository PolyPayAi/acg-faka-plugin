<?php
require dirname(__DIR__) . '/app/Pay/PolyPay/Gateway.php';

/** Require a disallowed test URL to fail before checkout. */
function rejectLanUrl(string $url, string $origin): void
{
    try { \App\Pay\PolyPay\HostUrl::validate($url, $origin); }
    catch (\InvalidArgumentException $error) { return; }
    throw new \RuntimeException('Unexpectedly accepted test URL');
}
$origin = 'http://192.168.31.96:18081';
foreach (['http://192.168.31.96:18081/order?kind=order', 'https://shop.example/return'] as $url) {
    if (\App\Pay\PolyPay\HostUrl::validate($url, $origin) !== $url) throw new \RuntimeException('Expected host URL acceptance');
}
foreach ([
    [$origin . '/order', ''],
    ['http://192.168.31.97:18081/order', $origin],
    ['http://192.168.31.96:18082/order', $origin],
    ['http://192.168.31.96.evil.example:18081/order', $origin],
    ['http://user:pass@192.168.31.96:18081/order', $origin],
    [$origin . '/order#fragment', $origin],
    [$origin . "/order\r\nX-Test: 1", $origin],
    ['http://8.8.8.8/order', 'http://8.8.8.8'],
    ['http://shop.example/order', 'http://shop.example'],
    [$origin . '/order', $origin . '/path'],
] as [$url, $allowed]) rejectLanUrl($url, $allowed);
$directory = sys_get_temp_dir() . '/polypay-lan-' . bin2hex(random_bytes(8));
$config = ['api_key' => 'fixture-only', 'merchant_id' => 'MCH_TEST', 'usd_rate' => '0.14', 'storage_dir' => $directory, 'lan_http_origin' => $origin];
$api = function (string $operation, array $parameters) use ($origin): array {
    if ($operation === 'checkout') {
        if ($parameters['notify_url'] !== $origin . '/callback' || $parameters['redirect_url'] !== $origin . '/return') throw new \RuntimeException('LAN URLs were rewritten');
        return ['checkout_url' => 'https://checkout.polypay.ai/pay/fixture'];
    }
    return ['trade_id' => 'fixture'];
};
try {
    $gateway = new \App\Pay\PolyPay\Gateway($config, $api);
    if ($gateway->checkout('123', '1.00', $origin . '/callback', $origin . '/return') !== 'https://checkout.polypay.ai/pay/fixture') throw new \RuntimeException('LAN checkout failed');
    // The exception applies only to host URLs; PolyPay checkout responses remain HTTPS-only.
    rejectLanUrl('http://checkout.polypay.ai/pay/fixture', $origin);
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
echo "PASS: explicit LAN HTTP origin, exact host/port matching, HTTPS default, public HTTP rejection\n";
