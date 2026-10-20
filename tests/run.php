<?php
require_once dirname(__DIR__) . '/app/Pay/PolyPay/Gateway.php';
use App\Pay\PolyPay\Gateway;

/** Fail a scenario when an invariant changes. */
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
/** Require an invalid input or transition to fail. */
function rejects(callable $action): void { try { $action(); } catch (Throwable $error) { return; } throw new RuntimeException('Expected rejection'); }
$dir = sys_get_temp_dir() . '/polypay-acg-test-' . bin2hex(random_bytes(8));
$config = ['api_key' => 'fixture-only', 'merchant_id' => 'MCH_TEST', 'environment' => 'sandbox', 'usd_rate' => '0.14', 'storage_dir' => $dir];
$creates = 0; $id = ''; $remoteStatus = 1;
$api = function ($op, $params) use (&$creates, &$id, &$remoteStatus): array {
    if ($op === 'checkout') {
        ++$creates; $id = $params['mch_order_id'];
        check(strlen($id) === 32 && $params['amount'] === '1.40', 'Checkout contract');
        return ['checkout_url' => 'https://checkout.polypay.ai/pay/T1'];
    }
    if ($op === 'cancel') { $remoteStatus = 4; return []; }
    return ['trade_id' => 'T1', 'status' => $remoteStatus];
};
$gateway = new Gateway($config, $api);
$url = $gateway->checkout('123456', '10.00', 'https://shop.example/user/api/order/callback.123456', 'https://shop.example/order');
check($gateway->checkout('123456', '10.00', 'https://shop.example/user/api/order/callback.123456', 'https://shop.example/order') === $url && $creates === 1, 'Native retry');
$event = ['order_no' => $id, 'status' => 2, 'fiat_amount' => '1.40', 'environment' => 'sandbox', 'event_id' => 'evt1'];
$bad = $event; $bad['fiat_amount'] = '10.00'; rejects(function () use ($gateway, $bad) { $gateway->accept($bad); });
$bad = $event; $bad['status'] = 5; rejects(function () use ($gateway, $bad) { $gateway->accept($bad); });
$changed = $config; $changed['usd_rate'] = '0.20';
$normalized = (new Gateway($changed, $api))->accept($event);
check($normalized === ['order_no' => '123456', 'amount' => '10.00', 'paid' => 'yes'], 'Host amount uses immutable CNY snapshot');
check($gateway->accept($event) === $normalized, 'Duplicate normalization leaves host fulfillment idempotency intact');
$bad = $config; $bad['merchant_id'] = 'OTHER'; rejects(function () use ($bad, $api, $event) { (new Gateway($bad, $api))->accept($event); });
$remoteStatus = 2; check($gateway->maintain($id, true)['state'] === 'paid', 'Do not cancel a paid payment');
$gateway->checkout('789', '10.00', 'https://shop.example/user/api/rechargeNotification/callback.789', 'https://shop.example/user/recharge/index');
$remoteStatus = 1; check($gateway->maintain($id, true)['state'] === 'cancelled', 'Native cancellation');
// Exercise the actual native Signature adapter against an Ed25519-signed raw payload.
require_once __DIR__ . '/host-interface.php';
require_once dirname(__DIR__) . '/app/Pay/PolyPay/Impl/Signature.php';
$pair = sodium_crypto_sign_keypair(); $public = sodium_crypto_sign_publickey($pair); $secret = sodium_crypto_sign_secretkey($pair);
$keys = new class($public) implements \PolyPay\WebhookPublicKeyProviderInterface {
    private string $key;
    /** Inject the local test key without network access. */
    public function __construct(string $key) { $this->key = $key; }
    /** Return only the known fixture key. */
    public function getPublicKey(string $id): string { if ($id !== 'test') throw new RuntimeException('Unknown key'); return $this->key; }
};
$body = json_encode($event); $timestamp = (string)time(); $nonce = bin2hex(random_bytes(16));
$payload = implode("\n", ['polypay-webhook-v2', 'test', $timestamp, $nonce, 'MCH_TEST', 'sandbox', $body]);
$signature = rtrim(strtr(base64_encode(sodium_crypto_sign_detached($payload, $secret)), '+/', '-_'), '=');
$headers = ['X-Webhook-Signature-Version' => 'v2', 'X-Webhook-Key-Id' => 'test', 'X-Timestamp' => $timestamp,
    'X-Nonce' => $nonce, 'X-Webhook-Merchant-Id' => 'MCH_TEST', 'X-Webhook-Environment' => 'sandbox', 'X-Webhook-Signature-V2' => $signature];
$adapter = new \App\Pay\PolyPay\Impl\Signature();
rejects(function () use ($adapter, $body, $headers, $config, $keys) { $adapter->verifyRequest($body . ' ', $headers, $config, $keys); });
check($adapter->verifyRequest($body, $headers, $config, $keys) === $normalized, 'Native signed payload mapping');
rejects(function () use ($adapter, $body, $headers, $config, $keys) { $adapter->verifyRequest($body, $headers, $config, $keys); });
echo "PASS: native checkout, order/recharge separation, pricing snapshot, wrong merchant/amount/status, duplicates, cancellation\n";
