<?php
/** Minimal host doubles exercise the unmodified controller's HTTP acknowledgement boundary. */
namespace App\Controller\Base\API { class User {} }
namespace App\Service {
    interface Order { /** Process one native callback. */ public function callback(string $id, array $event): string; }
    interface Recharge { /** Process one native recharge. */ public function callback(string $id, array $event): string; }
}
namespace App\Util { class CallbackIpWhitelist { /** Allow only the local fixture. */ public static function enforce(): void {} } }
namespace App\Consts { interface Pay { const DAFA = 'FROM_PAY_DATA'; } }
namespace Kernel\Util {
    class Context {
        private static array $data = [];
        /** Save a request-scoped value. */ public static function set(string $key, $value): void { self::$data[$key] = $value; }
        /** Read a request-scoped value. */ public static function get(string $key) { return self::$data[$key] ?? null; }
        /** Clear request context. */ public static function del(string $key): void { unset(self::$data[$key]); }
    }
}
namespace Kernel\Annotation { class Interceptor { const TYPE_API = 1; } }
namespace Kernel\Context\Interface { interface Request { /** Read unchanged request bytes. */ public function raw(): string; } }
namespace App\Model {
    class Order {
        public static int $status = 0;
        /** Start a related payment lookup. */ public static function with(array $with): self { return new self(); }
        /** Start a direct lookup. */ public static function query(): self { return new self(); }
        /** Bind the fixture order identifier. */ public function where(string $key, string $value): self { return $this; }
        /** Return the native payment record. */ public function first(): object { return (object)['pay' => (object)['handle' => 'PolyPay'], 'status' => self::$status]; }
    }
    class UserRecharge extends Order {}
}
namespace {
    require_once dirname(__DIR__) . '/app/Pay/PolyPay/Vendor/polypay/autoload.php';
    require_once dirname(__DIR__) . '/app/Controller/User/Api/PolyPayNotification.php';
    $service = new class implements \App\Service\Order, \App\Service\Recharge {
        public string $mode = 'success';
        /** Simulate native verification and transactional outcomes independently. */
        public function callback(string $id, array $event): string {
            if ($this->mode === 'invalid') throw new \PolyPay\Exception\SignatureException('Bad signature', 401);
            if ($this->mode !== 'unverified') \Kernel\Util\Context::set(\App\Consts\Pay::DAFA, ['order_no' => $id, 'paid' => 'yes']);
            if ($this->mode !== 'success') throw new \RuntimeException('Native transaction rejected');
            \App\Model\Order::$status = 1;
            return 'OK';
        }
    };
    $request = new class implements \Kernel\Context\Interface\Request {
        /** Return a raw PolyPay JSON event. */ public function raw(): string { return '{"status":2}'; }
    };
    $controller = new \App\Controller\User\Api\PolyPayNotification();
    foreach (['order', 'recharge'] as $property) {
        $reflection = new \ReflectionProperty($controller, $property);
        $reflection->setAccessible(true); $reflection->setValue($controller, $service);
    }
    $_GET = ['_PARAMETER' => ['123'], 'kind' => 'order'];
    foreach ([['success', 0, 200], ['duplicate', 1, 200], ['failed', 0, 503], ['unverified', 1, 503], ['invalid', 0, 401]] as [$mode, $paid, $expected]) {
        $service->mode = $mode; \App\Model\Order::$status = $paid;
        $result = $controller->callback($request);
        if (http_response_code() !== $expected || ($expected === 200 && $result !== 'OK')) throw new \RuntimeException('HTTP boundary failed: ' . $mode);
        if (\Kernel\Util\Context::get(\App\Consts\Pay::DAFA) !== null) throw new \RuntimeException('Context leaked');
    }
    $_GET['kind'] = 'recharge'; $service->mode = 'success'; \App\Model\Order::$status = 0;
    if ($controller->callback($request) !== 'OK' || http_response_code() !== 200) throw new \RuntimeException('Recharge boundary failed');
    echo "PASS: controller success, verified duplicate, native failure, unverified paid, bad signature, recharge, context isolation\n";
}
