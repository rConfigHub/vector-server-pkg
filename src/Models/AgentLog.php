<?php

namespace Rconfig\VectorServer\Models;

use Database\Factories\AgentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentLog extends Model
{
    use HasFactory;

    protected $casts = [
        'context_data' => 'array',
    ];


    protected static function newFactory()
    {
        return AgentLogFactory::new();
    }
}
