<?php
require dirname(__DIR__) . '/app/Pay/PolyPay/Gateway.php';

/** Require a disallowed test URL to fail before checkout. */
function rejectHostUrl(string $url, $setting): void
{
    try { \App\Pay\PolyPay\HostUrl::validate($url, $setting); }
    catch (\InvalidArgumentException $error) { return; }
    throw new \RuntimeException('Unexpectedly accepted test URL');
}
$origin = 'http://192.168.31.96:18081';
foreach ([0, '0', false] as $disabled) {
    foreach ([$origin . '/order', 'http://shop.example/return', 'https://shop.example/return'] as $url) {
        if (\App\Pay\PolyPay\HostUrl::validate($url, $disabled) !== $url) throw new \RuntimeException('Disabled HTTPS switch rejected a valid URL');
    }
}
foreach ([1, '1', true] as $enabled) rejectHostUrl($origin . '/order', $enabled);
foreach ([
    ['javascript:alert(1)', 0], ['//shop.example/return', 0],
    ['http://user:pass@shop.example/order', 0],
    ['http://shop.example/order#fragment', 0],
    ["http://shop.example/order\r\nX-Test: 1", 0],
    [$origin . '/order', 'invalid'],
] as [$url, $setting]) rejectHostUrl($url, $setting);
$directory = sys_get_temp_dir() . '/polypay-lan-' . bin2hex(random_bytes(8));
$config = ['api_key' => 'fixture-only', 'merchant_id' => 'MCH_TEST', 'usd_rate' => '0.14', 'storage_dir' => $directory, 'force_https' => 0];
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
    rejectHostUrl('http://checkout.polypay.ai/pay/fixture', 1);
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
echo "PASS: HTTPS switch defaults on, off permits HTTP, invalid URLs rejected, checkout remains HTTPS\n";
