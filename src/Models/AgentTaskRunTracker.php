<?php

namespace Rconfig\VectorServer\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTaskRunTracker extends Model
{
    public const STATUS_RUNNING = 0;
    public const STATUS_COMPLETED = 1;
    public const STATUS_COMPLETED_WITH_FAILURES = 2;
    public const STATUS_PARTIAL_TIMEOUT = 3;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];
}
