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
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('dine_in_enabled')->default(false)->after('delivery_enabled');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('dining_table_id')->nullable()->after('delivery_instructions')->constrained()->nullOnDelete();
            // The table's label when the order was placed, kept if the table is renamed or removed.
            $table->string('table_label', 20)->nullable()->after('dining_table_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropColumn('table_label');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('dine_in_enabled');
        });
    }
};
