<?php
namespace App\Pay\PolyPay\Impl;

require_once dirname(__DIR__) . '/Gateway.php';

class Signature implements \App\Pay\Signature
{
    /** Verify the unchanged HTTP body and publish authenticated fields to the host context. */
    public function verification(array $data, array $config): bool
    {
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (!is_string($body) || strlen($body) > 1048576) throw new \UnexpectedValueException('Invalid webhook body');
        $normalized = $this->verifyRequest($body, \PolyPayPlugin\Support::headers(), $config);
        \Kernel\Util\Context::set(\App\Consts\Pay::DAFA, $normalized);
        return true;
    }

    /** Verify raw bytes before decoding and normalizing the native callback fields. */
    public function verifyRequest(string $body, array $headers, array $config, ?\PolyPay\WebhookPublicKeyProviderInterface $keys = null): array
    {
        $gateway = new \App\Pay\PolyPay\Gateway($config);
        $verifier = new \PolyPay\WebhookV2Handler($config['merchant_id'], 'production',
            new \PolyPay\Nonce\FileNonceStorage($config['storage_dir'] . '/nonces'), $keys);
        return $gateway->accept($verifier->verify($body, $headers));
    }
}
