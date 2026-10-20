<?php
/** Exercise the actual payment adapter without a network checkout. */
namespace Kernel\Util {
    class Plugin {
        public static ?array $companion = null;
        /** Return the fixture companion installation state. */
        public static function getPlugin(string $name, bool $cache): ?array { return self::$companion; }
    }
}
namespace App\Plugin\PolyPayCallback\Controller { class Notification {} }
namespace App\Pay {
    abstract class Base {
        public array $config;
        public string $callbackUrl;
        public string $returnUrl;
        public string $tradeNo;
        public float $amount;
    }
    interface Pay { const TYPE_REDIRECT = 1; /** Create a native checkout entity. */ public function trade(): \App\Entity\PayEntity; }
}
namespace App\Entity {
    class PayEntity {
        public string $url;
        /** Accept the host redirect type. */ public function setType(int $type): void {}
        /** Save the returned checkout URL. */ public function setUrl(string $url): void { $this->url = $url; }
    }
}
namespace {
    require dirname(__DIR__) . '/app/Pay/PolyPay/Impl/Pay.php';
    $pay = new \App\Pay\PolyPay\Impl\Pay();
    foreach ([null, ['PLUGIN_CONFIG' => ['STATUS' => 0]]] as $state) {
        \Kernel\Util\Plugin::$companion = $state;
        try { $pay->trade(); throw new \LogicException('Missing companion accepted'); }
        catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'Install and enable PolyPayCallback before creating payments') throw $error;
        }
    }
    \Kernel\Util\Plugin::$companion = ['PLUGIN_CONFIG' => ['STATUS' => 1]];
    $directory = sys_get_temp_dir() . '/polypay-trade-' . bin2hex(random_bytes(8));
    $pay->config = ['api_key' => 'fixture-only', 'merchant_id' => 'MCH_TEST', 'usd_rate' => '0.14', 'storage_dir' => $directory];
    $pay->returnUrl = 'https://shop.example/order'; $pay->amount = 10.00;
    $creates = 0;
    $api = function (string $operation, array $parameters) use (&$creates): array {
        if ($operation === 'checkout') { ++$creates; return ['checkout_url' => 'https://checkout.polypay.ai/pay/fixture']; }
        return ['trade_id' => 'fixture'];
    };
    try {
        foreach (['order', 'recharge'] as $kind) {
            $pay->tradeNo = $kind === 'order' ? '123' : '456';
            $controller = $kind === 'order' ? 'order' : 'rechargeNotification';
            $pay->callbackUrl = 'https://shop.example/user/api/' . $controller . '/callback.' . $pay->tradeNo;
            $expected = 'https://shop.example/plugin/PolyPayCallback/notification/callback.' . $pay->tradeNo . '?kind=' . $kind;
            $gateway = new \App\Pay\PolyPay\Gateway($pay->config, $api);
            $gateway->checkout($pay->tradeNo, '10.00', $expected, $pay->returnUrl);
            if ($pay->trade()->url !== 'https://checkout.polypay.ai/pay/fixture') throw new \RuntimeException('Companion callback URL changed');
        }
        if ($creates !== 2) throw new \RuntimeException('Cached native payment was recreated');
    } finally {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
    echo "PASS: missing/disabled companion blocks checkout; native order/recharge use companion URLs\n";
}
