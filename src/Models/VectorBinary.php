<?php

namespace Rconfig\VectorServer\Models;

use Illuminate\Database\Eloquent\Model;

class VectorBinary extends Model
{
    protected $table = 'vector_binaries';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'is_active' => 'boolean',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function caches()
    {
        return $this->hasMany(VectorBinaryCache::class, 'binary_id');
    }
}
