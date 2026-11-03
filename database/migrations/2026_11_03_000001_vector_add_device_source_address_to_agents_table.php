<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Outbound source address the agent binds for device connections (RCO-2020).
            $table->string('device_source_address', 45)->nullable();
            // Last status the agent reported for that address: ok, missing, invalid or unset.
            $table->string('reported_source_address_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['device_source_address', 'reported_source_address_status']);
        });
    }
};
