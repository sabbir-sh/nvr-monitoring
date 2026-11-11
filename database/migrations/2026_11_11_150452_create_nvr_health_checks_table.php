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
        Schema::create('nvr_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nvr_device_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->index();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();
            $table->index(['nvr_device_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nvr_health_checks');
    }
};
