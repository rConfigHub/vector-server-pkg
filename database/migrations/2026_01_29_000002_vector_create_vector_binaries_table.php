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
        Schema::create('vector_binaries', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('version');
            $table->string('sha256');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_binaries');
    }
};
