<?php

namespace Rconfig\VectorServer\Services\AgentAccess;

use Illuminate\Support\Facades\Cache;

class AgentDnsResolverService
{
    /**
     * @return array{ok: bool, hostname: string, ips: array<int, string>}
     */
    public function resolve(string $hostname): array
    {
        $normalizedHostname = strtolower(trim($hostname));
        $ttlSeconds = max((int) config('vector-server.agent_source_dns_ttl_seconds', 300), 1);
        $cacheKey = 'vector-server:agent-dns:' . sha1($normalizedHostname);

        $cached = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($normalizedHostname) {
            $ips = [];

            $records = @dns_get_record($normalizedHostname, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (! empty($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                        $ips[] = $record['ip'];
                    }
                    if (! empty($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }

            // Fallback to hosts resolver if dns extension did not return anything useful.
            if (empty($ips)) {
                $hostIps = @gethostbynamel($normalizedHostname);
                if (is_array($hostIps)) {
                    foreach ($hostIps as $hostIp) {
                        if (filter_var($hostIp, FILTER_VALIDATE_IP)) {
                            $ips[] = $hostIp;
                        }
                    }
                }
            }

            $ips = array_values(array_unique($ips));

            return [
                'hostname' => $normalizedHostname,
                'ips' => $ips,
                'ok' => ! empty($ips),
            ];
        });

        return [
            'ok' => (bool) ($cached['ok'] ?? false),
            'hostname' => $normalizedHostname,
            'ips' => array_values($cached['ips'] ?? []),
        ];
    }
}
