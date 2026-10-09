<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCO-1155: idle timeout (minutes) after which an interactive `vector:ssh`
 * session auto-closes. Configurable from the Hub UI; 0 disables the timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || Schema::hasColumn('settings', 'vector_ssh_idle_timeout')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->integer('vector_ssh_idle_timeout')->nullable()->default(5)
                ->comment('Idle minutes before a vector:ssh session auto-closes; 0 = never');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'vector_ssh_idle_timeout')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('vector_ssh_idle_timeout');
            });
        }
    }
};
