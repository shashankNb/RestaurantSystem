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
        // Each order keeps the processor it was placed with: its refund goes back the same
        // way even after the restaurant switches. Orders before Square were all Stripe.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_processor', 10)->default('stripe')->after('payment_status');
            $table->string('square_payment_id')->nullable()->unique()->after('stripe_refund_id');
            $table->string('square_refund_id')->nullable()->after('square_payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['square_payment_id']);
            $table->dropColumn(['payment_processor', 'square_payment_id', 'square_refund_id']);
        });
    }
};
