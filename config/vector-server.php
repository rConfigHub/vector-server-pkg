<?php

return [
    'vector_installed' => env('VECTOR_INSTALLED', true),
    'api_url' => env('VECTOR_API_URL', null),
    'api_key' => env('VECTOR_API_KEY', null),
    'agent_source_dns_ttl_seconds' => env('VECTOR_AGENT_SOURCE_DNS_TTL_SECONDS', 300),
    // Must exceed the longest possible legitimate job runtime (template timeout * retry_count).
    // Default covers the built-in 30s timeout × 3 retries with headroom.
    // Increase if templates use high timeouts (e.g. 120s × 3 retries ≈ 6 min → set to 15+).
    'stale_job_timeout_minutes' => env('VECTOR_STALE_JOB_TIMEOUT_MINUTES', 10),

    // Per-device timeout for the agent-run watchdog (TaskDownloadCmd::waitForAgentRunCompletion).
    'task_unit_timeout_seconds' => env('VECTOR_TASK_UNIT_TIMEOUT_SECONDS', 300),

    // Overall cap the watchdog waits for a run before force-timing-out any remaining
    // pending units. Should be >= task_unit_timeout_seconds.
    'task_run_wait_timeout_seconds' => env('VECTOR_TASK_RUN_WAIT_TIMEOUT_SECONDS', 900),
];
