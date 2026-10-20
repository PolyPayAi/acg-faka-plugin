<?php
namespace App\Pay\PolyPay\Impl;

require_once dirname(__DIR__) . '/Gateway.php';

class Pay extends \App\Pay\Base implements \App\Pay\Pay
{
    /** Return a native redirect payment entity; the host retains inventory and fulfillment. */
    public function trade(): \App\Entity\PayEntity
    {
        try {
            return $this->checkout();
        } catch (\InvalidArgumentException $error) {
            // Expected input errors must use the host's JSON boundary, not its HTTP 500 HTML page.
            throw new \Kernel\Exception\JSONException('PolyPay: ' . $error->getMessage(), 0, $error);
        }
    }

    /** Validate the payment setup and build the hosted checkout redirect. */
    private function checkout(): \App\Entity\PayEntity
    {
        $companion = \Kernel\Util\Plugin::getPlugin('PolyPayCallback', false);
        if ((int)($companion['PLUGIN_CONFIG']['STATUS'] ?? 0) !== 1
            || !class_exists(\App\Plugin\PolyPayCallback\Controller\Notification::class)) {
            throw new \RuntimeException('Install and enable PolyPayCallback before creating payments');
        }
        $gateway = new \App\Pay\PolyPay\Gateway($this->config);
        $parts = parse_url($this->callbackUrl);
        \App\Pay\PolyPay\HostUrl::validate($this->callbackUrl, (string)($this->config['lan_http_origin'] ?? ''));
        $kind = strpos($parts['path'] ?? '', '/rechargeNotification/') !== false ? 'recharge' : 'order';
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $callback = $origin . '/plugin/PolyPayCallback/notification/callback.' . rawurlencode($this->tradeNo) . '?kind=' . $kind;
        $url = $gateway->checkout($this->tradeNo, number_format($this->amount, 2, '.', ''), $callback, $this->returnUrl);
        $entity = new \App\Entity\PayEntity();
        $entity->setType(\App\Pay\Pay::TYPE_REDIRECT);
        $entity->setUrl($url);
        return $entity;
    }
}
