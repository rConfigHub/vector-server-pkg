<?php

namespace Rconfig\VectorServer\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Outbound source address: an IPv4 or IPv6 address an agent can bind for device connections (RCO-2020).
 */
class DeviceSourceAddress implements ValidationRule
{
    /**
     * @var array<string, array{ranges: list<string>, message: string}>
     */
    private const REJECTED_RANGES = [
        'loopback' => [
            'ranges' => ['127.0.0.0/8', '::1/128'],
            'message' => 'The outbound source address cannot be a loopback address. Use an address on one of the agent host\'s network interfaces.',
        ],
        'link-local' => [
            'ranges' => ['169.254.0.0/16', 'fe80::/10'],
            'message' => 'Link-local addresses are not supported as an outbound source address. Use a global or private address on the agent host.',
        ],
        'unspecified' => [
            'ranges' => ['0.0.0.0/8', '::/128'],
            'message' => 'The outbound source address cannot be an unspecified address such as 0.0.0.0 or ::.',
        ],
        'multicast' => [
            'ranges' => ['224.0.0.0/4', 'ff00::/8'],
            'message' => 'The outbound source address cannot be a multicast address.',
        ],
        'ipv4-mapped' => [
            'ranges' => ['::ffff:0:0/96'],
            'message' => 'Enter the IPv4 address directly, not as an IPv4-mapped IPv6 address.',
        ],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $address = (string) $value;

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            $fail('The outbound source address must be a valid IPv4 or IPv6 address, for example 10.20.30.40 or 2001:db8::10.');

            return;
        }

        foreach (self::REJECTED_RANGES as $rejected) {
            if (IpUtils::checkIp($address, $rejected['ranges'])) {
                $fail($rejected['message']);

                return;
            }
        }
    }
}
