<?php
declare(strict_types=1);
namespace App\Plugin\PolyPayCallback\Controller;

use App\Controller\Base\API\User;
use App\Interceptor\Waf;
use App\Util\CallbackIpWhitelist;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Util\Context;

#[Interceptor(Waf::class, Interceptor::TYPE_API)]
class Notification extends User
{
    #[Inject]
    private \App\Service\Order $order;
    #[Inject]
    private \App\Service\Recharge $recharge;

    /** Keep native fulfillment while translating host errors into reliable HTTP acknowledgements. */
    public function callback(Request $request): string
    {
        Context::del(\App\Consts\Pay::DAFA);
        try {
            CallbackIpWhitelist::enforce();
            $id = (string)($_GET['_PARAMETER'][0] ?? '');
            $kind = (string)($_GET['kind'] ?? '');
            if (!preg_match('/^\d{1,64}$/D', $id) || !in_array($kind, ['order', 'recharge'], true)) {
                http_response_code(400);
                return 'Invalid payment reference';
            }
            $body = $request->raw();
            if (strlen($body) > 1048576) { http_response_code(413); return 'Body too large'; }
            $event = json_decode($body, true);
            if (!is_array($event)) { http_response_code(400); return 'Invalid JSON'; }
            $model = $kind === 'recharge' ? \App\Model\UserRecharge::class : \App\Model\Order::class;
            $local = $model::with(['pay'])->where('trade_no', $id)->first();
            if (!$local || !$local->pay || $local->pay->handle !== 'PolyPay') {
                http_response_code(409);
                return 'Unknown PolyPay payment';
            }
            try {
                // The native service invokes the plugin Signature adapter once, then commits fulfillment.
                $result = $kind === 'recharge' ? $this->recharge->callback($id, $event) : $this->order->callback($id, $event);
                if ($result !== 'OK') throw new \RuntimeException('Unexpected native acknowledgement');
            } catch (\Throwable $error) {
                $verified = Context::get(\App\Consts\Pay::DAFA);
                $current = $model::query()->where('trade_no', $id)->first();
                // A new authenticated delivery after a committed payment is safe to acknowledge.
                if (!is_array($verified) || ($verified['paid'] ?? '') !== 'yes'
                    || ($verified['order_no'] ?? '') !== $id || !$current || (int)$current->status !== 1) throw $error;
            }
            http_response_code(200);
            return 'OK';
        } catch (\Throwable $error) {
            $status = $error instanceof \PolyPay\Exception\SignatureException ? $error->getHttpStatus() : 503;
            http_response_code($status);
            return 'PolyPay callback rejected';
        } finally {
            Context::del(\App\Consts\Pay::DAFA);
        }
    }
}
