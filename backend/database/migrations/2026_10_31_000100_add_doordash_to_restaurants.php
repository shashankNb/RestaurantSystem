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
        // A restaurant can have DoorDash's drivers deliver its delivery orders, booked with its
        // own DoorDash developer account's access key, entered by its owner. The developer and
        // key IDs aren't secret; the signing secret and the webhook's authorization value are
        // encrypted with APP_KEY (see the Restaurant model's casts).
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('doordash_enabled')->default(false)->after('square_wallets');
            // What customers pay: the zone's fee, or DoorDash's price (App\Enums\DoorDashFee).
            $table->string('doordash_fee', 10)->default('zone')->after('doordash_enabled');
            $table->string('doordash_developer_id')->nullable()->after('doordash_fee');
            $table->string('doordash_key_id')->nullable()->after('doordash_developer_id');
            $table->text('doordash_signing_secret')->nullable()->after('doordash_key_id');
            $table->text('doordash_webhook_secret')->nullable()->after('doordash_signing_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'doordash_enabled',
                'doordash_fee',
                'doordash_developer_id',
                'doordash_key_id',
                'doordash_signing_secret',
                'doordash_webhook_secret',
            ]);
        });
    }
};
