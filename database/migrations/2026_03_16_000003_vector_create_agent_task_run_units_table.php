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
        Schema::create('agent_task_run_units', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->index();
            $table->string('report_id')->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('device_id')->index();
            $table->unsignedBigInteger('agent_id')->nullable()->index();
            $table->string('command')->nullable();
            $table->string('queue_ulid')->nullable()->unique();
            $table->tinyInteger('status')->default(0)->index();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_task_run_units');
    }
};
