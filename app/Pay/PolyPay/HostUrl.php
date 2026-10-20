<?php
namespace App\Pay\PolyPay;

require_once __DIR__ . '/Support.php';

/** Validate host URLs with an explicit, narrowly scoped LAN testing exception. */
class HostUrl
{
    /** Accept HTTPS, or HTTP on the exact configured RFC1918 IPv4 origin. */
    public static function validate(string $url, string $lanOrigin = ''): string
    {
        $parts = parse_url($url);
        if (is_array($parts) && ($parts['scheme'] ?? '') === 'https') return \PolyPayPlugin\Support::url($url);
        if ($lanOrigin === '') return \PolyPayPlugin\Support::url($url);
        $origin = parse_url($lanOrigin);
        if (!is_array($origin) || ($origin['scheme'] ?? '') !== 'http'
            || !self::isPrivateIpv4($origin['host'] ?? '')
            || array_diff(array_keys($origin), ['scheme', 'host', 'port'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $lanOrigin)) {
            throw new \InvalidArgumentException('LAN HTTP origin must be an exact private IPv4 origin without a path');
        }
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
            || ($parts['host'] ?? '') !== $origin['host']
            || ($parts['port'] ?? 80) !== ($origin['port'] ?? 80)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('HTTP checkout URL must match the configured LAN origin');
        }
        return $url;
    }

    /** Restrict the exception to literal addresses in the three RFC1918 ranges. */
    private static function isPrivateIpv4(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        $octets = array_map('intval', explode('.', $host));
        return $octets[0] === 10 || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    }
}
