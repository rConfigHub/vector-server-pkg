<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Desired state, toggled from the UI (RCO-744 phase 2).
            $table->boolean('live_channel_enabled')->default(false);
            // Observed state, persisted from the hub's /agents report.
            $table->boolean('live_channel_connected')->default(false);
            $table->decimal('live_channel_rtt_ms', 8, 3)->nullable();
            $table->timestamp('live_channel_last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'live_channel_enabled',
                'live_channel_connected',
                'live_channel_rtt_ms',
                'live_channel_last_seen_at',
            ]);
        });
    }
};
