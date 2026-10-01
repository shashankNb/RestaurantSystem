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
        // Platform-level: events come from the platform's Stripe account.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            // Unique so a replayed or re-delivered webhook is stored, and processed, once.
            $table->string('stripe_event_id')->unique();
            $table->string('type', 100);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
