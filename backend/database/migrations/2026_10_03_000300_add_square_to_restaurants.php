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
        // with the credentials of its own Square application, entered by its owner. The
        // application ID is public (the payment forms start with it); the access token and
        // the webhook signature key are encrypted with APP_KEY (see the Restaurant model's casts).
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('payment_processor', 10)->default('stripe')->after('custom_domain');
            $table->string('square_application_id')->nullable()->after('stripe_wallets');
            $table->text('square_access_token')->nullable()->after('square_application_id');
            $table->text('square_webhook_signature_key')->nullable()->after('square_access_token');
            $table->string('square_merchant_name')->nullable()->after('square_webhook_signature_key');
            $table->string('square_location_id')->nullable()->after('square_merchant_name');
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
            $table->dropColumn([
                'payment_processor',
                'square_application_id',
                'square_access_token',
                'square_webhook_signature_key',
                'square_merchant_name',
                'square_location_id',
                'square_locations',
                'square_wallets',
            ]);
        });
    }
};
