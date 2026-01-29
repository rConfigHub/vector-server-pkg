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
        Schema::create('vector_binary_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('binary_id')->constrained('vector_binaries')->cascadeOnDelete();
            $table->string('local_path');
            $table->timestamp('downloaded_at');
            $table->timestamp('verified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_binary_cache');
    }
};
