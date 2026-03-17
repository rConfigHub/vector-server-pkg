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
        Schema::table('agent_queues', function (Blueprint $table) {
            $table->string('task_report_id')->nullable()->index();
            $table->string('task_run_id')->nullable()->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_queues', function (Blueprint $table) {
            $table->dropColumn(['task_report_id', 'task_run_id', 'task_id']);
        });
    }
};
