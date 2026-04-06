<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('agents', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->jsonb('srcip_allowlist')->nullable();
            } else {
                $table->json('srcip_allowlist')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('srcip_allowlist');
        });
    }
};
