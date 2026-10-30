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
        // A delivery order keeps the courier it was placed with (null: the restaurant's own
        // drivers), and its current booking with that courier: DoorDash's delivery, by the ID
        // the platform gave it, and where it's up to. A new booking (after DoorDash calls one
        // off, say) replaces these; courier_attempts numbers them.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier', 20)->nullable()->after('delivery_instructions');
            $table->string('courier_delivery_id', 100)->nullable()->unique()->after('courier');
            $table->unsignedTinyInteger('courier_attempts')->default(0)->after('courier_delivery_id');
            $table->string('courier_status', 20)->nullable()->after('courier_attempts');
            $table->string('courier_driver_name')->nullable()->after('courier_status');
            $table->string('courier_tracking_url', 500)->nullable()->after('courier_driver_name');
            $table->string('courier_support_reference', 100)->nullable()->after('courier_tracking_url');
            // What DoorDash charges the restaurant for the booking.
            $table->unsignedInteger('courier_fee_cents')->nullable()->after('courier_support_reference');
            // Why there's no driver on the way, for the kitchen.
            $table->string('courier_problem', 500)->nullable()->after('courier_fee_cents');
            $table->timestamp('courier_updated_at')->nullable()->after('courier_problem');

            // The every-minute check of deliveries DoorDash is still making.
            $table->index(['courier', 'courier_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['courier', 'courier_status']);
            $table->dropUnique(['courier_delivery_id']);
            $table->dropColumn([
                'courier',
                'courier_delivery_id',
                'courier_attempts',
                'courier_status',
                'courier_driver_name',
                'courier_tracking_url',
                'courier_support_reference',
                'courier_fee_cents',
                'courier_problem',
                'courier_updated_at',
            ]);
        });
    }
};
