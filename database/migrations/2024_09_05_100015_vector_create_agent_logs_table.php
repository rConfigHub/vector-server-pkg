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
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->dateTime('executed_at'); // more specific than `synced_at`
            $table->string('log_level')->nullable(); // for standard log levels
            $table->longText('message')->nullable();
            $table->string('operation')->nullable(); // e.g., config_retrieval
            $table->json('context_data')->nullable(); // to store metadata as JSON
            $table->string('entity_type')->nullable(); // e.g., class name
            $table->unsignedBigInteger('entity_id')->nullable(); // optional related entity ID
            $table->string('correlation_id')->nullable(); // trace logs for a single API request
            $table->timestamps();

            // Add indexes for faster querying
            $table->index(['agent_id', 'executed_at', 'log_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};
