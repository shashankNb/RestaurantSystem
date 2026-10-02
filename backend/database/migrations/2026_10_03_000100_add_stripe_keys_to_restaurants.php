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
        // Each restaurant is paid into its own Stripe account. The secret key and the webhook
        // signing secret are encrypted with APP_KEY (see the Restaurant model's casts).
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('stripe_publishable_key')->nullable()->after('custom_domain');
            $table->text('stripe_secret_key')->nullable()->after('stripe_publishable_key');
            $table->text('stripe_webhook_secret')->nullable()->after('stripe_secret_key');
        });

        // Events now arrive at each restaurant's own endpoint. Restaurants sharing a Stripe
        // account each receive the same event, so an event is unique per restaurant.
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['stripe_event_id']);
            $table->unique(['restaurant_id', 'stripe_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->dropUnique(['restaurant_id', 'stripe_event_id']);
            $table->dropConstrainedForeignId('restaurant_id');
            $table->unique('stripe_event_id');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret']);
        });
    }
};
