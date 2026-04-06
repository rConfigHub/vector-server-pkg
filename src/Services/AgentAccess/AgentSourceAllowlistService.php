<?php

namespace Rconfig\VectorServer\Services\AgentAccess;

use Rconfig\VectorServer\Models\Agent;
use Symfony\Component\HttpFoundation\IpUtils;

class AgentSourceAllowlistService
{
    public function __construct(private readonly AgentDnsResolverService $dnsResolver) {}

    /**
     * @return array{normalized_input: ?string, entries: array<int, array{type: string, value: string}>, errors: array<int, string>}
     */
    public function normalizeRawInput(?string $rawInput): array
    {
        $trimmedInput = is_string($rawInput) ? trim($rawInput) : null;
        if ($trimmedInput === null || $trimmedInput === '') {
            return [
                'normalized_input' => null,
                'entries' => [],
                'errors' => [],
            ];
        }

        $rawTokens = array_map(static fn($token) => trim($token), explode(',', $trimmedInput));
        $entries = [];
        $errors = [];
        $seen = [];

        foreach ($rawTokens as $token) {
            if ($token === '') {
                $errors[] = 'Empty value is not allowed in the source allowlist.';
                continue;
            }

            $entry = $this->parseToken($token);
            if ($entry === null) {
                $errors[] = sprintf('Invalid source value "%s". Use IP, CIDR, or DNS hostname.', $token);
                continue;
            }

            $entryKey = $entry['type'] . '|' . $entry['value'];
            if (isset($seen[$entryKey])) {
                continue;
            }

            $seen[$entryKey] = true;
            $entries[] = $entry;
        }

        if (! empty($errors)) {
            return [
                'normalized_input' => $trimmedInput,
                'entries' => [],
                'errors' => $errors,
            ];
        }

        return [
            'normalized_input' => implode(', ', array_map(static fn($entry) => $entry['value'], $entries)),
            'entries' => $entries,
            'errors' => [],
        ];
    }

    /**
     * @return array{allowed: bool, reason: string, match_type: ?string, matched_value: ?string}
     */
    public function isRequestIpAllowed(Agent $agent, string $requestIp): array
    {
        if (! filter_var($requestIp, FILTER_VALIDATE_IP)) {
            return [
                'allowed' => false,
                'reason' => 'Invalid request IP',
                'match_type' => null,
                'matched_value' => null,
            ];
        }

        $entries = $this->extractEntriesFromAgent($agent);
        if (empty($entries)) {
            return [
                'allowed' => false,
                'reason' => 'No source allowlist configured',
                'match_type' => null,
                'matched_value' => null,
            ];
        }

        foreach ($entries as $entry) {
            if ($entry['type'] === 'ip' && $this->ipsEqual($entry['value'], $requestIp)) {
                return [
                    'allowed' => true,
                    'reason' => 'Matched source IP entry',
                    'match_type' => 'ip',
                    'matched_value' => $entry['value'],
                ];
            }

            if ($entry['type'] === 'cidr' && IpUtils::checkIp($requestIp, $entry['value'])) {
                return [
                    'allowed' => true,
                    'reason' => 'Matched CIDR entry',
                    'match_type' => 'cidr',
                    'matched_value' => $entry['value'],
                ];
            }

            if ($entry['type'] === 'hostname') {
                $resolution = $this->dnsResolver->resolve($entry['value']);
                if (! $resolution['ok']) {
                    continue;
                }

                foreach ($resolution['ips'] as $resolvedIp) {
                    if ($this->ipsEqual($resolvedIp, $requestIp)) {
                        return [
                            'allowed' => true,
                            'reason' => 'Matched hostname DNS entry',
                            'match_type' => 'hostname',
                            'matched_value' => $entry['value'],
                        ];
                    }
                }
            }
        }

        return [
            'allowed' => false,
            'reason' => 'Unauthorized IP address',
            'match_type' => null,
            'matched_value' => null,
        ];
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    public function extractEntriesFromAgent(Agent $agent): array
    {
        if (is_array($agent->srcip_allowlist) && ! empty($agent->srcip_allowlist)) {
            return array_values(array_filter(array_map(function ($entry) {
                if (! is_array($entry) || empty($entry['type']) || empty($entry['value'])) {
                    return null;
                }

                return [
                    'type' => (string) $entry['type'],
                    'value' => (string) $entry['value'],
                ];
            }, $agent->srcip_allowlist)));
        }

        return $this->normalizeRawInput($agent->srcip)['entries'];
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private function parseToken(string $token): ?array
    {
        if (filter_var($token, FILTER_VALIDATE_IP)) {
            return ['type' => 'ip', 'value' => $this->normalizeIp($token)];
        }

        if ($this->isValidCidr($token)) {
            return ['type' => 'cidr', 'value' => strtolower($token)];
        }

        if ($this->isValidHostname($token)) {
            return ['type' => 'hostname', 'value' => strtolower($token)];
        }

        return null;
    }

    private function isValidCidr(string $token): bool
    {
        if (! str_contains($token, '/')) {
            return false;
        }

        [$ipPart, $prefixPart] = explode('/', $token, 2);
        if ($ipPart === '' || $prefixPart === '' || ! ctype_digit($prefixPart)) {
            return false;
        }

        $prefix = (int) $prefixPart;
        if (filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $prefix >= 0 && $prefix <= 32;
        }

        if (filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $prefix >= 0 && $prefix <= 128;
        }

        return false;
    }

    private function isValidHostname(string $token): bool
    {
        if (strlen($token) > 253) {
            return false;
        }

        if (str_contains($token, '://') || str_contains($token, '/')) {
            return false;
        }

        return filter_var($token, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function ipsEqual(string $leftIp, string $rightIp): bool
    {
        $leftPacked = @inet_pton($leftIp);
        $rightPacked = @inet_pton($rightIp);

        if ($leftPacked === false || $rightPacked === false) {
            return false;
        }

        return hash_equals($leftPacked, $rightPacked);
    }

    private function normalizeIp(string $ip): string
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return $ip;
        }

        $normalizedIp = @inet_ntop($packedIp);

        return $normalizedIp ?: $ip;
    }
}
