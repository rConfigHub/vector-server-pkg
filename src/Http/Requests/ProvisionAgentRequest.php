<?php

namespace Rconfig\VectorServer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Rconfig\VectorServer\Services\AgentAccess\AgentSourceAllowlistService;

class ProvisionAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        ];
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
