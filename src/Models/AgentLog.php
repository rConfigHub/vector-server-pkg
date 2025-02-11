<?php

namespace Rconfig\VectorServer\Models;

use Database\Factories\AgentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Rconfig\VectorServer\Models\Agent;

class AgentLog extends Model
{
    use HasFactory;

    protected $casts = [
        'context_data' => 'array',
    ];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    protected static function newFactory()
    {
        return AgentLogFactory::new();
    }
}
