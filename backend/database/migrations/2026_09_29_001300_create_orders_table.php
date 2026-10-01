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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Orders are financial records: a restaurant that has orders can't be deleted.
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->ulid('public_id')->unique();
            // Daily kitchen number (shown as 042), assigned when payment succeeds so abandoned
            // checkouts don't leave gaps. business_date is the restaurant-local trading day.
            $table->date('business_date')->nullable();
            $table->unsignedSmallInteger('order_number')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending_payment');
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('fulfilment_type', 10);
            // Null means ASAP.
            $table->timestamp('scheduled_for')->nullable();

            // Customer and delivery snapshot. Nullable so account deletion can anonymise them.
            $table->string('customer_name');
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_line1')->nullable();
            $table->string('delivery_line2')->nullable();
            $table->string('delivery_suburb', 100)->nullable();
            $table->string('delivery_state', 40)->nullable();
            $table->string('delivery_postcode', 10)->nullable();
            $table->text('delivery_instructions')->nullable();

            // Server-calculated totals, all GST-inclusive cents.
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('gst_cents');
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promo_code', 40)->nullable();

            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();

            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_refund_id')->nullable();
            $table->string('idempotency_key', 100)->unique();
            // SHA-256 of the normalised request, so a reused key with a different cart is refused.
            $table->char('request_fingerprint', 64)->nullable();
            $table->string('tracking_token', 64);
            $table->string('push_token')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status', 'created_at']);
            // Serves the scheduled jobs that sweep every restaurant by status and age.
            $table->index(['status', 'created_at']);
            $table->unique(['restaurant_id', 'business_date', 'order_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
