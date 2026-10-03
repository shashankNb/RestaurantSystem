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
        // Events from the platform's Square application, for every restaurant connected to it.
        Schema::create('square_events', function (Blueprint $table) {
            $table->id();
            // Sandbox or production: each has its own webhook subscription.
            $table->string('environment', 10);
            // Unique so a re-delivered event is stored, and processed, once.
            $table->string('event_id');
            $table->string('merchant_id')->nullable();
            $table->string('type', 100);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['environment', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('square_events');
    }
};
