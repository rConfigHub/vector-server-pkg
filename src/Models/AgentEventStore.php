<?php

namespace  Rconfig\VectorServer\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentEventStore extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
    ];
}
