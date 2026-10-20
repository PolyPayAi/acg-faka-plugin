<?php
namespace App\Pay\PolyPay;

require_once __DIR__ . '/Vendor/polypay/autoload.php';
require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/HostUrl.php';

use PolyPayPlugin\Support;
use PolyPayPlugin\Store;
use PolyPayPlugin\Api;

class Gateway
{
    private array $config;
    private Store $store;
    private $api;

    /** Bind the native adapter to one merchant and a private durable snapshot store. */
    public function __construct(array $config, ?callable $api = null)
    {
        foreach (['api_key', 'merchant_id', 'usd_rate', 'storage_dir'] as $key) {
            if (!is_string($config[$key] ?? null) || $config[$key] === '') throw new \RuntimeException('Configure PolyPay ' . $key);
        }
        $config['environment'] = 'production';
        $this->config = $config;
        $this->store = new Store($config['storage_dir']);
        $this->api = $api ?? [new Api($config['api_key']), 'request'];
    }

    /** Create hosted checkout without changing host tables, routes or order processing. */
    public function checkout(string $order, string $amount, string $notify, string $return): string
    {
        $amount = Support::amount($amount);
        HostUrl::validate($notify, ($this->config['force_https'] ?? 1));
        HostUrl::validate($return, ($this->config['force_https'] ?? 1));
        $id = 'AC' . substr(hash('sha256', $this->config['merchant_id'] . '|' . $notify . '|' . $order), 0, 30);
        return $this->store->locked($id, function (array $record) use ($id, $order, $amount, $notify, $return): string {
            if ($record) {
                if ($record['amount'] !== $amount || $record['return_url'] !== $return || $record['notify_url'] !== $notify
                    || $record['environment'] !== $this->config['environment']) throw new \UnexpectedValueException('Payment attempt parameters changed');
                if (in_array($record['state'], ['paid', 'cancelled', 'expired'], true)) throw new \UnexpectedValueException('Payment attempt is terminal');
                if (!empty($record['checkout_url'])) return $record['checkout_url'];
            } else {
                $record = ['mch_order_id' => $id, 'host_order' => $order, 'amount' => $amount,
                    'usd_amount' => Support::usd($amount, $this->config['usd_rate']), 'rate' => $this->config['usd_rate'],
                    'merchant_id' => $this->config['merchant_id'], 'environment' => $this->config['environment'],
                    'notify_url' => $notify, 'return_url' => $return, 'state' => 'creating'];
                $this->store->save($id, $record);
            }
            $result = ($this->api)('checkout', ['mch_order_id' => $id, 'amount' => $record['usd_amount'],
                'notify_url' => $notify, 'redirect_url' => $return]);
            if (empty($result['checkout_url'])) throw new \RuntimeException('Incomplete checkout response');
            $detail = ($this->api)('detail', ['mch_order_id' => $id]);
            if (empty($detail['trade_id'])) throw new \RuntimeException('Order lookup did not return a trade ID');
            $record['trade_id'] = (string)$detail['trade_id'];
            $record['checkout_url'] = Support::url($result['checkout_url']);
            $record['state'] = 'pending';
            $this->store->save($id, $record);
            return $record['checkout_url'];
        });
    }

    /** Map a verified paid event to the host's original CNY snapshot for native fulfillment. */
    public function accept(array $event): array
    {
        $id = $event['order_no'] ?? '';
        if (!is_string($id) || !preg_match('/^AC[a-f0-9]{30}$/D', $id)) throw new \UnexpectedValueException('Invalid PolyPay order');
        return $this->store->locked($id, function (array $record) use ($id, $event): array {
            if (!$record || ($record['merchant_id'] ?? '') !== $this->config['merchant_id']
                || ($record['environment'] ?? '') !== $this->config['environment']) throw new \UnexpectedValueException('Unknown PolyPay payment');
            Support::paid($event, $record);
            $record['state'] = 'paid';
            $record['event_id'] = $event['event_id'];
            $this->store->save($id, $record);
            return ['order_no' => $record['host_order'], 'amount' => $record['amount'], 'paid' => 'yes'];
        });
    }

    /** Inspect or cancel a pending payment; fulfillment remains owned by the host callback. */
    public function maintain(string $id, bool $cancel): array
    {
        return $this->store->locked($id, function (array $record) use ($id, $cancel): array {
            if (empty($record['trade_id']) || $record['merchant_id'] !== $this->config['merchant_id']
                || $record['environment'] !== $this->config['environment']) throw new \RuntimeException('Unknown payment');
            if ($record['state'] === 'paid') return ['state' => 'paid', 'fulfillment' => 'host_webhook'];
            $remote = ($this->api)('detail', ['trade_id' => $record['trade_id']]);
            if ($cancel && in_array($remote['status'] ?? null, [0, 1], true)) {
                try { ($this->api)('cancel', ['trade_id' => $record['trade_id']]); }
                catch (\Throwable $error) { /* Query below resolves ambiguous cancellation responses. */ }
                $remote = ($this->api)('detail', ['trade_id' => $record['trade_id']]);
            }
            $states = [0 => 'pending', 1 => 'pending', 2 => 'paid', 3 => 'expired', 4 => 'cancelled', 6 => 'confirming', 7 => 'paid'];
            $state = $states[$remote['status'] ?? -1] ?? 'unknown';
            if (in_array($state, ['expired', 'cancelled'], true)) {
                $record['state'] = $state;
                $this->store->save($id, $record);
            }
            if ($cancel && !in_array($state, ['expired', 'cancelled', 'paid'], true)) throw new \RuntimeException('Cancellation did not converge');
            return ['state' => $state, 'fulfillment' => 'host_webhook'];
        });
    }
}
