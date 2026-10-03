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
        // A restaurant takes payments with its own Stripe account or its own Square account,
        // connected to the platform's Square application ("Connect with Square"). Its tokens
        // are encrypted with APP_KEY (see the Restaurant model's casts).
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('payment_processor', 10)->default('stripe')->after('custom_domain');
            $table->string('square_environment', 10)->nullable()->after('stripe_wallets');
            $table->string('square_merchant_id')->nullable()->index()->after('square_environment');
            $table->string('square_merchant_name')->nullable()->after('square_merchant_id');
            $table->text('square_access_token')->nullable()->after('square_merchant_name');
            $table->text('square_refresh_token')->nullable()->after('square_access_token');
            $table->timestamp('square_token_expires_at')->nullable()->after('square_refresh_token');
            $table->string('square_location_id')->nullable()->after('square_token_expires_at');
            // The account's locations when last asked, for the back office's choice.
            $table->json('square_locations')->nullable()->after('square_location_id');
            // The last Apple Pay domain registration with Square (see App\Payments\WalletSetup).
            $table->json('square_wallets')->nullable()->after('square_locations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['square_merchant_id']);
            $table->dropColumn([
                'payment_processor',
                'square_environment',
                'square_merchant_id',
                'square_merchant_name',
                'square_access_token',
                'square_refresh_token',
                'square_token_expires_at',
                'square_location_id',
                'square_locations',
                'square_wallets',
            ]);
        });
    }
};
