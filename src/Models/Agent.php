<?php

namespace Rconfig\VectorServer\Models;

use App\Models\Device;
use App\Models\Role;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Rconfig\VectorServer\Models\User;

class Agent extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'agent_debug' => 'boolean',
    ];

    protected static function newFactory()
    {
        return AgentFactory::new();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'agent_roles', 'agent_id', 'role_id');
    }

    // Filter results based on user's role
    public function scopeFilterByRole($query, $roleId)
    {
        // default closed, snippets MUST have a role to be returned
        return $query->whereHas('roles', function ($q) use ($roleId) {
            $q->where('id', $roleId);
        });
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'agent_roles')->withPivot('role');
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
