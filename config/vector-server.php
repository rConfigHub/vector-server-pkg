<?php

return [
    'vector_installed' => env('VECTOR_INSTALLED', true),
    'api_url' => env('VECTOR_API_URL', null),
    'api_key' => env('VECTOR_API_KEY', null),
    'agent_source_dns_ttl_seconds' => env('VECTOR_AGENT_SOURCE_DNS_TTL_SECONDS', 300),
];
