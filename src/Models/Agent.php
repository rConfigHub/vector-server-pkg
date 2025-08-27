<?php

namespace Rconfig\VectorServer\Models;

use App\Jobs\PublishToRabbitMQJob;
use App\Models\Device;
use App\Models\Role;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Rconfig\VectorServer\Models\User;
use Rconfig\VectorServer\Traits\PublishesToRabbitMQ;

class Agent extends Model
{
    use HasFactory;
    // use PublishesToRabbitMQ,; // disabled for now until we have a use case

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

    public function devicesLimited()
    {
        return $this->hasMany(Device::class)->select('id', 'device_name', 'device_ip',  'agent_id'); // view_url comes back automatically
    }

    /**
     * Status constants for better readability
     */
    const STATUS_HEALTHY = 1;
    const STATUS_DOWN = 2;
    const STATUS_WARNING = 3; // Optional: for future use
    const STATUS_DISABLED = 4;
    const ADMIN_ENABLED = 1;
    const ADMIN_DISABLED = 0;

    /**
     * Check if agent is currently healthy
     */
    public function isHealthy()
    {
        return $this->status == self::STATUS_HEALTHY;
    }

    /**
     * Check if agent is currently down
     */
    public function isDown()
    {
        return $this->status == self::STATUS_DOWN;
    }

    /**
     * Check if agent is overdue for check-in
     */
    public function isOverdue()
    {
        return $this->next_scheduled_checkin_at < now();
    }

    /**
     * Check if agent should be marked as down
     */
    public function shouldBeMarkedDown()
    {
        return $this->missed_checkins >= $this->max_missed_checkins;
    }

    public function isAdminEnabled()
    {
        return $this->is_admin_enabled == self::ADMIN_ENABLED;
    }

    public function isAdminDisabled()
    {
        return $this->is_admin_enabled == self::ADMIN_DISABLED;
    }

    /**
     * Mark agent as down with logging
     */
    public function markAsDown($reason = 'Missed check-ins exceeded')
    {
        if ($this->status != self::STATUS_DOWN) {
            $this->status = self::STATUS_DOWN;
            $this->save();

            // Log the status change
            AgentLog::create([
                'agent_id' => $this->id,
                'executed_at' => now(),
                'log_level' => 'WARN',
                'message' => "Agent {$this->name} marked as DOWN: {$reason}",
                'operation' => 'status_change',
                'context_data' => json_encode([
                    'old_status' => 'healthy',
                    'new_status' => 'down',
                    'reason' => $reason
                ]),
                'entity_type' => 'Agent',
                'entity_id' => $this->id,
            ]);

            return true; // Status was changed
        }

        return false; // Status was already down
    }

    /**
     * Mark agent as healthy with logging
     */
    public function markAsHealthy($reason = 'Check-in received')
    {
        $wasDown = $this->isDown();

        if ($this->status != self::STATUS_HEALTHY) {
            $this->status = self::STATUS_HEALTHY;
            $this->missed_checkins = 0;
            $this->save();

            // Log the recovery
            AgentLog::create([
                'agent_id' => $this->id,
                'executed_at' => now(),
                'log_level' => $wasDown ? 'INFO' : 'DEBUG',
                'message' => "Agent {$this->name} marked as HEALTHY: {$reason}",
                'operation' => 'status_change',
                'context_data' => json_encode([
                    'old_status' => $wasDown ? 'down' : 'unknown',
                    'new_status' => 'healthy',
                    'reason' => $reason
                ]),
                'entity_type' => 'Agent',
                'entity_id' => $this->id,
            ]);

            return $wasDown; // Return true if this was a recovery
        }

        return false; // Status was already healthy
    }

    /**
     * Update next scheduled check-in time
     */
    public function scheduleNextCheckIn($useRetryInterval = false)
    {
        $interval = $useRetryInterval ? $this->retry_interval : $this->checkin_interval;
        $this->next_scheduled_checkin_at = now()->addSeconds($interval);
        $this->save();
    }

    /**
     * Scope for agents that are enabled and not the default agent
     */
    public function scopeActiveAgents($query)
    {
        return $query->where('is_admin_enabled', 1)->where('id', '>', 1);
    }

    /**
     * Scope for agents that are overdue for check-in
     */
    public function scopeOverdue($query)
    {
        return $query->where('next_scheduled_checkin_at', '<', now());
    }
}
