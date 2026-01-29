<?php

namespace Rconfig\VectorServer\Services;

use Illuminate\Support\Str;
use Rconfig\VectorServer\Models\VectorAgentBootstrapToken;

class BootstrapTokenService
{
    public function generate(int $agentId, int $ttlMinutes): string
    {
        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);

        VectorAgentBootstrapToken::create([
            'agent_id' => $agentId,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'created_at' => now(),
        ]);

        return $rawToken;
    }

    public function validate(string $rawToken): ?VectorAgentBootstrapToken
    {
        if (trim($rawToken) === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        return VectorAgentBootstrapToken::with('agent')
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function burn(VectorAgentBootstrapToken $token): VectorAgentBootstrapToken
    {
        $token->used_at = now();
        $token->save();

        return $token;
    }
}
