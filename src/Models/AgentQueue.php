<?php

namespace  Rconfig\VectorServer\Models;

use Database\Factories\AgentQueueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentQueue extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'connection_params' => 'array',
    ];

    protected static function newFactory()
    {
        return AgentQueueFactory::new();
    }
}
