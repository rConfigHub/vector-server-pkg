<?php

namespace Rconfig\VectorServer\Models;

use Illuminate\Database\Eloquent\Model;

class VectorAgentBootstrapToken extends Model
{
    protected $table = 'vector_agent_bootstrap_tokens';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
