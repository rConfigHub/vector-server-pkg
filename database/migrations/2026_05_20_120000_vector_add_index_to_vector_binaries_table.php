<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vector_binaries', function (Blueprint $table) {
            $table->index(['platform', 'created_at'], 'vector_binaries_platform_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vector_binaries', function (Blueprint $table) {
            $table->dropIndex('vector_binaries_platform_created_at_idx');
        });
    }
};
