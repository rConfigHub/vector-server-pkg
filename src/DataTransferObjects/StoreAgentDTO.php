<?php

declare(strict_types=1);

namespace Rconfig\VectorServer\DataTransferObjects;

use App\DataTransferObjects\DtoBase;

final class StoreAgentDTO extends DtoBase
{
    public string $name;
    public ?string $email;
    public ?string $srcip;
    public ?string $api_token;
    public int $status;
    public bool|int $agent_debug;
    public bool $ssl_verify;
    public int $retry_count;
    public int $retry_interval;
    public int $job_retry_count;
    public int $checkin_interval;
    public int $queue_download_rate;
    public int $log_upload_rate;
    public int $worker_count;
    public ?int $max_missed_checkins;

    public function __construct(array $parameters = [])
    {
        $this->name = $parameters['name'];
        $this->email = $parameters['email'] ?? null;
        $this->srcip = $parameters['srcip'] ?? null;
        $this->api_token = $parameters['api_token'] ?? null;
        $this->status = $parameters['status'] ?? 0;
        $this->agent_debug = $parameters['agent_debug'] ?? 0;
        $this->ssl_verify = $parameters['ssl_verify'] ?? true;
        $this->retry_count = $parameters['retry_count'] ?? 3;
        $this->retry_interval = $parameters['retry_interval'] ?? 10;
        $this->job_retry_count = $parameters['job_retry_count'] ?? 1;
        $this->checkin_interval = $parameters['checkin_interval'] ?? 300;
        $this->queue_download_rate = $parameters['queue_download_rate'] ?? 300;
        $this->log_upload_rate = $parameters['log_upload_rate'] ?? 300;
        $this->worker_count = $parameters['worker_count'] ?? 5;
        $this->max_missed_checkins = $parameters['max_missed_checkins'] ?? null;
    }
}
