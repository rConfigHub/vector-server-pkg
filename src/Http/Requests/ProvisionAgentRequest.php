<?php

namespace Rconfig\VectorServer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Rules\DeviceSourceAddress;
use Rconfig\VectorServer\Services\AgentAccess\AgentSourceAllowlistService;

class ProvisionAgentRequest extends FormRequest
{
    /**
     * The token owner needs agent.update to re-issue a token for an existing agent, otherwise agent.create (RCO-2024).
     * The token's agents.write ability is checked by the route middleware.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $name = $this->input('name');
        $agentExists = is_string($name) && $name !== '' && Agent::where('name', $name)->exists();

        return $user->can($agentExists ? 'agent.update' : 'agent.create');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'srcip' => 'nullable|string|max:2048',
            'srcip_from_request' => 'nullable|boolean',
            'checkin_interval' => 'nullable|integer|min:0',
            'max_missed_checkins' => 'nullable|integer|min:0',
            'worker_count' => 'nullable|integer|min:1',
            'roles' => 'nullable|array',
            'roles.*' => 'integer|min:1',
            'bootstrap_ttl_minutes' => 'nullable|integer|min:1|max:1440',
            'device_source_address' => ['nullable', new DeviceSourceAddress],
        ];
    }

    /**
     * Treat a blank outbound source address as unset (RCO-2020).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('device_source_address')) {
            $address = trim((string) $this->input('device_source_address'));
            $this->merge(['device_source_address' => $address === '' ? null : $address]);
        }
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->input('srcip') === null || $this->input('srcip') === '') {
                    return;
                }

                $allowlist = app(AgentSourceAllowlistService::class)->normalizeRawInput($this->input('srcip'));

                if (! empty($allowlist['errors'])) {
                    foreach ($allowlist['errors'] as $error) {
                        $validator->errors()->add('srcip', $error);
                    }

                    return;
                }

                $this->merge([
                    'srcip' => $allowlist['normalized_input'],
                    'srcip_allowlist' => $allowlist['entries'],
                ]);
            },
        ];
    }
}
