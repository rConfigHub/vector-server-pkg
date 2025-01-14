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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('srcip')->nullable();
            $table->uuid('api_token')->nullable();
            $table->integer('status')->default(0); // 0: inactive, 1: active, 2: blocked, 3: deleted, 4: pending
            $table->integer('agent_debug')->default(0); // 0: off, 1: on
            $table->integer('retry_count')->default(3); // Number of retries
            $table->integer('retry_interval')->default(10); // In seconds, i.e., the agent should retry every X seconds
            $table->integer('job_retry_count')->default(1); // Number of retries for jobs for this agent
            $table->integer('checkin_interval')->default(300); // In seconds, i.e., the agent should call in every X seconds
            $table->integer('queue_download_rate')->default(300); // In seconds, i.e., the agent should download the queue every X seconds
            $table->integer('log_upload_rate')->default(300); // Maximum allowed missed check-ins before triggering an alert
            $table->integer('worker_count')->default(5); // Maximum allowed missed check-ins before triggering an alert
            $table->integer('missed_checkins')->default(0); // Count of missed check-ins
            $table->integer('max_missed_checkins')->default(3); // Maximum allowed missed check-ins before triggering an alert
            $table->timestamp('next_scheduled_checkin_at')->nullable(); // Next scheduled check-in time
            $table->timestamp('last_check_in_at')->nullable();
            $table->timestamps();
        });

        DB::table('agents')->insert([
            'id' => 1,
            'name' => 'Vector Server',
            'email' => 'admin',
            'srcip' => '127.0.0.1',
            'api_token' => null,
            'status' => 1,
            'agent_debug' => 0,
            'retry_count' => 3,
            'retry_interval' => 10,
            'checkin_interval' => 300,
            'queue_download_rate' => 300,
            'log_upload_rate' => 300,
            'missed_checkins' => 0,
            'max_missed_checkins' => 5,
            'next_scheduled_checkin_at' => null,
            'last_check_in_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
