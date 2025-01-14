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
        Schema::create('agent_queues', function (Blueprint $table) {
            $table->id();
            $table->string('ulid')->unique();
            $table->integer('agent_id');
            $table->integer('device_id');
            $table->string('ip_address');
            $table->json('connection_params');
            // connection params has
            // protocol
            // username
            // password
            // enable_password
            // command
            // retry_count
            $table->boolean('processed')->default(false);
            $table->integer('retry_attempt')->default(3);
            $table->integer('retry_failed')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_queues');
    }
};
