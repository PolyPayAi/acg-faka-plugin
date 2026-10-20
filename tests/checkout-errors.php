<?php
/** Verify that invalid checkout URLs reach the host JSON error boundary. */
namespace Kernel\Exception { class JSONException extends \Exception {} }
namespace Kernel\Util {
    class Plugin {
        /** Supply the enabled callback companion contract. */
        public static function getPlugin(string $name, bool $cache): array { return ['PLUGIN_CONFIG' => ['STATUS' => 1]]; }
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
    interface Pay { /** Build a native payment result. */ public function trade(): \App\Entity\PayEntity; }
}
namespace App\Entity { class PayEntity {} }
namespace {
    $path = getenv('POLYPAY_PLUGIN_DIR') ?: dirname(__DIR__) . '/app/Pay/PolyPay';
    require $path . '/Impl/Pay.php';
    $directory = sys_get_temp_dir() . '/polypay-url-errors-' . bin2hex(random_bytes(8));
    $pay = new \App\Pay\PolyPay\Impl\Pay();
    $pay->config = ['api_key' => 'fixture-only', 'merchant_id' => 'MCH_TEST', 'usd_rate' => '0.14', 'storage_dir' => $directory];
    $pay->tradeNo = '999000001'; $pay->amount = 1;
    try {
        foreach ([['http://shop.example/callback', 'https://shop.example/return'], ['https://shop.example/callback', 'http://shop.example/return']] as [$callback, $return]) {
            $pay->callbackUrl = $callback; $pay->returnUrl = $return;
            try { $pay->trade(); throw new \RuntimeException('HTTP URL accepted'); }
            catch (\Kernel\Exception\JSONException $error) {
                if (strpos($error->getMessage(), 'HTTPS') === false || !($error->getPrevious() instanceof \InvalidArgumentException)) throw $error;
            }
        }
    } finally {
        if (is_dir($directory)) rmdir($directory);
    }
    echo "PASS: HTTP callback and return URLs produce host JSON errors before API checkout\n";
}
