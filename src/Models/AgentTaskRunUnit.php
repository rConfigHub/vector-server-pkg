<?php

namespace Rconfig\VectorServer\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTaskRunUnit extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = 2;
    public const STATUS_TIMEOUT = 3;
    public const STATUS_SKIPPED = 4;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
