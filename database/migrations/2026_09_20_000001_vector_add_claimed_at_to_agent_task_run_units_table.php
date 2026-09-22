<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records when the agent actually claims a unit (pulls it via get_unprocessed_jobs),
     * as distinct from started_at, which is stamped at enqueue. The per-unit timeout is
     * measured from claimed_at when present so agent poll latency is not charged against
     * the execution budget (RCO-1250 item 2).
     */
    public function up(): void
    {
        if (! Schema::hasTable('agent_task_run_units')) {
            return;
        }

        Schema::table('agent_task_run_units', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_task_run_units', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_task_run_units')) {
            return;
        }

        Schema::table('agent_task_run_units', function (Blueprint $table) {
            if (Schema::hasColumn('agent_task_run_units', 'claimed_at')) {
                $table->dropColumn('claimed_at');
            }
        });
    }
};
