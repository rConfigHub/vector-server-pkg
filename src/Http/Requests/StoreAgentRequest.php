<?php

namespace Rconfig\VectorServer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Rconfig\VectorServer\DataTransferObjects\StoreAgentDTO;
use Rconfig\VectorServer\Services\AgentAccess\AgentSourceAllowlistService;

class StoreAgentRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check(); // return true if user is logged in
    }

    public function rules()
    {
        if ($this->getMethod() == 'POST') {
            $rules = [
                'name' => 'required|string|max:255',
                'email' => 'nullable|email',
                'srcip' => 'nullable|string|max:2048',
                'status' => 'nullable|integer|in:0,1,2,3,4',
                'agent_debug' => 'nullable|boolean',
                'ssl_verify' => 'nullable|boolean',
                'retry_count' => 'nullable|integer|min:0',
                'retry_interval' => 'nullable|integer|min:0',
                'job_retry_count' => 'nullable|integer|min:0',
                'checkin_interval' => 'nullable|integer|min:0',
                'queue_download_rate' => 'nullable|integer|min:0',
                'log_upload_rate' => 'nullable|integer|min:0',
                'worker_count' => 'nullable|integer|min:1',
                'max_missed_checkins' => 'nullable|integer|min:0',
                'roles' => 'required'
            ];
        }

        if ($this->getMethod() == 'PATCH') {
            $rules = [
                'name' => 'required|string|max:255',
                'email' => 'nullable|email',
                'srcip' => 'nullable|string|max:2048',
                'api_token' => 'required|nullable|uuid',
                'status' => 'nullable|integer|in:0,1,2,3,4',
                'agent_debug' => 'nullable|boolean',
                'ssl_verify' => 'nullable|boolean',
                'retry_count' => 'nullable|integer|min:0',
                'retry_interval' => 'nullable|integer|min:0',
                'job_retry_count' => 'nullable|integer|min:0',
                'checkin_interval' => 'nullable|integer|min:0',
                'queue_download_rate' => 'nullable|integer|min:0',
                'log_upload_rate' => 'nullable|integer|min:0',
                'worker_count' => 'nullable|integer|min:1',
                'max_missed_checkins' => 'nullable|integer|min:0',
                'roles' => 'required'
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function ($validator) {
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

    /**
     * Build and return a DTO.
     */
    public function toDTO(): StoreAgentDTO
    {
        return new StoreAgentDTO([
            'name' => $this->name,
            'email' => $this->email,
            'srcip' => $this->srcip,
            'srcip_allowlist' => $this->input('srcip_allowlist'),
            'api_token' => $this->api_token ?? Str::uuid()->toString(),
            'status' => $this->status ?? 0,
            'agent_debug' => $this->agent_debug ?? 0,
            'ssl_verify' => $this->ssl_verify ?? true,
            'retry_count' => $this->retry_count ?? 3,
            'retry_interval' => $this->retry_interval ?? 10,
            'job_retry_count' => $this->job_retry_count ?? 1,
            'checkin_interval' => $this->checkin_interval ?? 300,
            'queue_download_rate' => $this->queue_download_rate ?? 300,
            'log_upload_rate' => $this->log_upload_rate ?? 300,
            'worker_count' => $this->worker_count ?? 5,
            'max_missed_checkins' => $this->max_missed_checkins ?? 3
        ]);
    }
}
