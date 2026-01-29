<?php

namespace Rconfig\VectorServer\Models;

use Illuminate\Database\Eloquent\Model;

class VectorBinaryCache extends Model
{
    protected $table = 'vector_binary_cache';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'downloaded_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function binary()
    {
        return $this->belongsTo(VectorBinary::class, 'binary_id');
    }
}
