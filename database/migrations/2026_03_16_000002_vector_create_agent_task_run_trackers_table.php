<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_task_run_trackers', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->string('report_id')->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedInteger('expected_total')->default(0);
            $table->unsignedInteger('pending_total')->default(0);
            $table->unsignedInteger('success_total')->default(0);
            $table->unsignedInteger('failed_total')->default(0);
            $table->unsignedInteger('timeout_total')->default(0);
            $table->unsignedInteger('skipped_total')->default(0);
            $table->tinyInteger('status')->default(0)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_task_run_trackers');
    }
};
