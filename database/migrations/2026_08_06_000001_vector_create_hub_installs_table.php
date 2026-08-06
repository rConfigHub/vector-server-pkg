<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vector_hub_installs', function (Blueprint $table) {
            $table->id();
            // queued | running | success | failed
            $table->string('status')->default('queued');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('service_status')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vector_hub_installs');
    }
};
