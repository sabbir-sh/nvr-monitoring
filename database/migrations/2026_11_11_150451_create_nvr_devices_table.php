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
        Schema::create('nvr_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('ip', 45);
            $table->unsignedSmallInteger('port')->default(80);
            $table->string('site', 120)->nullable();
            $table->string('vendor', 120)->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('status', 16)->default('unknown')->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->unique(['ip', 'port']);
            $table->index(['enabled', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nvr_devices');
    }
};
