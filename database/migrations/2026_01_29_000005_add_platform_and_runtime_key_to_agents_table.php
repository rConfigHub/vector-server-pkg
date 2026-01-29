<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('platform', 64)->nullable()->after('reported_platform');
            $table->uuid('runtime_key')->nullable()->after('platform');
            $table->timestamp('runtime_key_rotated_at')->nullable()->after('runtime_key');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['platform', 'runtime_key', 'runtime_key_rotated_at']);
        });
    }
};
