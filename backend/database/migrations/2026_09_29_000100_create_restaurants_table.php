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
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('description', 500)->nullable();
            $table->string('timezone', 64)->default('Australia/Melbourne');
            $table->char('currency', 3)->default('AUD');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            // {line1, line2, suburb, state, postcode, country}: structured for JSON-LD.
            $table->json('address')->nullable();
            $table->string('abn', 20)->nullable();
            $table->string('logo')->nullable();
            $table->string('cover_image')->nullable();
            $table->char('brand_color', 7)->default('#7A1F2B');
            $table->boolean('is_accepting_orders')->default(true);
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('delivery_enabled')->default(true);
            $table->unsignedSmallInteger('default_prep_minutes')->default(20);
            $table->unsignedSmallInteger('auto_reject_minutes')->default(10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
