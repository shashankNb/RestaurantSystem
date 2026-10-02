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
        // The last check of the restaurant's Stripe account for Apple Pay and Google Pay (see
        // App\Payments\WalletSetup), shown in the back office without asking Stripe each time.
        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('stripe_wallets')->nullable()->after('stripe_webhook_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('stripe_wallets');
        });
    }
};
